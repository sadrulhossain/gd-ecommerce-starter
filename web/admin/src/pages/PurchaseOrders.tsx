import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog, Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import { useOptions } from '../lib/options'
import type { Listing } from '../types'

interface Line {
  id: number
  variant_id: number
  sku: string
  name: string
  quantity_ordered: number
  quantity_received: number
  unit_cost: Money
  line_total: Money
}

interface PurchaseOrder {
  id: number
  number: string
  supplier_id: number
  supplier?: { name: string } | null
  status: 'draft' | 'ordered' | 'partially_received' | 'received' | 'cancelled'
  expected_at: string | null
  notes: string | null
  subtotal: Money
  tax_total: Money
  grand_total: Money
  lines?: Line[]
}

const STATUS_LABEL: Record<PurchaseOrder['status'], string> = {
  draft: 'Draft',
  ordered: 'Ordered',
  partially_received: 'Partly received',
  received: 'Received',
  cancelled: 'Cancelled',
}

function message(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 422) return Object.values(error.details ?? {})[0]?.[0] ?? fallback

  return fallback
}

/** Purchase orders: draft with lines, then placed (immutable) or cancelled. Receiving goods is the check-in module. */
export function PurchaseOrdersPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState<PurchaseOrder | null>(null)

  const list = useQuery({
    queryKey: ['crud', '/v1/purchase-orders', page, status],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/purchase-orders', { query: { page, status: status || undefined } })) as Listing<PurchaseOrder>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', '/v1/purchase-orders'] })

  const remove = useMutation({
    mutationFn: (order: PurchaseOrder) => api.request('DELETE', `/v1/purchase-orders/${order.id}`),
    onSuccess: () => {
      toast.success('Purchase order deleted')
      setDeleting(null)
      void refresh()
    },
    onError: (error) => {
      setDeleting(null)
      toast.error(message(error, 'Could not delete the purchase order'))
    },
  })

  const meta = list.data?.meta

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">Purchase orders</h1>
        {can('purchase-orders.create') && <IconButton showText variant="primary" icon="plus" label="New purchase order" onClick={() => setCreating(true)} />}
      </header>

      <select
        aria-label="Filter by status"
        className="field max-w-xs"
        value={status}
        onChange={(e) => {
          setStatus(e.target.value)
          setPage(1)
        }}
      >
        <option value="">All statuses</option>
        {Object.entries(STATUS_LABEL).map(([value, label]) => (
          <option key={value} value={value}>
            {label}
          </option>
        ))}
      </select>

      {list.isError ? (
        <p role="alert" className="text-danger">
          Could not load purchase orders.
        </p>
      ) : (
        <DataTable
          columns={[
            { key: 'number', header: 'Number' },
            { key: 'supplier', header: 'Supplier', render: (o) => o.supplier?.name ?? '' },
            { key: 'status', header: 'Status', render: (o) => STATUS_LABEL[o.status] },
            { key: 'grand_total', header: 'Total', render: (o) => formatMoney(o.grand_total) },
            { key: 'expected_at', header: 'Expected' },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(o) => o.id}
          loading={list.isPending}
          actions={(o) => (
            <span className="inline-flex gap-2">
              <IconButton variant="row" icon="eye" label={`Open ${o.number}`} onClick={() => setOpenId(o.id)} />
              {can('purchase-orders.delete') && o.status === 'draft' && <IconButton variant="row-danger" icon="trash" label={`Delete ${o.number}`} onClick={() => setDeleting(o)} />}
            </span>
          )}
        />
      )}

      {meta && meta.last_page > 1 && (
        <nav className="flex items-center justify-end gap-3" aria-label="Pagination">
          <IconButton variant="secondary" icon="chevron-left" label="Previous" disabled={page <= 1} onClick={() => setPage(page - 1)} />
          <span className="text-ink-muted">
            {meta.current_page} / {meta.last_page}
          </span>
          <IconButton variant="secondary" icon="chevron-right" label="Next" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} />
        </nav>
      )}

      <Modal open={creating} title="New purchase order" onClose={() => setCreating(false)}>
        {creating && (
          <NewOrderForm
            onCancel={() => setCreating(false)}
            onCreated={(id) => {
              setCreating(false)
              setOpenId(id)
              void refresh()
            }}
          />
        )}
      </Modal>

      <Modal size="xl" open={openId !== null} title="Purchase order" onClose={() => setOpenId(null)}>
        {openId !== null && <OrderDetail id={openId} onChanged={() => void refresh()} />}
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        title="Delete purchase order"
        message={`Delete draft ${deleting?.number ?? ''}?`}
        confirmLabel="Delete"
        danger
        busy={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onCancel={() => setDeleting(null)}
      />
    </section>
  )
}

