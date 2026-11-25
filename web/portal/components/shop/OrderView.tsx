'use client'

import { useEffect, useState } from 'react'
import { cancelOrder, lookupOrder, STEPS, type Order } from '../../lib/account-client'
import { formatMoney } from '../../lib/shop'
import { Button, ButtonLink } from './Button'

export type OrderText = Record<string, string>

/** One order: confirmation, tracking steps, items and totals. Guests pass the tracking code; signed-in customers need none. */
export function OrderView({ number, token, justPlaced, text, steps, payments, trackHref, accountHref, shopHref }: { number: string; token: string | null; justPlaced: boolean; text: OrderText; steps: Record<string, string>; payments: Record<string, string>; trackHref: string; accountHref: string; shopHref: string }) {
  const [order, setOrder] = useState<Order | null | undefined>(undefined)
  const [state, setState] = useState<'ok' | 'notfound' | 'signin' | 'error'>('ok')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')

  const load = async () => {
    setState('ok')
    const result = await lookupOrder(number, token)
    if (result.ok) return setOrder(result.data)

    setOrder(null)
    setState(result.failure.status === 404 ? 'notfound' : result.failure.status === 401 ? 'signin' : 'error')
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load once per order.
  }, [number, token])

  async function cancel() {
    setBusy(true)
    const result = await cancelOrder(number)
    setBusy(false)
    setConfirming(false)
    if (result.ok) setOrder(result.data)
    else setMessage(result.failure.message)
  }

  if (order === undefined) return <p aria-live="polite" className="text-ink-muted">{text.loading}</p>

  if (!order) {
    return (
      <div role="alert" className="space-y-4">
        <p className={state === 'error' ? 'text-danger' : ''}>{state === 'notfound' ? text.notFound : state === 'signin' ? text.signIn : text.error}</p>
        {state === 'error' && <Button variant="secondary" onClick={() => void load()}>{text.retry}</Button>}
        {state === 'signin' && <ButtonLink href={accountHref}>{text.signInAction}</ButtonLink>}
        {state === 'notfound' && <ButtonLink href={trackHref} variant="secondary">{text.trackAgain}</ButtonLink>}
      </div>
    )
  }

  const cancelled = order.status === 'cancelled'
  const step = STEPS.indexOf(order.status as (typeof STEPS)[number])
  const stamps: Record<string, string | null> = { pending: order.placed_at, confirmed: order.confirmed_at, packed: order.packed_at, shipped: order.shipped_at, delivered: order.delivered_at }
  const trackingLink = token ? `${trackHref}?number=${encodeURIComponent(order.number)}&token=${encodeURIComponent(token)}` : null
  const date = (iso: string) => new Date(iso).toLocaleDateString('en', { dateStyle: 'long' })

  return (
    <div className="space-y-8">
      {justPlaced && (
        <div role="status" className="space-y-2 rounded-lg bg-brand-50 p-5">
          <p className="font-display text-xl font-semibold text-brand-900">{text.thanks}</p>
          {token && (
            <>
              <p className="text-brand-900">{text.keepTracking}</p>
              <p className="break-all font-mono text-sm text-brand-900">{trackingLink && <a className="underline" href={trackingLink}>{text.trackingLink}</a>} - {text.number} <strong>{order.number}</strong>, {text.code} <strong>{token}</strong></p>
            </>
          )}
        </div>
      )}

      <section aria-labelledby="order-progress" className="space-y-4">
        <h2 id="order-progress" className="font-display text-xl font-semibold">{text.progress}</h2>
        {cancelled ? (
          <p role="status" className="text-danger">
            {text.cancelled} {order.cancel_reason ? text.cancelReason.replace('{reason}', order.cancel_reason) : ''}
          </p>
        ) : (
          <ol className="grid gap-3 sm:grid-cols-5">
            {STEPS.map((s, i) => (
              <li key={s} aria-current={i === step ? 'step' : undefined} className={`rounded-lg border p-3 ${i <= step ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-line text-ink-muted'}`}>
                <p className="font-semibold">{steps[s]}</p>
                <p className="text-sm">{stamps[s] ? date(stamps[s]!) : '-'}</p>
              </li>
            ))}
          </ol>
        )}
        <p className="text-sm text-ink-muted">
          {text.payment}: {payments[order.payment_status] ?? order.payment_status} ({order.payment_method.toUpperCase()}) - {text.placedOn.replace('{date}', date(order.placed_at))}
        </p>
        {order.delivery?.tracking_number && <p>{text.courierTracking}: <strong>{order.delivery.tracking_number}</strong></p>}
      </section>

      <section aria-labelledby="order-items" className="space-y-3">
        <h2 id="order-items" className="font-display text-xl font-semibold">{text.items}</h2>
        <div className="overflow-x-auto rounded-lg border border-line bg-surface">
          <table className="w-full text-start">
            <thead className="bg-brand-50 text-sm">
              <tr>
                <th scope="col" className="p-3 text-start">{text.item}</th>
                <th scope="col" className="p-3 text-end">{text.quantity}</th>
                <th scope="col" className="p-3 text-end">{text.price}</th>
                <th scope="col" className="p-3 text-end">{text.total}</th>
              </tr>
            </thead>
            <tbody>
              {(order.items ?? []).map((item) => (
                <tr key={item.sku} className="border-t border-line">
                  <td className="p-3">{item.name}{Object.keys(item.options).length > 0 && <span className="block text-sm text-ink-muted">{Object.entries(item.options).map(([k, v]) => `${k}: ${v}`).join(', ')}</span>}</td>
                  <td className="p-3 text-end">{item.quantity}</td>
                  <td className="p-3 text-end">{formatMoney(item.unit_price)}</td>
                  <td className="p-3 text-end">{formatMoney(item.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="ms-auto grid max-w-xs grid-cols-2 gap-x-4 gap-y-1 text-end">
          <dt className="text-ink-muted">{text.subtotal}</dt><dd>{formatMoney(order.subtotal)}</dd>
          {order.discount_total.amount > 0 && (<><dt className="text-ink-muted">{text.discount}</dt><dd className="text-sale">-{formatMoney(order.discount_total)}</dd></>)}
          <dt className="text-ink-muted">{text.shipping}</dt><dd>{formatMoney(order.shipping_total)}</dd>
          <dt className="text-ink-muted">{text.tax}</dt><dd>{formatMoney(order.tax_total)}</dd>
          <dt className="font-bold">{text.grandTotal}</dt><dd className="font-bold">{formatMoney(order.grand_total)}</dd>
        </dl>
      </section>

      <section aria-labelledby="order-address" className="space-y-1">
        <h2 id="order-address" className="font-display text-xl font-semibold">{text.deliverTo}</h2>
        <p>{order.contact_name}, {order.contact_phone}</p>
        <p className="text-ink-muted">{[order.address_line, order.city, order.district].filter(Boolean).join(', ')}</p>
      </section>

      {message && <p role="alert" className="text-danger">{message}</p>}
      {order.status === 'pending' && !token && (
        <div className="space-y-3">
          {confirming ? (
            <div role="alertdialog" aria-label={text.cancel} className="space-y-3 rounded-lg border border-line p-4">
              <p>{text.cancelConfirm}</p>
              <div className="flex gap-3">
                <Button variant="secondary" onClick={() => setConfirming(false)}>{text.cancelKeep}</Button>
                <Button disabled={busy} onClick={() => void cancel()} className="!bg-sale">{busy ? text.cancelling : text.cancel}</Button>
              </div>
            </div>
          ) : (
            <Button variant="secondary" onClick={() => setConfirming(true)}>{text.cancel}</Button>
          )}
          <p className="text-sm text-ink-muted">{text.cancelOnlyPending}</p>
        </div>
      )}
      <ButtonLink href={shopHref} variant="ghost">{text.keepShopping}</ButtonLink>
    </div>
  )
}
