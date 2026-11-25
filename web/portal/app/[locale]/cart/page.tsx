import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { CartView } from '../../../components/shop/CartView'
import { getSettings } from '../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../lib/i18n'
import { pageMetadata } from '../../../lib/seo'

type Props = { params: Promise<{ locale: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale } = await params
  if (!isLocale(locale)) return {}
  const settings = await getSettings(locale)

  // A personal page: kept out of search results.
  return { ...pageMetadata({ locale, path: '/cart', siteName: settings.site_name, title: getMessages(locale).cart.title }), robots: { index: false, follow: true } }
}

/** The cart itself is read in the browser (the token is an httpOnly cookie), so this page stays static. */
export default async function CartPage({ params }: Props) {
  const { locale } = await params
  if (!isLocale(locale)) notFound()

  const t = getMessages(locale).cart

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
      <h1 className="mb-8 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{t.title}</h1>
      <CartView text={t} shopHref={localePath(locale, '/shop')} checkoutHref={localePath(locale, '/checkout')} />
    </div>
  )
}
