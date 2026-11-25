'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { checkoutOptions, me, placeOrder, type CheckoutOptions, type Customer } from '../../lib/account-client'
import { fetchCart, type Cart } from '../../lib/cart-client'
import { formatMoney } from '../../lib/shop'
import { Button, ButtonLink } from './Button'
import { EmptyState } from './EmptyState'

export type CheckoutText = Record<string, string>

const field = 'block w-full rounded-lg border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600'

function Field({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-semibold">{label}</label>
      {children}
      {hint && !error && <p id={`${id}-hint`} className="text-sm text-ink-muted">{hint}</p>}
      {error && <p id={`${id}-error`} role="alert" className="text-sm text-danger">{error}</p>}
    </div>
  )
}

const initial = { contact_name: '', contact_phone: '', contact_email: '', address_line: '', city: '', district: '', postal_code: '', note: '', delivery_zone_id: '', payment_method: '' }

/** Checkout: contact, address, area, payment and coupon. Totals are the server's quote for the chosen area and coupon; nothing is computed here. */
export function CheckoutForm({ text, shopHref, cartHref, orderPrefix }: { text: CheckoutText; shopHref: string; cartHref: string; orderPrefix: string }) {
  const router = useRouter()
  const [options, setOptions] = useState<CheckoutOptions | null | undefined>(undefined)
  const [cart, setCart] = useState<Cart | null | undefined>(undefined)
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [values, setValues] = useState(initial)
  const [coupon, setCoupon] = useState('')
  const [applied, setApplied] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  const quote = async (zoneId: string, code: string) => {
    const result = await fetchCart({ zoneId: zoneId || undefined, coupon: code || undefined })
    if (result.ok) setCart(result.cart)

    return result
  }

  const load = async () => {
    setFailed(false)
    const [opts, user, cartResult] = await Promise.all([checkoutOptions(), me(), fetchCart()])
    if (!opts || !cartResult.ok) return setFailed(true)

    setOptions(opts)
    setCart(cartResult.cart)
    if (user.ok && user.data) {
      setCustomer(user.data)
      setValues((v) => ({ ...v, contact_name: user.data!.name, contact_email: user.data!.email, contact_phone: user.data!.phone ?? '' }))
    }
    setValues((v) => ({ ...v, payment_method: opts.methods[0]?.code ?? '' }))
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load once on mount.
  }, [])

  const set = (name: keyof typeof initial, value: string) => setValues((v) => ({ ...v, [name]: value }))

  async function chooseZone(zoneId: string) {
    set('delivery_zone_id', zoneId)
    await quote(zoneId, applied)
  }

  async function applyCoupon() {
    const code = coupon.trim()
    setApplied(code)
    const result = await quote(values.delivery_zone_id, code)
    if (result.ok && result.cart?.coupon_error) setErrors((e) => ({ ...e, coupon_code: result.cart!.coupon_error! }))
    else setErrors((e) => ({ ...e, coupon_code: '' }))
  }

  async function submit() {
    setBusy(true)
    setErrors({})
    setFormError('')
    const body: Record<string, unknown> = { ...values, delivery_zone_id: values.delivery_zone_id ? Number(values.delivery_zone_id) : null, coupon_code: applied || null }
    for (const key of ['contact_email', 'district', 'postal_code', 'note'] as const) if (!values[key].trim()) body[key] = null

    const result = await placeOrder(body)
    if (result.ok) return router.push(`${orderPrefix}/${encodeURIComponent(result.data.number)}?token=${encodeURIComponent(String(result.extra?.lookup_token ?? ''))}&placed=1`)

    setErrors(result.failure.fields)
    setFormError(Object.keys(result.failure.fields).length === 0 || result.failure.fields.items ? result.failure.message : '')
    setBusy(false)
  }

  if (failed) {
    return (
      <div role="alert" className="space-y-3">
        <p className="text-danger">{text.loadError}</p>
        <Button variant="secondary" onClick={() => void load()}>{text.retry}</Button>
      </div>
    )
  }
  if (!options || cart === undefined) return <p aria-live="polite" className="text-ink-muted">{text.loading}</p>

  if (!cart || cart.items.length === 0) {
    return (
      <EmptyState title={text.emptyTitle} action={<ButtonLink href={shopHref}>{text.keepShopping}</ButtonLink>}>
        {text.emptyText}
      </EmptyState>
    )
  }

  const hasDiscount = cart.discount_total.amount > 0
  const zoneChosen = values.delivery_zone_id !== ''

  return (
    <form className="grid gap-8 lg:grid-cols-[1fr_24rem]" noValidate onSubmit={(e) => { e.preventDefault(); void submit() }}>
      <div className="space-y-8">
        <p className="text-sm text-ink-muted">{customer ? text.signedInAs.replace('{name}', customer.name) : text.guestNote}</p>
        {formError && <p role="alert" className="rounded-lg bg-sale-soft p-4 text-sale">{formError}</p>}

        <fieldset className="space-y-4">
          <legend className="font-display text-xl font-semibold">{text.contact}</legend>
          <Field id="co-name" label={text.name} error={errors.contact_name}>
            <input id="co-name" className={field} autoComplete="name" required aria-invalid={Boolean(errors.contact_name)} aria-describedby={errors.contact_name ? 'co-name-error' : undefined} value={values.contact_name} onChange={(e) => set('contact_name', e.target.value)} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="co-phone" label={text.phone} error={errors.contact_phone}>
              <input id="co-phone" type="tel" className={field} autoComplete="tel" required aria-invalid={Boolean(errors.contact_phone)} aria-describedby={errors.contact_phone ? 'co-phone-error' : undefined} value={values.contact_phone} onChange={(e) => set('contact_phone', e.target.value)} />
            </Field>
            <Field id="co-email" label={text.email} hint={text.emailHint} error={errors.contact_email}>
              <input id="co-email" type="email" className={field} autoComplete="email" aria-invalid={Boolean(errors.contact_email)} aria-describedby={errors.contact_email ? 'co-email-error' : 'co-email-hint'} value={values.contact_email} onChange={(e) => set('contact_email', e.target.value)} />
            </Field>
          </div>
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="font-display text-xl font-semibold">{text.address}</legend>
          <Field id="co-address" label={text.addressLine} error={errors.address_line}>
            <input id="co-address" className={field} autoComplete="street-address" required aria-invalid={Boolean(errors.address_line)} aria-describedby={errors.address_line ? 'co-address-error' : undefined} value={values.address_line} onChange={(e) => set('address_line', e.target.value)} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field id="co-city" label={text.city} error={errors.city}>
              <input id="co-city" className={field} autoComplete="address-level2" required aria-invalid={Boolean(errors.city)} aria-describedby={errors.city ? 'co-city-error' : undefined} value={values.city} onChange={(e) => set('city', e.target.value)} />
            </Field>
            <Field id="co-district" label={text.district} error={errors.district}>
              <input id="co-district" className={field} autoComplete="address-level1" value={values.district} onChange={(e) => set('district', e.target.value)} />
            </Field>
            <Field id="co-postal" label={text.postalCode} error={errors.postal_code}>
              <input id="co-postal" className={field} autoComplete="postal-code" value={values.postal_code} onChange={(e) => set('postal_code', e.target.value)} />
            </Field>
          </div>
          <Field id="co-zone" label={text.zone} error={errors.delivery_zone_id}>
            <select id="co-zone" className={field} aria-invalid={Boolean(errors.delivery_zone_id)} aria-describedby={errors.delivery_zone_id ? 'co-zone-error' : undefined} value={values.delivery_zone_id} onChange={(e) => void chooseZone(e.target.value)}>
              <option value="">{text.zoneChoose}</option>
              {options.zones.map((z) => (
                <option key={z.id} value={z.id}>{z.name} ({formatMoney(z.shipping_fee)})</option>
              ))}
            </select>
          </Field>
          <Field id="co-note" label={text.note} error={errors.note}>
            <textarea id="co-note" rows={3} className={field} value={values.note} onChange={(e) => set('note', e.target.value)} />
          </Field>
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="font-display text-xl font-semibold">{text.payment}</legend>
          {options.methods.map((m) => (
            <label key={m.code} className="flex min-h-11 items-center gap-3 rounded-lg border border-line bg-surface px-4 py-2">
              <input type="radio" name="payment_method" value={m.code} checked={values.payment_method === m.code} onChange={() => set('payment_method', m.code)} />
              {m.name}
            </label>
          ))}
          {errors.payment_method && <p role="alert" className="text-sm text-danger">{errors.payment_method}</p>}
        </fieldset>
      </div>

      <aside aria-label={text.summary} className="h-fit space-y-4 rounded-lg border border-line bg-surface p-6 shadow-soft">
        <h2 className="font-display text-xl font-semibold">{text.summary}</h2>
        <ul className="space-y-2 text-sm">
          {cart.items.map((line) => (
            <li key={line.variant_id} className="flex justify-between gap-3">
              <span>{line.quantity} x {line.name}</span>
              <span>{formatMoney(line.line_total)}</span>
            </li>
          ))}
        </ul>

        <div className="space-y-1.5">
          <label htmlFor="co-coupon" className="text-sm font-semibold">{text.coupon}</label>
          <div className="flex gap-2">
            <input id="co-coupon" className={field} autoComplete="off" aria-invalid={Boolean(errors.coupon_code)} aria-describedby={errors.coupon_code ? 'co-coupon-error' : undefined} value={coupon} onChange={(e) => setCoupon(e.target.value)} />
            <Button variant="secondary" onClick={() => void applyCoupon()}>{text.applyCoupon}</Button>
          </div>
          {errors.coupon_code && <p id="co-coupon-error" role="alert" className="text-sm text-danger">{errors.coupon_code}</p>}
          {cart.coupon_code && !cart.coupon_error && <p className="text-sm text-brand-800">{text.couponApplied.replace('{code}', cart.coupon_code)}</p>}
        </div>

        <dl className="space-y-2 border-t border-line pt-4">
          <div className="flex justify-between"><dt className="text-ink-muted">{text.subtotal}</dt><dd>{formatMoney(cart.subtotal)}</dd></div>
          {hasDiscount && <div className="flex justify-between"><dt className="text-ink-muted">{text.discount}</dt><dd className="text-sale">-{formatMoney(cart.discount_total)}</dd></div>}
          <div className="flex justify-between"><dt className="text-ink-muted">{text.shipping}</dt><dd>{zoneChosen ? formatMoney(cart.shipping_total) : '-'}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">{text.tax}</dt><dd>{formatMoney(cart.tax_total)}</dd></div>
          <div className="flex justify-between border-t border-line pt-2 text-lg font-bold"><dt>{text.total}</dt><dd>{formatMoney(cart.grand_total)}</dd></div>
        </dl>
        {!zoneChosen && <p className="text-sm text-ink-muted">{text.chooseZone}</p>}
        {!cart.can_checkout && <p role="alert" className="text-sm text-danger">{text.unavailable}</p>}

        <Button type="submit" size="lg" block disabled={busy || !cart.can_checkout}>{busy ? text.placing : text.placeOrder}</Button>
        <ButtonLink href={cartHref} variant="ghost" block>{text.backToCart}</ButtonLink>
      </aside>
    </form>
  )
}