function NewOrderForm({ onCancel, onCreated }: { onCancel: () => void; onCreated: (id: number) => void }) {
  const suppliers = useOptions('/v1/suppliers', (s) => String(s.name), { is_active: 'true' })
  const [values, setValues] = useState({ supplier_id: '', expected_at: '', notes: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const create = useMutation({
    mutationFn: async () =>
      (await api.request('POST', '/v1/purchase-orders', {
        body: { supplier_id: values.supplier_id, expected_at: values.expected_at || null, notes: values.notes || null },
      })) as { data: { id: number } },
    onSuccess: (body) => {
      toast.success('Draft created')
      onCreated(body.data.id)
    },
    onError: (error) => {
      setErrors(fieldErrors(error))
      toast.error(message(error, 'Could not create the purchase order'))
    },
  })

  return (
    <form
      className="space-y-3"
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        setErrors({})
        create.mutate()
      }}
    >
      <FormField label="Supplier" name="supplier_id" type="select" required options={suppliers.data} value={values.supplier_id} error={errors.supplier_id} onChange={(v) => setValues({ ...values, supplier_id: String(v) })} />
      <FormField label="Expected on" name="expected_at" type="date" value={values.expected_at} error={errors.expected_at} onChange={(v) => setValues({ ...values, expected_at: String(v) })} />
      <FormField label="Notes" name="notes" type="textarea" value={values.notes} error={errors.notes} onChange={(v) => setValues({ ...values, notes: String(v) })} />
      <p className="text-sm text-ink-muted">The draft opens next, so you can add lines.</p>
      <div className="flex justify-end gap-2 pt-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onCancel} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Create draft" disabled={create.isPending} />
      </div>
    </form>
  )
}

