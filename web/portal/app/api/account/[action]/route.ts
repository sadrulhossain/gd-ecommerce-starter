import { apiCall, clearCustomerToken, readCustomerToken, relay, safely, writeCustomerToken } from '../../../../lib/customer-server'
import { noStore } from '../../../../lib/cart-server'

type Context = { params: Promise<{ action: string }> }

/** Sign in or register: the API's token goes into an httpOnly cookie, the browser gets only the customer. */
export async function POST(request: Request, { params }: Context) {
  const { action } = await params
  const forwardedFor = request.headers.get('x-forwarded-for')

  if (action === 'login' || action === 'register') {
    const body = await request.json().catch(() => null)
    if (!body || typeof body !== 'object') return Response.json({ error: { code: 'invalid', message: 'Invalid request.' } }, { status: 400, headers: noStore })

    return safely(async () => {
      const response = await apiCall('POST', `/v1/customer/${action}`, { body, forwardedFor })
      if (!response.ok) return relay(response)

      const payload = (await response.json()) as { data: unknown; token: string }
      await writeCustomerToken(payload.token)

      return new Response(JSON.stringify({ data: payload.data }), { status: response.status, headers: noStore })
    })
  }

  if (action === 'logout') {
    return safely(async () => {
      const token = await readCustomerToken()
      if (token) await apiCall('POST', '/v1/customer/logout', { bearer: token, forwardedFor })
      await clearCustomerToken()

      return new Response(null, { status: 204, headers: { 'Cache-Control': 'no-store' } })
    })
  }

  return new Response(null, { status: 404 })
}

/** `GET /api/account/me`: the signed-in customer, or `{data: null}` (a dead token is cleared). */
export async function GET(request: Request, { params }: Context) {
  const { action } = await params
  if (action !== 'me') return new Response(null, { status: 404 })

  return safely(async () => {
    const token = await readCustomerToken()
    if (!token) return new Response(JSON.stringify({ data: null }), { headers: noStore })

    const response = await apiCall('GET', '/v1/customer/me', { bearer: token, forwardedFor: request.headers.get('x-forwarded-for') })
    if (response.status === 401) {
      await clearCustomerToken()

      return new Response(JSON.stringify({ data: null }), { headers: noStore })
    }

    return relay(response)
  })
}

