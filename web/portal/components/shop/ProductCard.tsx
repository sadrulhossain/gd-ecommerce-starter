import Link from 'next/link'
import type { Media } from '../../lib/api'
import { percentOff, type Money, type StockState } from '../../lib/shop'
import { Picture } from '../Media'
import { Badge } from './Badge'
import { Price } from './Price'
import { Rating } from './Rating'
import { StockStatus } from './StockStatus'

export interface ProductCardData {
  name: string
  href: string
  image?: Media | null
  price: Money
  compareAt?: Money | null
  /** True when the price is the lowest of several variants. */
  priceFrom?: boolean
  rating?: number
  reviewCount?: number
  brand?: string | null
  stock?: StockState
  isNew?: boolean
}

/** Product tile for lists and grids. The whole card is one link (the name), so it is a single tab stop. */
export function ProductCard({ product }: { product: ProductCardData }) {
  const off = percentOff(product.price, product.compareAt)

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-soft transition hover:-translate-y-1 hover:shadow-lift">
      <div className="relative aspect-square bg-brand-50">
        {product.image ? (
          <Picture image={product.image} sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw" className="size-full object-cover transition duration-500 group-hover:scale-105" />
        ) : (
          <span aria-hidden="true" className="grid size-full place-items-center font-display text-4xl text-brand-300">{product.name.charAt(0)}</span>
        )}
        <span className="absolute start-3 top-3 flex flex-col items-start gap-1">
          {off > 0 && <Badge tone="sale">-{off}%</Badge>}
          {product.isNew && <Badge tone="dark">New</Badge>}
        </span>
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        {product.brand && <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{product.brand}</p>}
        <h3 className="font-display text-lg font-semibold leading-snug">
          <Link href={product.href} className="after:absolute after:inset-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            {product.name}
          </Link>
        </h3>
        {product.rating !== undefined && <Rating value={product.rating} count={product.reviewCount} />}
        <div className="mt-auto space-y-2 pt-2">
          <Price price={product.price} compareAt={product.compareAt} from={product.priceFrom} />
          {product.stock && product.stock !== 'in' && <StockStatus state={product.stock} />}
        </div>
      </div>
    </article>
  )
}
