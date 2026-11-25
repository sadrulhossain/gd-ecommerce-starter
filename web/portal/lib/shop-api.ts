import { ApiError } from '@gd/api-client'
import { apiFor, type Media } from './api'
import type { Money } from './shop'

export interface ShopCategory {
  id: number
  parent_id: number | null
  name: string
  slug: string
  description: string | null
  image: Media | null
  children?: ShopCategory[]
}

export interface ShopBrand {
  id: number
  name: string
  slug: string
}

export interface Variant {
  id: number
  sku: string
  name: string | null
  options: Record<string, string>
  price: Money
  compare_at_price: Money | null
  weight_grams: number | null
}

export interface ProductImage {
  id: number
  variant_id: number | null
  image: Media | null
  alt: string | null
  is_primary: boolean
}

export interface Product {
  id: number
  name: string
  slug: string
  short_description: string | null
  description: string | null
  has_variants: boolean
  seo_title: string | null
  seo_description: string | null
  category: ShopCategory | null
  brand: ShopBrand | null
  price_from: Money | null
  variants: Variant[]
  images: ProductImage[]
}

export interface ProductQuery {
  page?: number
  category?: string
  brand?: string
  q?: string
  sort?: 'newest' | 'price_asc' | 'price_desc' | 'name'
}

export interface ProductPage {
  products: Product[]
  page: number
  lastPage: number
  total: number
}

export const SORTS = ['newest', 'price_asc', 'price_desc', 'name'] as const

export async function getProducts(locale: string, query: ProductQuery = {}): Promise<ProductPage> {
  const body = (await apiFor(locale).request('GET', '/v1/public/products', { query: { ...query, per_page: 12 } })) as {
    data: Product[]
    meta?: { current_page: number; last_page: number; total: number }
  }

  return { products: body.data, page: body.meta?.current_page ?? 1, lastPage: body.meta?.last_page ?? 1, total: body.meta?.total ?? body.data.length }
}

/** One storefront product by slug; null when it is not on sale (the API answers 404). */
export async function getProduct(locale: string, slug: string): Promise<Product | null> {
  try {
    return ((await apiFor(locale).request('GET', `/v1/public/products/${encodeURIComponent(slug)}`)) as { data: Product }).data
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null
    throw error
  }
}

export async function getShopCategories(locale: string): Promise<ShopCategory[]> {
  return ((await apiFor(locale).request('GET', '/v1/public/product-categories')) as { data: ShopCategory[] }).data
}

/** Primary image first, then the rest in API order. */
export function orderedImages(product: Pick<Product, 'images'>): ProductImage[] {
  return [...product.images].filter((i) => i.image).sort((a, b) => Number(b.is_primary) - Number(a.is_primary))
}

/** Card data for a product: lowest-priced variant, its compare-at price, and "from" when variants differ in price. */
export function cardPrice(product: Product): { price: Money; compareAt: Money | null; from: boolean } | null {
  const sorted = [...product.variants].sort((a, b) => a.price.amount - b.price.amount)
  const cheapest = sorted[0]
  if (!cheapest) return product.price_from ? { price: product.price_from, compareAt: null, from: false } : null

  return { price: cheapest.price, compareAt: cheapest.compare_at_price, from: sorted.some((v) => v.price.amount !== cheapest.price.amount) }
}
