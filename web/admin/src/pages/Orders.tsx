import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { Pager } from '../components/Pager'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import { useOptions } from '../lib/options'
import type { Listing } from '../types'

interface Item {
  sku: string
  name: string
  quantity: number
  unit_price: Money
  line_total: Money
}

interface Order {
  id: number
  number: string
  status: string
  payment_method: string
  payment_status: string
  contact_name: string
  contact_phone: string
  contact_email: string | null
  address_line: string
  city: string
  district: string | null
  note: string | null
  cancel_reason: string | null
  subtotal: Money
  shipping_total: Money
  tax_total: Money
  discount_total: Money
  grand_total: Money
  placed_at: string
  delivery?: { courier_id: number; tracking_number: string | null; collected_at: string | null } | null
  items?: Item[]
}

export const ORDER_STATUSES = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled']

/** Next status in the lifecycle, or null when the order is finished. */
export function nextStatus(status: string): string | null {
  const at = ORDER_STATUSES.indexOf(status)

  return at < 0 || status === 'delivered' || status === 'cancelled' ? null : ORDER_STATUSES[at + 1]
}

const label = (s: string) => s.charAt(0).toUpperCase() + s.slice(1).replaceAll('_', ' ')

function failure(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 422) return Object.values(error.details ?? {})[0]?.[0] ?? fallback

  return fallback
}

/** Orders: find, open, move along pending > confirmed > packed > shipped > delivered, cancel, hand to a courier. */
export function OrdersPage() {
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const queryClient = useQueryClient()

  const list = useQuery({
    queryKey: ['crud', '/v1/orders', page, status, q],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/orders', { query: { page, status: status || undefined, q: q || undefined } })) as Listing<Order>,
  })

  return (
    <section className="space-y-4">
      <h1 className="text-2xl font-semibold">Orders</h1>
      <div className="flex flex-wrap gap-2">
        <input type="search" className="field max-w-sm" placeholder="Number, name or phone" aria-label="Search orders" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
        <select aria-label="Filter by status" className="field max-w-xs" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All statuses</option>
          {ORDER_STATUSES.map((s) => (
            <option key={s} value={s}>{label(s)}</option>
          ))}
        </select>
      </div>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load orders.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'number', header: 'Number' },
            { key: 'contact_name', header: 'Customer' },
            { key: 'status', header: 'Status', render: (o) => label(o.status) },
            { key: 'payment_status', header: 'Payment', render: (o) => `${o.payment_method.toUpperCase()} - ${label(o.payment_status)}` },
            { key: 'grand_total', header: 'Total', render: (o) => formatMoney(o.grand_total) },
            { key: 'placed_at', header: 'Placed', render: (o) => new Date(o.placed_at).toLocaleDateString() },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(o) => o.id}
          loading={list.isPending}
          empty="No orders found."
          actions={(o) => <IconButton variant="row" icon="eye" label={`Open ${o.number}`} onClick={() => setOpenId(o.id)} />}
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
      <Modal size="xl" open={openId !== null} title="Order" onClose={() => setOpenId(null)}>
        {openId !== null && <OrderDetail id={openId} onChanged={() => void queryClient.invalidateQueries({ queryKey: ['crud', '/v1/orders'] })} />}
      </Modal>
    </section>
  )
}

