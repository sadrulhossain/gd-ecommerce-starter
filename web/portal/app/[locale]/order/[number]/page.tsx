import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { OrderView } from '../../../../components/shop/OrderView'
import { getSettings } from '../../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../../lib/i18n'
import { privateMetadata } from '../../../../lib/seo'

type Props = { params: Promise<{ locale: string; number: string }>; searchParams: Promise<{ token?: string; placed?: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, number } = await params
  if (!isLocale(locale)) return {}
  const t = getMessages(locale).order

  // The canonical never carries the tracking code.
  return privateMetadata({ locale, path: `/order/${number}`, siteName: (await getSettings(locale)).site_name, title: t.title.replace('{number}', number) })
}

/** Confirmation and tracking. The order is read in the browser: with the tracking code for a guest, or the session for a signed-in customer. */
export default async function OrderPage({ params, searchParams }: Props) {
  const { locale, number } = await params
  if (!isLocale(locale)) notFound()

  const { token, placed } = await searchParams
  const messages = getMessages(locale)
  const { steps, payments, ...t } = messages.order

  return (
    <div className="mx-auto max-w-4xl px-4 py-10 sm:py-14">
      <h1 className="mb-8 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{t.title.replace('{number}', number)}</h1>
      <OrderView
        number={number}
        token={token ? token.slice(0, 100) : null}
        justPlaced={placed === '1'}
        text={t}
        steps={steps}
        payments={payments}
        trackHref={localePath(locale, '/track')}
        accountHref={localePath(locale, '/account')}
        shopHref={localePath(locale, '/shop')}
      />
    </div>
  )
}
