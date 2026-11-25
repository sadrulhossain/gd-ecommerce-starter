import { formatMoney, type Money } from '@gd/api-client'

export { formatMoney, type Money }

/** Whole-number percent off, or 0 when the compare-at price is not higher than the price (or the currencies differ). */
export function percentOff(price: Money, compareAt: Money | null | undefined): number {
  if (!compareAt || compareAt.currency !== price.currency || compareAt.amount <= price.amount || compareAt.amount <= 0) return 0

  return Math.round(((compareAt.amount - price.amount) / compareAt.amount) * 100)
}

/** Rating clamped to 0-5 and rounded to the nearest half. */
export function roundRating(rating: number): number {
  if (!Number.isFinite(rating)) return 0

  return Math.round(Math.min(5, Math.max(0, rating)) * 2) / 2
}

export type StockState = 'in' | 'low' | 'out'

/** In stock, low (at or below the threshold) or out. `onHand` null or undefined means "not tracked": treated as in stock. */
export function stockState(onHand: number | null | undefined, lowAt = 5): StockState {
  if (onHand === null || onHand === undefined) return 'in'
  if (onHand <= 0) return 'out'

  return onHand <= lowAt ? 'low' : 'in'
}

/** Keep a quantity a whole number inside min..max; NaN falls back to min. */
export function clampQuantity(value: number, min = 1, max = 99): number {
  if (!Number.isFinite(value)) return min

  return Math.min(max, Math.max(min, Math.trunc(value)))
}
