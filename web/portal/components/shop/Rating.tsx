import { roundRating } from '../../lib/shop'

const STAR = 'M12 2.5l2.94 6.1 6.56.9-4.8 4.6 1.2 6.6L12 17.5l-5.9 3.2 1.2-6.6-4.8-4.6 6.56-.9L12 2.5z'

/** Stars rounded to halves, with the number as text for assistive tech ("3.5 out of 5") and an optional review count. */
export function Rating({ value, count }: { value: number; count?: number }) {
  const rating = roundRating(value)

  return (
    <span className="inline-flex items-center gap-1.5 text-sm text-ink-muted">
      <span className="inline-flex" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((n) => {
          const half = rating === n - 0.5
          const fill = rating >= n ? 'var(--color-star)' : half ? 'url(#star-half)' : 'var(--color-star-empty)'

          return (
            <svg key={n} viewBox="0 0 24 24" className="size-4">
              {half && (
                <defs>
                  <linearGradient id="star-half" x1="0" x2="1" y1="0" y2="0">
                    <stop offset="50%" stopColor="var(--color-star)" />
                    <stop offset="50%" stopColor="var(--color-star-empty)" />
                  </linearGradient>
                </defs>
              )}
              <path d={STAR} fill={fill} />
            </svg>
          )
        })}
      </span>
      <span className="sr-only">{rating} out of 5</span>
      {count !== undefined && <span>({count})</span>}
    </span>
  )
}
