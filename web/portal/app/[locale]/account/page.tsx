import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { AccountView } from '../../../components/shop/AccountView'
import { getSettings } from '../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../lib/i18n'
import { privateMetadata } from '../../../lib/seo'

type Props = { params: Promise<{ locale: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale } = await params
  if (!isLocale(locale)) return {}

  return privateMetadata({ locale, path: '/account', siteName: (await getSettings(locale)).site_name, title: getMessages(locale).account.title })
}

export default async function AccountPage({ params }: Props) {
  const { locale } = await params
  if (!isLocale(locale)) notFound()

  const messages = getMessages(locale)

  return (
    <div className="mx-auto max-w-4xl px-4 py-10 sm:py-14">
      <h1 className="mb-8 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{messages.account.title}</h1>
      <AccountView text={messages.account} steps={messages.order.steps} orderPrefix={localePath(locale, '/order')} shopHref={localePath(locale, '/shop')} />
    </div>
  )
}
