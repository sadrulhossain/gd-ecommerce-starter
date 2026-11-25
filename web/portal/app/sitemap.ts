import type { MetadataRoute } from 'next'
import { getList, getPosts, getSections, type Service } from '../lib/api'
import { defaultLocale, localePath, locales } from '../lib/i18n'
import { SECTION_PAGES } from '../lib/section-page'
import { absolute } from '../lib/seo'
import { getProducts } from '../lib/shop-api'

/**
 * Every public page in every locale, each with its hreflang alternates. Pages that come from a section are listed only
 * while that section is visible. Built from the same cached, tagged fetches as the pages, so an admin edit refreshes it.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const sections = await getSections(defaultLocale)
  const services = sections.some((s) => s.key === 'services') ? await getList<Service>(defaultLocale, 'services') : []
  const posts = await allPosts()
  const products = await allProducts()

  const paths: { path: string; modified?: string }[] = [
    { path: '/' },
    ...sections.flatMap((s) => (SECTION_PAGES[s.key] ? [{ path: SECTION_PAGES[s.key]! }] : [])),
    { path: '/blog' },
    { path: '/shop' },
    { path: '/contact' },
    { path: '/privacy' },
    { path: '/terms' },
    ...services.map((s) => ({ path: `/services/${s.id}` })),
    ...products.map((p) => ({ path: `/shop/${p.slug}` })),
    ...posts.map((p) => ({ path: `/blog/${p.slug}`, modified: p.updated_at })),
  ]

  return paths.flatMap(({ path, modified }) =>
    locales.map((locale) => ({
      url: absolute(localePath(locale, path)),
      lastModified: modified ? new Date(modified) : undefined,
      alternates: { languages: Object.fromEntries(locales.map((code) => [code, absolute(localePath(code, path))])) },
    })),
  )
}

async function allPosts() {
  const first = await getPosts(defaultLocale)
  const rest = await Promise.all(Array.from({ length: Math.max(0, first.lastPage - 1) }, (_, i) => getPosts(defaultLocale, { page: i + 2 })))

  return [first, ...rest].flatMap((page) => page.posts)
}

async function allProducts() {
  const first = await getProducts(defaultLocale)
  const rest = await Promise.all(Array.from({ length: Math.max(0, first.lastPage - 1) }, (_, i) => getProducts(defaultLocale, { page: i + 2 })))

  return [first, ...rest].flatMap((page) => page.products)
}
