'use client'

import Link from 'next/link'
import { useId, useState } from 'react'
import { addToCart } from '../../lib/cart-client'
import type { Variant } from '../../lib/shop-api'
import { Price } from './Price'
import { Button } from './Button'
import { QuantityStepper } from './QuantityStepper'

export interface AddToCartText {
  sku: string
  chooseOption: string
  quantity: string
  addToCart: string
  adding: string
  added: string
  viewCart: string
  unavailableVariant: string
}

/** Option names in first-seen order with their values, e.g. Color: [Red, Blue]. */
export function optionGroups(variants: Variant[]): Array<{ name: string; values: string[] }> {
  const groups = new Map<string, string[]>()
  for (const v of variants) {
    for (const [name, value] of Object.entries(v.options)) {
      const values = groups.get(name) ?? []
      if (!values.includes(value)) values.push(value)
      groups.set(name, values)
    }
  }

  return [...groups].map(([name, values]) => ({ name, values }))
}

/** The variant whose options equal `chosen`, if any. */
export function findVariant(variants: Variant[], chosen: Record<string, string>): Variant | undefined {
  return variants.find((v) => Object.entries(chosen).every(([name, value]) => v.options[name] === value) && Object.keys(v.options).length === Object.keys(chosen).length)
}

/** Variant pickers (one select per option), price, quantity and the add button. Errors from the cart (stock, limits) show under the button. */
export function AddToCart({ variants, text, cartHref }: { variants: Variant[]; text: AddToCartText; cartHref: string }) {
  const cheapest = [...variants].sort((a, b) => a.price.amount - b.price.amount)[0]
  const groups = optionGroups(variants)
  const [chosen, setChosen] = useState<Record<string, string>>(cheapest?.options ?? {})
  const [quantity, setQuantity] = useState(1)
  const [state, setState] = useState<{ kind: 'idle' | 'busy' | 'done' | 'error'; message?: string }>({ kind: 'idle' })
  const id = useId()

  const variant = groups.length === 0 ? variants[0] : findVariant(variants, chosen)

  async function submit() {
    if (!variant) return
    setState({ kind: 'busy' })
    const result = await addToCart(variant.id, quantity)
    setState(result.ok ? { kind: 'done' } : { kind: 'error', message: result.message })
  }

  if (!cheapest) return null

  return (
    <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); void submit() }}>
      {groups.map((group) => (
        <div key={group.name} className="space-y-1.5">
          <label htmlFor={`${id}-${group.name}`} className="text-sm font-semibold">{group.name}</label>
          <select
            id={`${id}-${group.name}`}
            className="block w-full max-w-xs rounded-full border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600"
            value={chosen[group.name] ?? ''}
            onChange={(e) => { setChosen({ ...chosen, [group.name]: e.target.value }); setState({ kind: 'idle' }) }}
          >
            {group.values.map((value) => (
              <option key={value} value={value}>{value}</option>
            ))}
          </select>
        </div>
      ))}

      {variant ? (
        <>
          <Price price={variant.price} compareAt={variant.compare_at_price} size="lg" />
          <p className="text-sm text-ink-muted">{text.sku}: {variant.sku}</p>
        </>
      ) : (
        <p role="alert" className="text-danger">{text.unavailableVariant}</p>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <QuantityStepper value={quantity} onChange={setQuantity} max={20} label={text.quantity} />
        <Button type="submit" size="lg" disabled={!variant || state.kind === 'busy'}>
          {state.kind === 'busy' ? text.adding : text.addToCart}
        </Button>
      </div>

      <div aria-live="polite" className="min-h-6">
        {state.kind === 'done' && (
          <p className="text-brand-800">
            {text.added}{' '}
            <Link href={cartHref} className="font-semibold underline underline-offset-2">{text.viewCart}</Link>
          </p>
        )}
        {state.kind === 'error' && <p role="alert" className="text-danger">{state.message}</p>}
      </div>
    </form>
  )
}

