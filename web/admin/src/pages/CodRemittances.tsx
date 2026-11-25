import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, fromMinor, toMinor, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog, Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { Pager } from '../components/Pager'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import { useOptions } from '../lib/options'
import type { Listing } from '../types'

interface Remittance {
  id: number
  number: string
  courier_id: number
  status: 'draft' | 'posted'
  expected_amount: Money
  received_amount: Money | null
  difference: Money | null
  reference: string | null
  notes: string | null
  deliveries?: Array<{ id: number; order_id: number; tracking_number: string | null; collected_amount: Money | null }>
}

interface CollectableDelivery {
  id: number
  order_number: string
  tracking_number: string | null
  collected_amount: Money | null
}

function failure(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 422) return Object.values(error.details ?? {})[0]?.[0] ?? fallback

  return fallback
}

/** COD remittances: a courier hands over the cash for delivered orders; a draft lists the deliveries, posting records what arrived. */
export function CodRemittancesPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)

  const list = useQuery({
    queryKey: ['crud', '/v1/cod-remittances', page],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/cod-remittances', { query: { page } })) as Listing<Remittance>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', '/v1/cod-remittances'] })
  const couriers = useOptions('/v1/couriers', (c) => String(c.name))
  const courierName = (id: number) => couriers.data?.find((c) => c.value === id)?.label ?? `#${id}`

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">COD remittances</h1>
        {can('cod-remittances.create') && <IconButton showText variant="primary" icon="plus" label="New remittance" onClick={() => setCreating(true)} />}
      </header>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load remittances.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'number', header: 'Number' },
            { key: 'courier_id', header: 'Courier', render: (r) => courierName(r.courier_id) },
            { key: 'status', header: 'Status', render: (r) => (r.status === 'draft' ? 'Draft' : 'Posted') },
            { key: 'expected_amount', header: 'Expected', render: (r) => formatMoney(r.expected_amount) },
            { key: 'received_amount', header: 'Received', render: (r) => formatMoney(r.received_amount) },
            { key: 'difference', header: 'Difference', render: (r) => formatMoney(r.difference) },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(r) => r.id}
          loading={list.isPending}
          actions={(r) => <IconButton variant="row" icon="eye" label={`Open ${r.number}`} onClick={() => setOpenId(r.id)} />}
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
      <Modal size="lg" open={creating} title="New remittance" onClose={() => setCreating(false)}>
        {creating && <NewRemittance couriers={couriers.data ?? []} onCancel={() => setCreating(false)} onCreated={(id) => { setCreating(false); setOpenId(id); void refresh() }} />}
      </Modal>
      <Modal size="lg" open={openId !== null} title="Remittance" onClose={() => setOpenId(null)}>
        {openId !== null && <RemittanceDetail id={openId} onChanged={() => void refresh()} onGone={() => { setOpenId(null); void refresh() }} />}
      </Modal>
    </section>
  )
}

function NewRemittance({ couriers, onCancel, onCreated }: { couriers: Array<{ value: string | number; label: string }>; onCancel: () => void; onCreated: (id: number) => void }) {
  const [courierId, setCourierId] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [head, setHead] = useState({ reference: '', notes: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const deliveries = useQuery({
    queryKey: ['collectable', courierId],
    enabled: courierId !== '',
    queryFn: async () => ((await api.request('GET', '/v1/deliveries', { query: { courier_id: courierId, collectable: 1 } })) as Listing<CollectableDelivery>).data,
  })

  const create = useMutation({
    mutationFn: async () => (await api.request('POST', '/v1/cod-remittances', { body: { courier_id: Number(courierId), delivery_ids: picked, reference: head.reference || null, notes: head.notes || null } })) as { data: { id: number } },
    onSuccess: (body) => { toast.success('Draft created'); onCreated(body.data.id) },
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(failure(e, 'Could not create the remittance')) },
  })

  const rows = deliveries.data ?? []
  const toggle = (id: number) => setPicked(picked.includes(id) ? picked.filter((p) => p !== id) : [...picked, id])

  return (
    <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); setErrors({}); create.mutate() }}>
      <FormField label="Courier" name="courier_id" type="select" required options={couriers} value={courierId} error={errors.courier_id} onChange={(v) => { setCourierId(String(v)); setPicked([]) }} />
      {courierId !== '' && (
        <fieldset className="space-y-2 rounded-lg border border-line p-3">
          <legend className="px-1 text-sm font-medium">Cash collected, not yet remitted</legend>
          {deliveries.isPending && <p className="text-ink-muted">Loading...</p>}
          {!deliveries.isPending && rows.length === 0 && <p className="text-ink-muted">Nothing to remit for this courier.</p>}
          {rows.map((d) => (
            <label key={d.id} className="flex items-center gap-2">
              <input type="checkbox" checked={picked.includes(d.id)} onChange={() => toggle(d.id)} />
              <span>{d.order_number}{d.tracking_number ? ` (${d.tracking_number})` : ''} - {formatMoney(d.collected_amount)}</span>
            </label>
          ))}
          {(errors.delivery_ids || errors['delivery_ids.0']) && <p role="alert" className="text-danger">{errors.delivery_ids ?? errors['delivery_ids.0']}</p>}
        </fieldset>
      )}
      <FormField label="Reference" name="reference" value={head.reference} error={errors.reference} onChange={(v) => setHead({ ...head, reference: String(v) })} />
      <FormField label="Notes" name="notes" type="textarea" value={head.notes} error={errors.notes} onChange={(v) => setHead({ ...head, notes: String(v) })} />
      <div className="flex justify-end gap-2 pt-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onCancel} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Create draft" disabled={create.isPending || picked.length === 0} />
      </div>
    </form>
  )
}

