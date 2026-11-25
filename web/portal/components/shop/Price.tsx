import { formatMoney, percentOff, type Money } from '../../lib/shop'

/** Price with an optional struck-through compare-at price and a percent-off badge. The struck price is announced as "was". */
export function Price({ price, compareAt, from = false, size = 'md' }: { price: Money; compareAt?: Money | null; from?: boolean; size?: 'md' | 'lg' }) {
  const off = percentOff(price, compareAt)

  return (
    <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
      {from && <span className="text-sm text-ink-muted">From</span>}
      <span className={`font-display font-bold ${off ? 'text-sale' : 'text-ink'} ${size === 'lg' ? 'text-3xl' : 'text-xl'}`}>{formatMoney(price)}</span>
      {off > 0 && compareAt && (
        <>
          <span className="sr-only">was</span>
          <s className="text-sm text-ink-muted">{formatMoney(compareAt)}</s>
          <span className="rounded-full bg-sale-soft px-2 py-0.5 text-xs font-semibold text-sale">-{off}%</span>
        </>
      )}
    </p>
  )
}