function OrderDetail({ id, onChanged }: { id: number; onChanged: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [confirming, setConfirming] = useState<'order' | 'cancel' | null>(null)
  const key = ['purchase-order', id]

  const order = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/purchase-orders/${id}`)) as { data: PurchaseOrder }).data })
  const reload = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    onChanged()
  }
  const act = useMutation({
    mutationFn: (action: 'order' | 'cancel') => api.request('POST', `/v1/purchase-orders/${id}/${action}`),
    onSuccess: (_data, action) => {
      toast.success(action === 'order' ? 'Order placed' : 'Order cancelled')
      setConfirming(null)
      reload()
    },
    onError: (error) => {
      setConfirming(null)
      toast.error(message(error, 'Could not update the purchase order'))
    },
  })
  const removeLine = useMutation({
    mutationFn: (line: Line) => api.request('DELETE', `/v1/purchase-order-lines/${line.id}`),
    onSuccess: reload,
    onError: (error) => toast.error(message(error, 'Could not remove the line')),
  })

  const po = order.data
  if (order.isError) return <p role="alert" className="text-danger">Could not load the purchase order.</p>
  if (!po) return <p className="text-ink-muted">Loading...</p>

  const draft = po.status === 'draft'
  const lines = po.lines ?? []

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-xl font-semibold">{po.number}</h3>
        <span className="text-ink-muted">
          {po.supplier?.name} - {STATUS_LABEL[po.status]}
          {po.expected_at ? ` - expected ${po.expected_at}` : ''}
        </span>
      </header>
      {po.notes && <p className="text-ink-muted">{po.notes}</p>}

      <DataTable
        columns={[
          { key: 'sku', header: 'SKU' },
          { key: 'name', header: 'Item' },
          { key: 'quantity_ordered', header: 'Ordered' },
          { key: 'quantity_received', header: 'Received' },
          { key: 'unit_cost', header: 'Unit cost', render: (l) => formatMoney(l.unit_cost) },
          { key: 'line_total', header: 'Line total', render: (l) => formatMoney(l.line_total) },
        ]}
        rows={lines}
        rowKey={(l) => l.id}
        empty="No lines yet."
        actions={draft && can('purchase-orders.update') ? (l) => <IconButton variant="row-danger" icon="trash" label={`Remove line ${l.sku}`} onClick={() => removeLine.mutate(l)} /> : undefined}
      />

      <dl className="ml-auto grid max-w-xs grid-cols-2 gap-x-4 text-right">
        <dt className="text-ink-muted">Subtotal</dt>
        <dd>{formatMoney(po.subtotal)}</dd>
        <dt className="text-ink-muted">Tax</dt>
        <dd>{formatMoney(po.tax_total)}</dd>
        <dt className="font-semibold">Total</dt>
        <dd className="font-semibold">{formatMoney(po.grand_total)}</dd>
      </dl>

      {draft && can('purchase-orders.update') && <AddLineForm orderId={id} onAdded={reload} />}

      <div className="flex flex-wrap justify-end gap-2">
        {draft && can('purchase-orders.order') && <IconButton showText variant="primary" icon="check" label="Place order" disabled={lines.length === 0} onClick={() => setConfirming('order')} />}
        {po.status === 'ordered' && can('purchase-orders.cancel') && <IconButton showText variant="danger" icon="x" label="Cancel order" onClick={() => setConfirming('cancel')} />}
      </div>

      <ConfirmDialog
        open={confirming !== null}
        title={confirming === 'order' ? 'Place order' : 'Cancel order'}
        message={confirming === 'order' ? `Place ${po.number}? It cannot be edited afterwards.` : `Cancel ${po.number}? Only an order with nothing received can be cancelled.`}
        confirmLabel={confirming === 'order' ? 'Place order' : 'Cancel order'}
        danger={confirming === 'cancel'}
        busy={act.isPending}
        onConfirm={() => confirming && act.mutate(confirming)}
        onCancel={() => setConfirming(null)}
      />
    </div>
  )
}

interface ProductHit {
  name: string
  variants?: Array<{ id: number; sku: string; name: string | null }>
}

function AddLineForm({ orderId, onAdded }: { orderId: number; onAdded: () => void }) {
  const [search, setSearch] = useState('')
  const [values, setValues] = useState<Record<string, unknown>>({ variant_id: '', quantity_ordered: 1, unit_cost: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const hits = useQuery({
    queryKey: ['variant-search', search],
    enabled: search.trim().length >= 2,
    queryFn: async () => {
      const body = (await api.request('GET', '/v1/products', { query: { q: search.trim() } })) as { data: ProductHit[] }

      return body.data.flatMap((p) => (p.variants ?? []).map((v) => ({ value: v.id, label: `${v.sku} - ${p.name}${v.name ? ` (${v.name})` : ''}` })))
    },
  })

  const add = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { variant_id: values.variant_id, quantity_ordered: values.quantity_ordered }
      if (values.unit_cost !== '' && values.unit_cost !== null) body.unit_cost = values.unit_cost

      return api.request('POST', `/v1/purchase-orders/${orderId}/lines`, { body })
    },
    onSuccess: () => {
      toast.success('Line added')
      setValues({ variant_id: '', quantity_ordered: 1, unit_cost: '' })
      onAdded()
    },
    onError: (error) => {
      setErrors(fieldErrors(error))
      toast.error(message(error, 'Could not add the line'))
    },
  })

  return (
    <form
      className="space-y-3 rounded-lg border border-line p-3"
      aria-label="Add line"
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        setErrors({})
        add.mutate()
      }}
    >
      <FormField label="Find item (SKU or name)" name="line_search" value={search} onChange={(v) => setSearch(String(v))} />
      <FormField label="Item" name="variant_id" type="select" required options={hits.data} value={values.variant_id} error={errors.variant_id} onChange={(v) => setValues({ ...values, variant_id: v })} />
      <FormField label="Quantity" name="quantity_ordered" type="number" required value={values.quantity_ordered} error={errors.quantity_ordered} onChange={(v) => setValues({ ...values, quantity_ordered: v })} />
      <FormField label="Unit cost" name="unit_cost" type="money" hint="Empty uses the item's cost price." value={values.unit_cost} error={errors.unit_cost} onChange={(v) => setValues({ ...values, unit_cost: v })} />
      <div className="flex justify-end">
        <IconButton showText type="submit" variant="secondary" icon="plus" label="Add line" disabled={add.isPending} />
      </div>
    </form>
  )
}
