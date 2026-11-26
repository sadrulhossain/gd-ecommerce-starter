import { clearToken, readToken } from '../../../lib/cart-server'
import { apiCall, readCustomerToken, relay, safely } from '../../../lib/customer-server'

const noCart = () => new Response(JSON.stringify({ message: 'Your cart is empty.', errors: { items: ['Your cart is empty.'] } }), { status: 422, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } })

/** Checkout options: active delivery zones (with fee) and enabled payment methods. Never cached, so an admin change shows at once. */
export async function GET(request: Request) {
  const forwardedFor = request.headers.get('x-forwarded-for')

  return safely(async () => {
    const [zones, methods] = await Promise.all([apiCall('GET', '/v1/public/delivery-zones', { forwardedFor }), apiCall('GET', '/v1/public/payment-methods', { forwardedFor })])
    if (!zones.ok || !methods.ok) return relay(zones.ok ? methods : zones)

    return new Response(JSON.stringify({ zones: ((await zones.json()) as { data: unknown }).data, methods: ((await methods.json()) as { data: unknown }).data }), {
      headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
    })
  })
}

/** Place the order from the visitor's cart. A signed-in customer's token is forwarded so the order joins their account. */
export async function POST(request: Request) {
  const cart = await readToken()
  if (!cart) return noCart()

  const body = await request.json().catch(() => null)
  if (!body || typeof body !== 'object') return Response.json({ message: 'Invalid request.' }, { status: 400 })

  return safely(async () => {
    const response = await apiCall('POST', `/v1/public/carts/${encodeURIComponent(cart)}/checkout`, {
      body,
      bearer: await readCustomerToken(),
      forwardedFor: request.headers.get('x-forwarded-for'),
    })
    // The API empties the cart on success; an unknown cart (expired) is gone for good.
    if (response.status === 201 || response.status === 404) await clearToken()

    return relay(response)
  })
}
