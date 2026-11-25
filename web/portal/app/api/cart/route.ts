import { callApi, clearToken, noStore, readToken, unavailable } from '../../../lib/cart-server'

/** Current cart for the visitor, or `{data: null}` when they have none (or it expired). */
export async function GET(request: Request) {
  const token = await readToken()
  if (!token) return new Response(JSON.stringify({ data: null }), { headers: noStore })

  const params = new URL(request.url).searchParams
  const query = new URLSearchParams()
  for (const key of ['delivery_zone_id', 'coupon_code']) if (params.get(key)) query.set(key, params.get(key)!)
  try {
    const response = await callApi('GET', `/v1/public/carts/${encodeURIComponent(token)}${query.size ? `?${query}` : ''}`)
    if (response.status === 404) {
      await clearToken()

      return new Response(JSON.stringify({ data: null }), { headers: noStore })
    }

    return new Response(await response.text(), { status: response.status, headers: noStore })
  } catch {
    return unavailable()
  }
}