function RemittanceDetail({ id, onChanged, onGone }: { id: number; onChanged: () => void; onGone: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const key = ['cod-remittance', id]
  const [confirming, setConfirming] = useState<'post' | 'delete' | null>(null)
  const [received, setReceived] = useState<number | null>(null)
  const [error, setError] = useState('')

  const remittance = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/cod-remittances/${id}`)) as { data: Remittance }).data })
  const act = useMutation({
    mutationFn: (action: 'post' | 'delete') => (action === 'post' ? api.request('POST', `/v1/cod-remittances/${id}/post`, { body: { received_amount: received } }) : api.request('DELETE', `/v1/cod-remittances/${id}`)),
    onSuccess: (_d, action) => {
      toast.success(action === 'post' ? 'Remittance posted' : 'Draft deleted')
      setConfirming(null)
      if (action === 'delete') return onGone()
      void queryClient.invalidateQueries({ queryKey: key })
      onChanged()
    },
    onError: (e) => { setConfirming(null); toast.error(failure(e, 'Could not complete that')) },
  })

  const r = remittance.data
  if (remittance.isError) return <p role="alert" className="text-danger">Could not load the remittance.</p>
  if (!r) return <p className="text-ink-muted">Loading...</p>

  const tryPost = () => {
    if (received === null) return setError('Enter the cash actually received.')
    setError('')
    setConfirming('post')
  }

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-xl font-semibold">{r.number}</h3>
        <span className="text-ink-muted">{r.status === 'draft' ? 'Draft' : 'Posted'}{r.reference ? ` - ${r.reference}` : ''}</span>
      </header>
      <DataTable
        columns={[
          { key: 'order_id', header: 'Order', render: (d) => `#${d.order_id}` },
          { key: 'tracking_number', header: 'Tracking' },
          { key: 'collected_amount', header: 'Collected', render: (d) => formatMoney(d.collected_amount) },
        ]}
        rows={r.deliveries ?? []}
        rowKey={(d) => d.id}
      />
      <dl className="ml-auto grid max-w-xs grid-cols-2 gap-x-4 text-right">
        <dt className="text-ink-muted">Expected</dt><dd>{formatMoney(r.expected_amount)}</dd>
        {r.status === 'posted' && (
          <>
            <dt className="text-ink-muted">Received</dt><dd>{formatMoney(r.received_amount)}</dd>
            <dt className="font-semibold">Difference</dt><dd className="font-semibold">{formatMoney(r.difference)}</dd>
          </>
        )}
      </dl>
      {r.status === 'draft' && can('cod-remittances.post') && (
        <FormField label="Cash received" name="received_amount" type="money" required hint={`Expected ${fromMinor(r.expected_amount.amount)}. Any difference is recorded.`} value={received ?? ''} error={error} onChange={(v) => setReceived(typeof v === 'number' ? v : toMinor(String(v ?? '')))} />
      )}
      {r.status === 'draft' && (
        <div className="flex flex-wrap justify-end gap-2">
          {can('cod-remittances.delete') && <IconButton showText variant="danger" icon="trash" label="Delete draft" onClick={() => setConfirming('delete')} />}
          {can('cod-remittances.post') && <IconButton showText variant="primary" icon="check" label="Post" onClick={tryPost} />}
        </div>
      )}
      <ConfirmDialog
        open={confirming !== null}
        title={confirming === 'post' ? 'Post remittance' : 'Delete draft'}
        message={confirming === 'post' ? `Post ${r.number} with ${formatMoney({ amount: received ?? 0, currency: r.expected_amount.currency })} received? It cannot be edited afterwards.` : `Delete draft ${r.number}?`}
        confirmLabel={confirming === 'post' ? 'Post' : 'Delete'}
        danger={confirming === 'delete'}
        busy={act.isPending}
        onConfirm={() => confirming && act.mutate(confirming)}
        onCancel={() => setConfirming(null)}
      />
    </div>
  )
}
