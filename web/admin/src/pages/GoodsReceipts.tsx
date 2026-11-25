import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog, Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { Pager } from '../components/Pager'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import type { Listing } from '../types'

interface ReceiptLine {
  id: number
  sku: string
  name: string
  quantity: number
  unit_cost: Money
}

interface Receipt {
  id: number
  number: string
  purchase_order_number?: string
  status: 'draft' | 'posted'
  delivery_note: string | null
  notes: string | null
  posted_at: string | null
  lines?: ReceiptLine[]
}

interface OrderLine {
  id: number
  sku: string
  name: string
  quantity_ordered: number
  quantity_received: number
}

interface OpenOrder {
  id: number
  number: string
  supplier?: { name: string } | null
  lines?: OrderLine[]
}

function failure(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 422) return Object.values(error.details ?? {})[0]?.[0] ?? fallback

  return fallback
}

/** Check-in: goods arriving against a placed purchase order. Posting adds the units to stock. */
export function GoodsReceiptsPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)

  const list = useQuery({
    queryKey: ['crud', '/v1/goods-receipts', page, status],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/goods-receipts', { query: { page, status: status || undefined } })) as Listing<Receipt>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', '/v1/goods-receipts'] })

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">Check-in</h1>
        {can('goods-receipts.create') && <IconButton showText variant="primary" icon="plus" label="New receipt" onClick={() => setCreating(true)} />}
      </header>
      <select aria-label="Filter by status" className="field max-w-xs" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="posted">Posted</option>
      </select>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load receipts.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'number', header: 'Receipt' },
            { key: 'purchase_order_number', header: 'Purchase order' },
            { key: 'status', header: 'Status', render: (r) => (r.status === 'draft' ? 'Draft' : 'Posted') },
            { key: 'delivery_note', header: 'Delivery note' },
            { key: 'posted_at', header: 'Posted', render: (r) => (r.posted_at ? new Date(r.posted_at).toLocaleDateString() : '') },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(r) => r.id}
          loading={list.isPending}
          actions={(r) => <IconButton variant="row" icon="eye" label={`Open ${r.number}`} onClick={() => setOpenId(r.id)} />}
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
      <Modal size="xl" open={creating} title="New receipt" onClose={() => setCreating(false)}>
        {creating && <NewReceipt onCancel={() => setCreating(false)} onCreated={(id) => { setCreating(false); setOpenId(id); void refresh() }} />}
      </Modal>
      <Modal size="xl" open={openId !== null} title="Receipt" onClose={() => setOpenId(null)}>
        {openId !== null && <ReceiptDetail id={openId} onChanged={() => void refresh()} onGone={() => { setOpenId(null); void refresh() }} />}
      </Modal>
    </section>
  )
}

function NewReceipt({ onCancel, onCreated }: { onCancel: () => void; onCreated: (id: number) => void }) {
  const [orderId, setOrderId] = useState('')
  const [head, setHead] = useState({ delivery_note: '', notes: '' })
  const [counts, setCounts] = useState<Record<number, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Only orders that can still receive goods.
  const orders = useQuery({
    queryKey: ['receivable-orders'],
    queryFn: async () => {
      const [a, b] = await Promise.all(['ordered', 'partially_received'].map(async (s) => (await api.request('GET', '/v1/purchase-orders', { query: { status: s } })) as Listing<OpenOrder>))

      return [...a.data, ...b.data]
    },
  })
  const order = useQuery({
    queryKey: ['purchase-order', Number(orderId)],
    enabled: orderId !== '',
    queryFn: async () => ((await api.request('GET', `/v1/purchase-orders/${orderId}`)) as { data: OpenOrder }).data,
  })

  const lines = (order.data?.lines ?? []).map((l) => ({ line: l, remaining: l.quantity_ordered - l.quantity_received }))
  const quantityOf = (l: { line: OrderLine; remaining: number }) => counts[l.line.id] ?? String(l.remaining)

  const create = useMutation({
    mutationFn: () =>
      api.request('POST', '/v1/goods-receipts', {
        body: {
          purchase_order_id: Number(orderId),
          delivery_note: head.delivery_note || null,
          notes: head.notes || null,
          lines: lines.filter((l) => Number(quantityOf(l)) > 0).map((l) => ({ purchase_order_line_id: l.line.id, quantity: Number(quantityOf(l)) })),
        },
      }) as Promise<{ data: { id: number } }>,
    onSuccess: (body) => { toast.success('Draft created'); onCreated(body.data.id) },
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(failure(e, 'Could not create the receipt')) },
  })

  return (
    <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); setErrors({}); create.mutate() }}>
      <FormField
        label="Purchase order"
        name="purchase_order_id"
        type="select"
        required
        options={(orders.data ?? []).map((o) => ({ value: o.id, label: `${o.number}${o.supplier ? ` - ${o.supplier.name}` : ''}` }))}
        value={orderId}
        error={errors.purchase_order_id}
        onChange={(v) => { setOrderId(String(v)); setCounts({}) }}
      />
      {orderId !== '' && (
        <fieldset className="space-y-2 rounded-lg border border-line p-3">
          <legend className="px-1 text-sm font-medium">Received now</legend>
          {lines.length === 0 && <p className="text-ink-muted">Loading lines...</p>}
          {lines.map((l) => (
            <FormField
              key={l.line.id}
              label={`${l.line.sku} - ${l.line.name}`}
              name={`line_${l.line.id}`}
              type="number"
              hint={`Ordered ${l.line.quantity_ordered}, received so far ${l.line.quantity_received}.`}
              value={quantityOf(l)}
              onChange={(v) => setCounts({ ...counts, [l.line.id]: v === null ? '' : String(v) })}
            />
          ))}
          {(errors.lines || errors['lines.0.quantity']) && <p role="alert" className="text-danger">{errors.lines ?? errors['lines.0.quantity']}</p>}
        </fieldset>
      )}
      <FormField label="Delivery note" name="delivery_note" value={head.delivery_note} error={errors.delivery_note} onChange={(v) => setHead({ ...head, delivery_note: String(v) })} />
      <FormField label="Notes" name="notes" type="textarea" value={head.notes} error={errors.notes} onChange={(v) => setHead({ ...head, notes: String(v) })} />
      <div className="flex justify-end gap-2 pt-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onCancel} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Create draft" disabled={create.isPending || orderId === ''} />
      </div>
    </form>
  )
}

