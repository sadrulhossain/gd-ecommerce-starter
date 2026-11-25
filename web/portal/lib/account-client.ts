import type { Money } from './shop'

export interface Customer {
  id: number
  name: string
  email: string
  phone: string | null
}

export interface OrderItem {
  sku: string
  name: string
  options: Record<string, string>
  quantity: number
  unit_price: Money
  line_total: Money
}

export interface Order {
  number: string
  status: string
  payment_method: string
  payment_status: string
  subtotal: Money
  shipping_total: Money
  tax_total: Money
  discount_total: Money
  grand_total: Money
  contact_name: string
  contact_phone: string
  address_line: string
  city: string
  district: string | null
  cancel_reason: string | null
  placed_at: string
  confirmed_at: string | null
  packed_at: string | null
  shipped_at: string | null
  delivered_at: string | null
  cancelled_at: string | null
  delivery?: { tracking_number: string | null } | null
  items?: OrderItem[]
}

/** Field errors keyed by form field name, plus a message for the form as a whole. */
export interface Failure {
  message: string
  fields: Record<string, string>
  status: number
}

export type Result<T> = { ok: true; data: T; extra?: Record<string, unknown> } | { ok: false; failure: Failure }

async function call<T>(input: string, init?: RequestInit): Promise<Result<T>> {
  try {
    const response = await fetch(input, { ...init, cache: 'no-store', headers: { 'Content-Type': 'application/json', ...init?.headers } })
    const body = (response.status === 204 ? {} : await response.json().catch(() => ({}))) as { data?: T; message?: string; errors?: Record<string, string[]>; error?: { message?: string }; lookup_token?: string }

    if (!response.ok) {
      const fields = Object.fromEntries(Object.entries(body.errors ?? {}).map(([key, messages]) => [key, messages[0] ?? '']))

      return { ok: false, failure: { message: Object.values(fields)[0] ?? body.message ?? body.error?.message ?? 'Something went wrong. Please try again.', fields, status: response.status } }
    }

    return { ok: true, data: body.data as T, extra: body.lookup_token ? { lookup_token: body.lookup_token } : undefined }
  } catch {
    return { ok: false, failure: { message: 'Something went wrong. Please try again.', fields: {}, status: 0 } }
  }
}

const post = (body: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(body) })

export const me = () => call<Customer | null>('/api/account/me')
export const login = (email: string, password: string) => call<Customer>('/api/account/login', post({ email, password }))
export const register = (fields: { name: string; email: string; phone?: string; password: string }) => call<Customer>('/api/account/register', post(fields))
export const logout = () => call<null>('/api/account/logout', { method: 'POST' })
export const myOrders = (page = 1) => call<Order[]>(`/api/account/orders?page=${page}`)
export const lookupOrder = (number: string, token?: string | null) => call<Order>(`/api/orders/${encodeURIComponent(number)}${token ? `?token=${encodeURIComponent(token)}` : ''}`)
export const cancelOrder = (number: string) => call<Order>(`/api/orders/${encodeURIComponent(number)}`, { method: 'POST' })

export interface CheckoutOptions {
  zones: Array<{ id: number; name: string; shipping_fee: Money }>
  methods: Array<{ code: string; name: string }>
}

export async function checkoutOptions(): Promise<CheckoutOptions | null> {
  try {
    const response = await fetch('/api/checkout', { cache: 'no-store' })

    return response.ok ? ((await response.json()) as CheckoutOptions) : null
  } catch {
    return null
  }
}

export const placeOrder = (fields: Record<string, unknown>) => call<Order>('/api/checkout', post(fields))

/** Status steps in order; a cancelled order has its own state. */
export const STEPS = ['pending', 'confirmed', 'packed', 'shipped', 'delivered'] as const
