'use client'

import { useRouter } from 'next/navigation'
import { useState } from 'react'
import { Button } from './Button'

const field = 'block w-full rounded-lg border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600'

/** Guest tracking: order number and tracking code go to the order page; the code is checked there. */
export function TrackForm({ text, orderPrefix, initial }: { text: Record<string, string>; orderPrefix: string; initial: { number: string; token: string } }) {
  const router = useRouter()
  const [number, setNumber] = useState(initial.number)
  const [token, setToken] = useState(initial.token)

  return (
    <form className="max-w-md space-y-4" onSubmit={(e) => { e.preventDefault(); router.push(`${orderPrefix}/${encodeURIComponent(number.trim())}?token=${encodeURIComponent(token.trim())}`) }}>
      <div className="space-y-1.5">
        <label htmlFor="track-number" className="text-sm font-semibold">{text.number}</label>
        <input id="track-number" required className={field} value={number} onChange={(e) => setNumber(e.target.value)} autoComplete="off" />
      </div>
      <div className="space-y-1.5">
        <label htmlFor="track-code" className="text-sm font-semibold">{text.code}</label>
        <input id="track-code" required className={field} value={token} onChange={(e) => setToken(e.target.value)} autoComplete="off" />
      </div>
      <Button type="submit" size="lg">{text.submit}</Button>
    </form>
  )
}
