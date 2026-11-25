'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { login, logout, me, myOrders, register, type Customer, type Failure, type Order } from '../../lib/account-client'
import { formatMoney } from '../../lib/shop'
import { Button, ButtonLink } from './Button'

export type AccountText = Record<string, string>

const field = 'block w-full rounded-lg border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600'

function Input({ id, label, type = 'text', value, onChange, error, hint, autoComplete }: { id: string; label: string; type?: string; value: string; onChange: (v: string) => void; error?: string; hint?: string; autoComplete?: string }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-semibold">{label}</label>
      <input id={id} type={type} className={field} value={value} autoComplete={autoComplete} aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined} onChange={(e) => onChange(e.target.value)} />
      {hint && !error && <p id={`${id}-hint`} className="text-sm text-ink-muted">{hint}</p>}
      {error && <p id={`${id}-error`} role="alert" className="text-sm text-danger">{error}</p>}
    </div>
  )
}

/** Sign in / create account, then the customer's orders. The session is an httpOnly cookie managed by the portal's route handlers. */
export function AccountView({ text, steps, orderPrefix, shopHref }: { text: AccountText; steps: Record<string, string>; orderPrefix: string; shopHref: string }) {
  const [customer, setCustomer] = useState<Customer | null | undefined>(undefined)

  useEffect(() => {
    let alive = true
    void me().then((r) => alive && setCustomer(r.ok ? r.data : null))

    return () => {
      alive = false
    }
  }, [])

  if (customer === undefined) return <p aria-live="polite" className="text-ink-muted">{text.loading}</p>
  if (!customer) return <SignIn text={text} onDone={setCustomer} />

  return <Orders text={text} steps={steps} customer={customer} orderPrefix={orderPrefix} shopHref={shopHref} onSignOut={() => setCustomer(null)} />
}

function SignIn({ text, onDone }: { text: AccountText; onDone: (c: Customer) => void }) {
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [values, setValues] = useState({ name: '', email: '', phone: '', password: '' })
  const [failure, setFailure] = useState<Failure | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (key: keyof typeof values) => (v: string) => setValues((s) => ({ ...s, [key]: v }))

  async function submit() {
    setBusy(true)
    setFailure(null)
    const result = mode === 'login' ? await login(values.email, values.password) : await register({ name: values.name, email: values.email, phone: values.phone || undefined, password: values.password })
    setBusy(false)
    if (result.ok) onDone(result.data)
    else setFailure(result.failure)
  }

  const errors = failure?.fields ?? {}

  return (
    <form className="mx-auto max-w-md space-y-4" noValidate onSubmit={(e) => { e.preventDefault(); void submit() }}>
      <h2 className="font-display text-2xl font-semibold">{mode === 'login' ? text.signIn : text.register}</h2>
      {failure && Object.keys(errors).length === 0 && <p role="alert" className="text-danger">{failure.message}</p>}
      {mode === 'register' && <Input id="ac-name" label={text.name} value={values.name} onChange={set('name')} error={errors.name} autoComplete="name" />}
      <Input id="ac-email" label={text.email} type="email" value={values.email} onChange={set('email')} error={errors.email} autoComplete="email" />
      {mode === 'register' && <Input id="ac-phone" label={text.phone} type="tel" value={values.phone} onChange={set('phone')} error={errors.phone} autoComplete="tel" />}
      <Input id="ac-password" label={text.password} type="password" value={values.password} onChange={set('password')} error={errors.password} hint={mode === 'register' ? text.passwordHint : undefined} autoComplete={mode === 'login' ? 'current-password' : 'new-password'} />
      <Button type="submit" size="lg" block disabled={busy}>{mode === 'login' ? text.signIn : text.register}</Button>
      <p className="text-center text-sm text-ink-muted">
        {mode === 'login' ? text.noAccount : text.haveAccount}{' '}
        <button type="button" className="font-semibold text-brand-700 underline underline-offset-2" onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setFailure(null) }}>
          {mode === 'login' ? text.register : text.signIn}
        </button>
      </p>
    </form>
  )
}

function Orders({ text, steps, customer, orderPrefix, shopHref, onSignOut }: { text: AccountText; steps: Record<string, string>; customer: Customer; orderPrefix: string; shopHref: string; onSignOut: () => void }) {
  const [page, setPage] = useState(1)
  const [orders, setOrders] = useState<Order[] | undefined>(undefined)
  const [lastPage, setLastPage] = useState(1)
  const [error, setError] = useState(false)

  useEffect(() => {
    let alive = true
    setOrders(undefined)
    void fetch(`/api/account/orders?page=${page}`, { cache: 'no-store' })
      .then(async (response) => {
        if (response.status === 401) return onSignOut()
        if (!response.ok) throw new Error('failed')
        const body = (await response.json()) as { data: Order[]; meta?: { last_page: number } }
        if (!alive) return
        setOrders(body.data)
        setLastPage(body.meta?.last_page ?? 1)
      })
      .catch(() => alive && setError(true))

    return () => {
      alive = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- onSignOut is stable for this view.
  }, [page])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="font-display text-xl font-semibold">{text.hello.replace('{name}', customer.name)}</p>
        <Button variant="secondary" size="sm" onClick={() => void logout().then(onSignOut)}>{text.signOut}</Button>
      </div>
      <h2 className="font-display text-2xl font-semibold">{text.orders}</h2>
      {error ? (
        <p role="alert" className="text-danger">{text.error}</p>
      ) : orders === undefined ? (
        <p aria-live="polite" className="text-ink-muted">{text.loading}</p>
      ) : orders.length === 0 ? (
        <div className="space-y-4">
          <p className="text-ink-muted">{text.noOrders}</p>
          <ButtonLink href={shopHref}>{text.shop}</ButtonLink>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-line bg-surface">
          <table className="w-full">
            <thead className="bg-brand-50 text-sm">
              <tr>
                <th scope="col" className="p-3 text-start">{text.number}</th>
                <th scope="col" className="p-3 text-start">{text.date}</th>
                <th scope="col" className="p-3 text-start">{text.status}</th>
                <th scope="col" className="p-3 text-end">{text.total}</th>
                <th scope="col" className="p-3"><span className="sr-only">{text.view}</span></th>
              </tr>
            </thead>
            <tbody>
              {orders.map((o) => (
                <tr key={o.number} className="border-t border-line">
                  <td className="p-3 font-semibold">{o.number}</td>
                  <td className="p-3">{new Date(o.placed_at).toLocaleDateString('en', { dateStyle: 'medium' })}</td>
                  <td className="p-3">{o.status === 'cancelled' ? 'Cancelled' : steps[o.status] ?? o.status}</td>
                  <td className="p-3 text-end">{formatMoney(o.grand_total)}</td>
                  <td className="p-3 text-end"><Link href={`${orderPrefix}/${encodeURIComponent(o.number)}`} className="font-semibold text-brand-700 underline underline-offset-2" aria-label={`${text.view} ${o.number}`}>{text.view}</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-4">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{text.previous}</Button>
          <span className="text-ink-muted">{page} / {lastPage}</span>
          <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>{text.next}</Button>
        </div>
      )}
    </div>
  )
}