function ReceiptDetail({ id, onChanged, onGone }: { id: number; onChanged: () => void; onGone: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const key = ['goods-receipt', id]
  const [confirming, setConfirming] = useState<'post' | 'delete' | null>(null)

  const receipt = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/goods-receipts/${id}`)) as { data: Receipt }).data })
  const act = useMutation({
    mutationFn: (action: 'post' | 'delete') => (action === 'post' ? api.request('POST', `/v1/goods-receipts/${id}/post`) : api.request('DELETE', `/v1/goods-receipts/${id}`)),
    onSuccess: (_d, action) => {
      toast.success(action === 'post' ? 'Posted to stock' : 'Draft deleted')
      setConfirming(null)
      if (action === 'delete') return onGone()
      void queryClient.invalidateQueries({ queryKey: key })
      void queryClient.invalidateQueries({ queryKey: ['receivable-orders'] })
      onChanged()
    },
    onError: (e) => { setConfirming(null); toast.error(failure(e, 'Could not complete that')) },
  })

  const r = receipt.data
  if (receipt.isError) return <p role="alert" className="text-danger">Could not load the receipt.</p>
  if (!r) return <p className="text-ink-muted">Loading...</p>

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-xl font-semibold">{r.number}</h3>
        <span className="text-ink-muted">{r.status === 'draft' ? 'Draft' : 'Posted'} - order {r.purchase_order_number}{r.delivery_note ? ` - note ${r.delivery_note}` : ''}</span>
      </header>
      {r.notes && <p className="text-ink-muted">{r.notes}</p>}
      <DataTable
        columns={[
          { key: 'sku', header: 'SKU' },
          { key: 'name', header: 'Item' },
          { key: 'quantity', header: 'Received' },
          { key: 'unit_cost', header: 'Unit cost', render: (l) => formatMoney(l.unit_cost) },
        ]}
        rows={r.lines ?? []}
        rowKey={(l) => l.id}
      />
      {r.status === 'draft' && (
        <div className="flex flex-wrap justify-end gap-2">
          {can('goods-receipts.delete') && <IconButton showText variant="danger" icon="trash" label="Delete draft" onClick={() => setConfirming('delete')} />}
          {can('goods-receipts.post') && <IconButton showText variant="primary" icon="check" label="Post" disabled={(r.lines ?? []).length === 0} onClick={() => setConfirming('post')} />}
        </div>
      )}
      <ConfirmDialog
        open={confirming !== null}
        title={confirming === 'post' ? 'Post receipt' : 'Delete draft'}
        message={confirming === 'post' ? `Post ${r.number}? It adds the units to stock and cannot be edited afterwards.` : `Delete draft ${r.number}?`}
        confirmLabel={confirming === 'post' ? 'Post' : 'Delete'}
        danger={confirming === 'delete'}
        busy={act.isPending}
        onConfirm={() => confirming && act.mutate(confirming)}
        onCancel={() => setConfirming(null)}
      />
    </div>
  )
}
