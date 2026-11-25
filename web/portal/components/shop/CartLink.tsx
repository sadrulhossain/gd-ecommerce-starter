'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { CART_CHANGED, fetchCart, itemCount } from '../../lib/cart-client'

/** Header cart link with the item count. Loads the count once on the client (the header itself never reads cookies) and follows cart changes. */
export function CartLink({ href, label, className = '' }: { href: string; label: string; className?: string }) {
  const [count, setCount] = useState(0)

  useEffect(() => {
    let alive = true
    void fetchCart().then((result) => alive && result.ok && setCount(itemCount(result.cart)))
    const onChange = (e: Event) => setCount(Number((e as CustomEvent<number>).detail) || 0)
    window.addEventListener(CART_CHANGED, onChange)

    return () => {
      alive = false
      window.removeEventListener(CART_CHANGED, onChange)
    }
  }, [])

  return (
    <Link href={href} className={`relative inline-flex min-h-11 items-center gap-2 rounded-full px-3 py-2 font-medium text-ink-muted transition-colors hover:bg-brand-50 hover:text-brand-700 ${className}`}>
      <svg viewBox="0 0 24 24" aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M3 4h2l2.4 11h10.2L20 7H6" />
        <circle cx="9" cy="19.5" r="1.3" />
        <circle cx="17" cy="19.5" r="1.3" />
      </svg>
      <span>{label}</span>
      {count > 0 && (
        <span className="grid min-w-5 place-items-center rounded-full bg-brand-700 px-1.5 text-xs font-semibold text-white" aria-label={`${count}`}>
          {count}
        </span>
      )}
    </Link>
  )
}
