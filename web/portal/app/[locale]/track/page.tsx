import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { TrackForm } from '../../../components/shop/TrackForm'
import { getSettings } from '../../../lib/api'
import { getMessages, isLocale, localePath } from '../../../lib/i18n'
import { pageMetadata } from '../../../lib/seo'

type Props = { params: Promise<{ locale: string }>; searchParams: Promise<{ number?: string; token?: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale } = await params
  if (!isLocale(locale)) return {}
  const messages = getMessages(locale)

  return pageMetadata({ locale, path: '/track', siteName: (await getSettings(locale)).site_name, title: messages.track.title, description: messages.track.intro })
}

export default async function TrackPage({ params, searchParams }: Props) {
  const { locale } = await params
  if (!isLocale(locale)) notFound()

  const { number = '', token = '' } = await searchParams
  const t = getMessages(locale).track

  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:py-14">
      <h1 className="font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{t.title}</h1>
      <p className="mb-8 mt-2 text-ink-muted">{t.intro}</p>
      <TrackForm text={t} orderPrefix={localePath(locale, '/order')} initial={{ number: number.slice(0, 40), token: token.slice(0, 100) }} />
      <p className="mt-8">
        <Link href={localePath(locale, '/account')} className="font-semibold text-brand-700 underline underline-offset-2">{t.account}</Link>
      </p>
    </div>
  )
}
