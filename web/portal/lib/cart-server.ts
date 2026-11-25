import { cookies } from 'next/headers'

/**
 * Cart proxy for the browser. The cart token lives in an httpOnly cookie, so page scripts never see it; the route
 * handlers under app/api/cart add it to every API call. Only route handlers import this file (they may read cookies;
 * pages and shared components must not).
 */
export const CART_COOKIE = 'cart_token'
const MAX_AGE = 60 * 60 * 24 * 30

const apiBase = () => (process.env.API_INTERNAL_URL ?? 'http://api.localhost/api').replace(/\/+$/, '')

export async function readToken(): Promise<string | null> {
  return (await cookies()).get(CART_COOKIE)?.value ?? null
}

export async function writeToken(token: string): Promise<void> {
  ;(await cookies()).set(CART_COOKIE, token, { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', path: '/', maxAge: MAX_AGE })
}

export async function clearToken(): Promise<void> {
  ;(await cookies()).delete(CART_COOKIE)
}

export async function callApi(method: string, path: string, body?: unknown, forwardedFor?: string | null): Promise<Response> {
  return fetch(`${apiBase()}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    cache: 'no-store',
  })
}

export const noStore = { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }

export const unavailable = () => Response.json({ error: { code: 'unavailable', message: 'The service is unavailable.' } }, { status: 502, headers: { 'Cache-Control': 'no-store' } })

/** Create a cart and remember its token. Returns the token, or null when the API refused. */
export async function createCart(forwardedFor?: string | null): Promise<string | null> {
  const response = await callApi('POST', '/v1/public/carts', undefined, forwardedFor)
  if (!response.ok) return null

  const token = ((await response.json()) as { data: { token: string } }).data.token
  await writeToken(token)

  return token
}
