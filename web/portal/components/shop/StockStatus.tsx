import { stockState, type StockState } from '../../lib/shop'

const TEXT: Record<StockState, string> = { in: 'In stock', low: 'Low stock', out: 'Out of stock' }
const DOT: Record<StockState, string> = { in: 'bg-brand-600', low: 'bg-accent-500', out: 'bg-danger' }

/** Availability as a dot and a word (never colour alone). Pass `onHand` to derive the state, or `state` directly. */
export function StockStatus({ onHand, state }: { onHand?: number | null; state?: StockState }) {
  const s = state ?? stockState(onHand)

  return (
    <span className="inline-flex items-center gap-2 text-sm text-ink">
      <span aria-hidden="true" className={`size-2 rounded-full ${DOT[s]}`} />
      {TEXT[s]}
    </span>
  )
}