function OrderDetail({ id, onChanged }: { id: number; onChanged: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const key = ['order', id]
  const [reason, setReason] = useState<string | null>(null)
  const [reasonError, setReasonError] = useState('')
  const couriers = useOptions('/v1/couriers', (c) => String(c.name), { is_active: 'true' })
  const [courier, setCourier] = useState({ courier_id: '', tracking_number: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const order = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/orders/${id}`)) as { data: Order }).data })
  const reload = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    onChanged()
  }

  const advance = useMutation({
    mutationFn: () => api.request('POST', `/v1/orders/${id}/advance`),
    onSuccess: () => { toast.success('Order moved on'); reload() },
    onError: (e) => toast.error(failure(e, 'Could not move the order on')),
  })
  const cancel = useMutation({
    mutationFn: () => api.request('POST', `/v1/orders/${id}/cancel`, { body: { reason } }),
    onSuccess: () => { toast.success('Order cancelled'); setReason(null); reload() },
    onError: (e) => { setReasonError(failure(e, 'Could not cancel the order')) },
  })
  const assign = useMutation({
    mutationFn: () => api.request('POST', `/v1/orders/${id}/delivery`, { body: { courier_id: courier.courier_id, tracking_number: courier.tracking_number || null } }),
    onSuccess: () => { toast.success('Courier assigned'); reload() },
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(failure(e, 'Could not assign the courier')) },
  })

  const o = order.data
  if (order.isError) return <p role="alert" className="text-danger">Could not load the order.</p>
  if (!o) return <p className="text-ink-muted">Loading...</p>

  const next = nextStatus(o.status)
  const open = o.status !== 'delivered' && o.status !== 'cancelled'

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-xl font-semibold">{o.number}</h3>
        <span className="text-ink-muted">{label(o.status)} - {o.payment_method.toUpperCase()} {label(o.payment_status)}</span>
      </header>
      <p>
        {o.contact_name}, {o.contact_phone}{o.contact_email ? `, ${o.contact_email}` : ''}
        <br />
        {[o.address_line, o.city, o.district].filter(Boolean).join(', ')}
      </p>
      {o.note && <p className="text-ink-muted">Note: {o.note}</p>}
      {o.cancel_reason && <p className="text-danger">Cancelled: {o.cancel_reason}</p>}

      <DataTable
        columns={[
          { key: 'sku', header: 'SKU' },
          { key: 'name', header: 'Item' },
          { key: 'quantity', header: 'Qty' },
          { key: 'unit_price', header: 'Price', render: (i) => formatMoney(i.unit_price) },
          { key: 'line_total', header: 'Total', render: (i) => formatMoney(i.line_total) },
        ]}
        rows={o.items ?? []}
        rowKey={(i) => i.sku}
      />
      <dl className="ml-auto grid max-w-xs grid-cols-2 gap-x-4 text-right">
        <dt className="text-ink-muted">Subtotal</dt><dd>{formatMoney(o.subtotal)}</dd>
        <dt className="text-ink-muted">Discount</dt><dd>{formatMoney(o.discount_total)}</dd>
        <dt className="text-ink-muted">Shipping</dt><dd>{formatMoney(o.shipping_total)}</dd>
        <dt className="text-ink-muted">Tax</dt><dd>{formatMoney(o.tax_total)}</dd>
        <dt className="font-semibold">Total</dt><dd className="font-semibold">{formatMoney(o.grand_total)}</dd>
      </dl>

      {o.delivery ? (
        <p>Courier #{o.delivery.courier_id}{o.delivery.tracking_number ? `, tracking ${o.delivery.tracking_number}` : ''}{o.delivery.collected_at ? ', cash collected' : ''}</p>
      ) : (
        open && can('delivery.manage') && (
          <form className="space-y-3 rounded-lg border border-line p-3" aria-label="Assign courier" noValidate onSubmit={(e) => { e.preventDefault(); setErrors({}); assign.mutate() }}>
            <FormField label="Courier" name="courier_id" type="select" required options={couriers.data} value={courier.courier_id} error={errors.courier_id} onChange={(v) => setCourier({ ...courier, courier_id: String(v) })} />
            <FormField label="Tracking number" name="tracking_number" value={courier.tracking_number} error={errors.tracking_number} onChange={(v) => setCourier({ ...courier, tracking_number: String(v) })} />
            <div className="flex justify-end"><IconButton showText type="submit" variant="secondary" icon="truck" label="Assign courier" disabled={assign.isPending} /></div>
          </form>
        )
      )}

      {reason !== null && (
        <form className="space-y-3 rounded-lg border border-line p-3" aria-label="Cancel order" noValidate onSubmit={(e) => { e.preventDefault(); setReasonError(''); cancel.mutate() }}>
          <FormField label="Reason" name="reason" type="textarea" required value={reason} error={reasonError} onChange={(v) => setReason(String(v))} />
          <div className="flex justify-end gap-2">
            <IconButton showText variant="secondary" icon="x" label="Keep order" onClick={() => setReason(null)} />
            <IconButton showText type="submit" variant="danger" icon="x" label="Cancel order" disabled={cancel.isPending} />
          </div>
        </form>
      )}

      <div className="flex flex-wrap justify-end gap-2">
        {open && can('orders.cancel') && reason === null && <IconButton showText variant="danger" icon="x" label="Cancel order" onClick={() => setReason('')} />}
        {next && can('orders.update') && <IconButton showText variant="primary" icon="check" label={`Mark ${next}`} disabled={advance.isPending} onClick={() => advance.mutate()} />}
      </div>
    </div>
  )
}
