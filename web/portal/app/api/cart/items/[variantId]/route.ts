import { callApi, noStore, readToken, unavailable } from '../../../../../lib/cart-server'

type Context = { params: Promise<{ variantId: string }> }

const none = () => Response.json({ error: { code: 'no_cart', message: 'There is no cart.' } }, { status: 404, headers: noStore })

/** Set a line's quantity (0 removes it). */
export async function PATCH(request: Request, { params }: Context) {
  const { variantId } = await params
  const token = await readToken()
  if (!token || !/^\d+$/.test(variantId)) return none()

  try {
    const response = await callApi('PATCH', `/v1/public/carts/${encodeURIComponent(token)}/items/${variantId}`, await request.json().catch(() => ({})), request.headers.get('x-forwarded-for'))

    return new Response(await response.text(), { status: response.status, headers: noStore })
  } catch {
    return unavailable()
  }
}

/** Remove a line. */
export async function DELETE(request: Request, { params }: Context) {
  const { variantId } = await params
  const token = await readToken()
  if (!token || !/^\d+$/.test(variantId)) return none()

  try {
    const response = await callApi('DELETE', `/v1/public/carts/${encodeURIComponent(token)}/items/${variantId}`, undefined, request.headers.get('x-forwarded-for'))

    return new Response(null, { status: response.status === 204 ? 204 : response.status, headers: { 'Cache-Control': 'no-store' } })
  } catch {
    return unavailable()
  }
}
