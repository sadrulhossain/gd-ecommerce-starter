import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { JsonLd } from '../../../../components/JsonLd'
import { Picture } from '../../../../components/Media'
import { AddToCart } from '../../../../components/shop/AddToCart'
import { Breadcrumbs } from '../../../../components/shop'
import { getSettings } from '../../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../../lib/i18n'
import { pageMetadata, productJsonLd } from '../../../../lib/seo'
import { getProduct, orderedImages } from '../../../../lib/shop-api'

type Props = { params: Promise<{ locale: string; slug: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, slug } = await params
  if (!isLocale(locale)) return {}
  const [product, settings] = await Promise.all([getProduct(locale, slug), getSettings(locale)])
  if (!product) return {}

  return pageMetadata({
    locale,
    path: `/shop/${product.slug}`,
    siteName: settings.site_name,
    title: product.seo_title || product.name,
    description: product.seo_description || product.short_description || undefined,
    image: orderedImages(product)[0]?.image?.url,
  })
}

export default async function ProductPage({ params }: Props) {
  const { locale, slug } = await params
  if (!isLocale(locale)) notFound()

  const [product, settings] = await Promise.all([getProduct(locale, slug), getSettings(locale)])
  if (!product || product.variants.length === 0) notFound()

  const messages = getMessages(locale)
  const t = messages.shop
  const images = orderedImages(product)
  const [main, ...rest] = images
  const crumbs = [
    { label: messages.nav.home, href: localePath(locale) },
    { label: t.title, href: localePath(locale, '/shop') },
    ...(product.category ? [{ label: product.category.name, href: `${localePath(locale, '/shop')}?category=${product.category.slug}` }] : []),
    { label: product.name },
  ]

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
      <JsonLd data={productJsonLd(product, settings, locale)} />
      <Breadcrumbs items={crumbs} />

      <div className="mt-6 grid gap-10 lg:grid-cols-2">
        <div className="space-y-3">
          {main?.image ? (
            <Picture image={main.image} alt={main.alt ?? product.name} loading="eager" sizes="(min-width: 1024px) 50vw, 100vw" className="aspect-square w-full rounded-lg border border-line bg-brand-50 object-cover" />
          ) : (
            <div aria-hidden="true" className="grid aspect-square w-full place-items-center rounded-lg bg-brand-50 font-display text-7xl text-brand-300">{product.name.charAt(0)}</div>
          )}
          {rest.length > 0 && (
            <ul className="grid grid-cols-4 gap-3">
              {rest.slice(0, 8).map((img) => (
                <li key={img.id}>
                  <Picture image={img.image!} alt={img.alt ?? ''} sizes="12vw" className="aspect-square w-full rounded-md border border-line bg-brand-50 object-cover" />
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="space-y-6">
          {product.brand && <p className="text-sm font-semibold uppercase tracking-wide text-ink-muted">{product.brand.name}</p>}
          <h1 className="font-display text-3xl font-extrabold tracking-tight text-balance sm:text-4xl">{product.name}</h1>
          {product.short_description && <p className="text-lg text-ink-muted">{product.short_description}</p>}

          <AddToCart
            variants={product.variants}
            cartHref={localePath(locale, '/cart')}
            text={{ sku: t.sku, chooseOption: t.chooseOption, quantity: t.quantity, addToCart: t.addToCart, adding: t.adding, added: t.added, viewCart: t.viewCart, unavailableVariant: t.unavailableVariant }}
          />

          {product.description && (
            <section aria-labelledby="product-description" className="border-t border-line pt-6">
              <h2 id="product-description" className="font-display text-xl font-semibold">{t.description}</h2>
              <div className="mt-3 whitespace-pre-line text-ink-muted">{product.description}</div>
            </section>
          )}
        </div>
      </div>
    </div>
  )
}
