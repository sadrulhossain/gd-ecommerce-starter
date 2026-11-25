import type { Money } from './shop'

export interface CartLine {
  variant_id: number
  sku: string
  name: string
  options: Record<string, string>
  quantity: number
  unit_price: Money
  discount_amount: Money
  line_total: Money
  available: boolean
  issue: string | null
}

export interface Cart {
  items: CartLine[]
  subtotal: Money
  shipping_total: Money
  tax_total: Money
  discount_total: Money
  grand_total: Money
  can_checkout: boolean
  coupon_code: string | null
  coupon_error: string | null
}

/** Result of a cart call: the new cart, or the first validation message to show next to the control. */
export type CartResult = { ok: true; cart: Cart | null } | { ok: false; message: string }

export const CART_CHANGED = 'cart:changed'

export const itemCount = (cart: Cart | null): number => cart?.items.reduce((sum, line) => sum + line.quantity, 0) ?? 0

const fail = (message: string): CartResult => ({ ok: false, message })

async function read(response: Response, fallback: string): Promise<CartResult> {
  const body = (await response.json().catch(() => null)) as { data?: Cart | null; message?: string; errors?: Record<string, string[]>; error?: { message?: string } } | null
  if (!response.ok) return fail(Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? body?.error?.message ?? fallback)

  return { ok: true, cart: body?.data ?? null }
}

/** Tell the header (and any other listener) that the cart changed. */
function announce(cart: Cart | null): void {
  window.dispatchEvent(new CustomEvent(CART_CHANGED, { detail: itemCount(cart) }))
}

export async function fetchCart(preview: { zoneId?: string; coupon?: string } = {}): Promise<CartResult> {
  const query = new URLSearchParams()
  if (preview.zoneId) query.set('delivery_zone_id', preview.zoneId)
  if (preview.coupon) query.set('coupon_code', preview.coupon)

  try {
    const result = await read(await fetch(`/api/cart${query.size ? `?${query}` : ''}`, { cache: 'no-store' }), 'Could not load the cart.')
    if (result.ok) announce(result.cart)

    return result
  } catch {
    return fail('Could not load the cart.')
  }
}

export async function addToCart(variantId: number, quantity: number): Promise<CartResult> {
  try {
    const result = await read(
      await fetch('/api/cart/items', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ variant_id: variantId, quantity }) }),
      'Could not add this item.',
    )
    if (result.ok) announce(result.cart)

    return result
  } catch {
    return fail('Could not add this item.')
  }
}

/** Quantity 0 removes the line. Resolves with the refreshed cart. */
export async function setQuantity(variantId: number, quantity: number): Promise<CartResult> {
  try {
    const response = quantity > 0
      ? await fetch(`/api/cart/items/${variantId}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ quantity }) })
      : await fetch(`/api/cart/items/${variantId}`, { method: 'DELETE' })
    if (!response.ok) return read(response, 'Could not update the cart.')

    return await fetchCart()
  } catch {
    return fail('Could not update the cart.')
  }
}
