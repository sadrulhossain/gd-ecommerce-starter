import { cookies } from 'next/headers'
import { noStore, unavailable } from './cart-server'

/**
 * Customer session for the browser. The customer's API token lives in an httpOnly cookie that only the route handlers
 * under app/api read, so page scripts never see it. Like cart-server.ts, only route handlers import this file.
 */
export const CUSTOMER_COOKIE = 'customer_token'
const MAX_AGE = 60 * 60 * 24 * 7

const apiBase = () => (process.env.API_INTERNAL_URL ?? 'http://api.localhost/api').replace(/\/+$/, '')

export async function readCustomerToken(): Promise<string | null> {
  return (await cookies()).get(CUSTOMER_COOKIE)?.value ?? null
}

export async function writeCustomerToken(token: string): Promise<void> {
  ;(await cookies()).set(CUSTOMER_COOKIE, token, { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', path: '/', maxAge: MAX_AGE })
}

export async function clearCustomerToken(): Promise<void> {
  ;(await cookies()).delete(CUSTOMER_COOKIE)
}

export interface CallOptions {
  body?: unknown
  bearer?: string | null
  forwardedFor?: string | null
}

/** One API call: JSON in, raw Response out (never cached). The visitor's IP is forwarded so per-IP throttles see them. */
export async function apiCall(method: string, path: string, { body, bearer, forwardedFor }: CallOptions = {}): Promise<Response> {
  return fetch(`${apiBase()}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}),
      ...(forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    cache: 'no-store',
  })
}

/** Relay the API's status and body as is, so 422 field errors and 429 throttling reach the form. */
export async function relay(response: Response): Promise<Response> {
  return new Response(response.status === 204 ? null : await response.text(), { status: response.status, headers: noStore })
}

/** Run a handler; any network failure becomes a 502 JSON error. */
export async function safely(run: () => Promise<Response>): Promise<Response> {
  try {
    return await run()
  } catch {
    return unavailable()
  }
}

export const signedOut = () => Response.json({ error: { code: 'unauthenticated', message: 'Sign in to continue.' } }, { status: 401, headers: noStore })
