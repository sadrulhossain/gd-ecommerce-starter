import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { getSettings } from '../../../lib/api'
import { fill, getMessages, isLocale, localePath } from '../../../lib/i18n'
import { pageMetadata } from '../../../lib/seo'
import { cardPrice, getProducts, getShopCategories, orderedImages, SORTS, type ProductQuery, type ShopCategory } from '../../../lib/shop-api'
import { Button, EmptyState, ProductCard, ButtonLink } from '../../../components/shop'

type Props = { params: Promise<{ locale: string }>; searchParams: Promise<{ page?: string; category?: string; q?: string; sort?: string }> }

const slug = /^[a-z0-9-]+$/

/** `/shop?category=<slug>&q=<text>&sort=<key>&page=<n>`. Anything else in the query is ignored. */
function parse({ page, category, q, sort }: { page?: string; category?: string; q?: string; sort?: string }): ProductQuery & { page: number } {
  const n = Number.parseInt(page ?? '1', 10)

  return {
    page: Number.isFinite(n) && n > 0 ? n : 1,
    category: category && slug.test(category) ? category : undefined,
    q: q?.trim().slice(0, 100) || undefined,
    sort: (SORTS as readonly string[]).includes(sort ?? '') ? (sort as ProductQuery['sort']) : undefined,
  }
}

const href = (locale: string, query: ProductQuery) => {
  const params = new URLSearchParams()
  if (query.category) params.set('category', query.category)
  if (query.q) params.set('q', query.q)
  if (query.sort && query.sort !== 'newest') params.set('sort', query.sort)
  if (query.page && query.page > 1) params.set('page', String(query.page))

  return `${localePath(locale, '/shop')}${params.size ? `?${params}` : ''}`
}

/** Top-level categories and their children, flattened with a depth for indentation. */
function flatten(categories: ShopCategory[], depth = 0): Array<ShopCategory & { depth: number }> {
  return categories.flatMap((c) => [{ ...c, depth }, ...flatten(c.children ?? [], depth + 1)])
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { locale } = await params
  if (!isLocale(locale)) return {}
  const query = parse(await searchParams)
  const [settings, categories] = await Promise.all([getSettings(locale), getShopCategories(locale)])
  const messages = getMessages(locale)
  const name = flatten(categories).find((c) => c.slug === query.category)?.name

  // Filters, search and later pages canonicalise to the plain shop; the category (a landing page) keeps its own title.
  return pageMetadata({ locale, path: '/shop', siteName: settings.site_name, title: name ?? messages.shop.title, description: messages.shop.intro })
}

export default async function ShopPage({ params, searchParams }: Props) {
  const { locale } = await params
  if (!isLocale(locale)) notFound()

  const query = parse(await searchParams)
  const t = getMessages(locale).shop
  const [{ products, page, lastPage, total }, categories] = await Promise.all([getProducts(locale, query), getShopCategories(locale)])
  const filtered = Boolean(query.category || query.q)

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
      <h1 className="font-display text-3xl font-extrabold tracking-tight text-balance sm:text-4xl">{t.title}</h1>
      <p className="mt-2 text-ink-muted">{t.intro}</p>

      <div className="mt-8 grid gap-8 lg:grid-cols-[14rem_1fr]">
        <aside className="space-y-6">
          <form action={localePath(locale, '/shop')} role="search" aria-label={t.search} className="space-y-3">
            <label htmlFor="shop-q" className="text-sm font-semibold">{t.search}</label>
            <input id="shop-q" name="q" type="search" defaultValue={query.q} placeholder={t.searchPlaceholder} className="block w-full rounded-full border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600" />
            {query.category && <input type="hidden" name="category" value={query.category} />}
            <label htmlFor="shop-sort" className="block text-sm font-semibold">{t.sortLabel}</label>
            <select id="shop-sort" name="sort" defaultValue={query.sort ?? 'newest'} className="block w-full rounded-full border border-line bg-surface px-4 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-brand-600">
              {SORTS.map((s) => (
                <option key={s} value={s}>{t.sort[s]}</option>
              ))}
            </select>
            <Button type="submit" size="sm" variant="secondary">{t.apply}</Button>
          </form>

          {categories.length > 0 && (
            <nav aria-label={t.categories}>
              <h2 className="mb-2 text-sm font-semibold">{t.categories}</h2>
              <ul className="space-y-1">
                <li>
                  <Link href={href(locale, { sort: query.sort, q: query.q })} aria-current={!query.category ? 'true' : undefined} className={`block rounded-full px-4 py-1.5 ${!query.category ? 'bg-brand-700 text-white' : 'hover:bg-brand-50'}`}>
                    {t.all}
                  </Link>
                </li>
                {flatten(categories).map((c) => (
                  <li key={c.id} style={{ paddingInlineStart: `${c.depth * 0.75}rem` }}>
                    <Link href={href(locale, { category: c.slug, sort: query.sort })} aria-current={query.category === c.slug ? 'true' : undefined} className={`block rounded-full px-4 py-1.5 ${query.category === c.slug ? 'bg-brand-700 text-white' : 'hover:bg-brand-50'}`}>
                      {c.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          )}
        </aside>

        <section aria-label={t.title}>
          <p className="mb-4 text-sm text-ink-muted" aria-live="polite">{fill(t.results, { count: String(total) })}</p>
          {products.length === 0 ? (
            <EmptyState title={t.empty} action={filtered ? <ButtonLink href={localePath(locale, '/shop')} variant="secondary">{t.clear}</ButtonLink> : undefined} />
          ) : (
            <ul className="grid grid-cols-2 gap-4 xl:grid-cols-3">
              {products.map((p) => {
                const price = cardPrice(p)
                if (!price) return null

                return (
                  <li key={p.id}>
                    <ProductCard
                      product={{
                        name: p.name,
                        href: localePath(locale, `/shop/${p.slug}`),
                        image: orderedImages(p)[0]?.image,
                        price: price.price,
                        compareAt: price.compareAt,
                        priceFrom: price.from,
                        brand: p.brand?.name,
                      }}
                    />
                  </li>
                )
              })}
            </ul>
          )}

          {lastPage > 1 && (
            <nav aria-label={t.pagination} className="mt-10 flex items-center justify-center gap-4">
              {page > 1 ? <Link href={href(locale, { ...query, page: page - 1 })} rel="prev" className="rounded-full border border-line px-5 py-2 hover:bg-brand-50">{t.previous}</Link> : <span />}
              <span className="text-ink-muted">{fill(t.pageOf, { page: String(page), last: String(lastPage) })}</span>
              {page < lastPage ? <Link href={href(locale, { ...query, page: page + 1 })} rel="next" className="rounded-full border border-line px-5 py-2 hover:bg-brand-50">{t.next}</Link> : <span />}
            </nav>
          )}
        </section>
      </div>
    </div>
  )
}
