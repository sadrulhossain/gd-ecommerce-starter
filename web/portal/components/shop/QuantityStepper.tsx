'use client'

import { useId } from 'react'
import { clampQuantity } from '../../lib/shop'

/** Quantity control: minus, a number field and plus. Controlled; values stay whole numbers inside min..max. */
export function QuantityStepper({ value, onChange, min = 1, max = 99, label = 'Quantity' }: { value: number; onChange: (value: number) => void; min?: number; max?: number; label?: string }) {
  const id = useId()
  const set = (n: number) => onChange(clampQuantity(n, min, max))
  const step = 'grid size-11 place-items-center text-xl text-ink hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:opacity-40'

  return (
    <div role="group" aria-labelledby={id} className="inline-flex items-stretch overflow-hidden rounded-full border border-line bg-surface">
      <span id={id} className="sr-only">{label}</span>
      <button type="button" className={step} aria-label="Decrease quantity" disabled={value <= min} onClick={() => set(value - 1)}>
        <span aria-hidden="true">-</span>
      </button>
      <input
        type="number"
        inputMode="numeric"
        aria-labelledby={id}
        className="w-12 border-x border-line bg-transparent text-center text-base [appearance:textfield] focus-visible:outline-2 focus-visible:outline-brand-600"
        min={min}
        max={max}
        value={value}
        onChange={(e) => set(e.target.valueAsNumber)}
      />
      <button type="button" className={step} aria-label="Increase quantity" disabled={value >= max} onClick={() => set(value + 1)}>
        <span aria-hidden="true">+</span>
      </button>
    </div>
  )
}
