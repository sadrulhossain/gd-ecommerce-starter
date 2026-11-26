import { apiCall, readCustomerToken, relay, safely, signedOut } from '../../../../lib/customer-server'

type Context = { params: Promise<{ number: string }> }

const valid = (number: string) => /^[A-Za-z0-9-]{3,40}$/.test(number)
const notFound = () => new Response(JSON.stringify({ message: 'Order not found.' }), { status: 404, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } })

/**
 * One order. With `?token=` (the lookup token from checkout) it is the guest lookup; without, it is the signed-in
 * customer's own order.
 */
export async function GET(request: Request, { params }: Context) {
  const { number } = await params
  if (!valid(number)) return notFound()

  const lookup = new URL(request.url).searchParams.get('token')
  if (lookup) return safely(async () => relay(await apiCall('GET', `/v1/public/orders/${encodeURIComponent(number)}?token=${encodeURIComponent(lookup)}`, { forwardedFor: request.headers.get('x-forwarded-for') })))

  const token = await readCustomerToken()
  if (!token) return signedOut()

  return safely(async () => relay(await apiCall('GET', `/v1/customer/orders/${encodeURIComponent(number)}`, { bearer: token, forwardedFor: request.headers.get('x-forwarded-for') })))
}

/** Cancel the signed-in customer's own order (the API allows it only while pending). */
export async function POST(request: Request, { params }: Context) {
  const { number } = await params
  const token = await readCustomerToken()
  if (!token) return signedOut()
  if (!valid(number)) return notFound()

  return safely(async () => relay(await apiCall('POST', `/v1/customer/orders/${encodeURIComponent(number)}/cancel`, { bearer: token, forwardedFor: request.headers.get('x-forwarded-for') })))
}
