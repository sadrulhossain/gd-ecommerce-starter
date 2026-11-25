import { callApi, clearToken, createCart, noStore, readToken, unavailable } from '../../../../lib/cart-server'

/** Add a variant to the cart, creating the cart (and its cookie) on first use or after the old one expired. */
export async function POST(request: Request) {
  const forwardedFor = request.headers.get('x-forwarded-for')
  const body = await request.json().catch(() => null)
  if (!body || typeof body !== 'object') return Response.json({ error: { code: 'invalid', message: 'Invalid request.' } }, { status: 400 })

  try {
    let token = await readToken()
    let response = token ? await callApi('POST', `/v1/public/carts/${encodeURIComponent(token)}/items`, body, forwardedFor) : null

    if (!token || response?.status === 404) {
      if (token) await clearToken()
      token = await createCart(forwardedFor)
      if (!token) return unavailable()
      response = await callApi('POST', `/v1/public/carts/${encodeURIComponent(token)}/items`, body, forwardedFor)
    }

    return new Response(await response!.text(), { status: response!.status, headers: noStore })
  } catch {
    return unavailable()
  }
}
