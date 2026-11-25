'use client'

import { useEffect, useState } from 'react'
import { fetchCart, setQuantity, type Cart } from '../../lib/cart-client'
import { formatMoney } from '../../lib/shop'
import { Button, ButtonLink } from './Button'
import { EmptyState } from './EmptyState'
import { QuantityStepper } from './QuantityStepper'

export interface CartText {
  loading: string
  emptyTitle: string
  emptyText: string
  keepShopping: string
  item: string
  price: string
  quantity: string
  total: string
  remove: string
  removeItem: string
  subtotal: string
  discount: string
  shippingNote: string
  checkout: string
  unavailable: string
  fixIssues: string
  error: string
  retry: string
  summary: string
}

/** The cart page body: lines with quantity and remove, totals from the server, checkout link. Prices are never computed here. */
export function CartView({ text, shopHref, checkoutHref }: { text: CartText; shopHref: string; checkoutHref: string }) {
  const [cart, setCart] = useState<Cart | null | undefined>(undefined)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = async () => {
    setError('')
    const result = await fetchCart()
    if (result.ok) setCart(result.cart)
    else {
      setCart(null)
      setError(text.error)
    }
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load once on mount.
  }, [])

  async function change(variantId: number, quantity: number) {
    setBusy(true)
    const result = await setQuantity(variantId, quantity)
    if (result.ok) {
      setCart(result.cart)
      setError('')
    } else setError(result.message)
    setBusy(false)
  }

  if (cart === undefined) return <p aria-live="polite" className="text-ink-muted">{text.loading}</p>

  if (error && !cart) {
    return (
      <div role="alert" className="space-y-3">
        <p className="text-danger">{error}</p>
        <Button variant="secondary" onClick={() => void load()}>{text.retry}</Button>
      </div>
    )
  }

  if (!cart || cart.items.length === 0) {
    return (
      <EmptyState title={text.emptyTitle} action={<ButtonLink href={shopHref}>{text.keepShopping}</ButtonLink>}>
        {text.emptyText}
      </EmptyState>
    )
  }

  const hasDiscount = cart.discount_total.amount > 0

  return (
    <div className="grid gap-8 lg:grid-cols-[1fr_22rem]">
      <div className="space-y-4">
        {error && <p role="alert" className="text-danger">{error}</p>}
        <ul className="divide-y divide-line rounded-lg border border-line bg-surface">
          {cart.items.map((line) => (
            <li key={line.variant_id} className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
              <div className="min-w-48 flex-1">
                <p className="font-display text-lg font-semibold">{line.name}</p>
                {Object.keys(line.options).length > 0 && <p className="text-sm text-ink-muted">{Object.entries(line.options).map(([k, v]) => `${k}: ${v}`).join(', ')}</p>}
                <p className="text-sm text-ink-muted">{formatMoney(line.unit_price)}</p>
                {!line.available && <p role="alert" className="text-sm font-semibold text-danger">{text.unavailable}{line.issue ? `: ${line.issue}` : ''}</p>}
              </div>
              <QuantityStepper value={line.quantity} onChange={(q) => void change(line.variant_id, q)} max={20} label={`${text.quantity}: ${line.name}`} />
              <p className="w-28 text-end font-semibold">{formatMoney(line.line_total)}</p>
              <Button variant="ghost" size="sm" disabled={busy} aria-label={text.removeItem.replace('{name}', line.name)} onClick={() => void change(line.variant_id, 0)}>
                {text.remove}
              </Button>
            </li>
          ))}
        </ul>
      </div>

      <aside aria-label={text.summary} className="h-fit space-y-4 rounded-lg border border-line bg-surface p-6 shadow-soft">
        <h2 className="font-display text-xl font-semibold">{text.summary}</h2>
        <dl className="space-y-2">
          <div className="flex justify-between"><dt className="text-ink-muted">{text.subtotal}</dt><dd>{formatMoney(cart.subtotal)}</dd></div>
          {hasDiscount && <div className="flex justify-between"><dt className="text-ink-muted">{text.discount}</dt><dd className="text-sale">-{formatMoney(cart.discount_total)}</dd></div>}
          <div className="flex justify-between border-t border-line pt-2 text-lg font-bold"><dt>{text.total}</dt><dd>{formatMoney(cart.grand_total)}</dd></div>
        </dl>
        <p className="text-sm text-ink-muted">{text.shippingNote}</p>
        {cart.can_checkout ? (
          <ButtonLink href={checkoutHref} size="lg" block>{text.checkout}</ButtonLink>
        ) : (
          <>
            <Button size="lg" block disabled>{text.checkout}</Button>
            <p role="alert" className="text-sm text-danger">{text.fixIssues}</p>
          </>
        )}
      </aside>
    </div>
  )
}
