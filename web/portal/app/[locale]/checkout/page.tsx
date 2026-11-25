import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { CheckoutForm } from '../../../components/shop/CheckoutForm'
import { getSettings } from '../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../lib/i18n'
import { privateMetadata } from '../../../lib/seo'

type Props = { params: Promise<{ locale: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale } = await params
  if (!isLocale(locale)) return {}

  return privateMetadata({ locale, path: '/checkout', siteName: (await getSettings(locale)).site_name, title: getMessages(locale).checkout.title })
}

/** Everything personal is read in the browser (cart and session are httpOnly cookies), so the page itself stays static. */
export default async function CheckoutPage({ params }: Props) {
  const { locale } = await params
  if (!isLocale(locale)) notFound()

  const t = getMessages(locale).checkout

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
      <h1 className="mb-8 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{t.title}</h1>
      <CheckoutForm text={t} shopHref={localePath(locale, '/shop')} cartHref={localePath(locale, '/cart')} orderPrefix={localePath(locale, '/order')} />
    </div>
  )
}

