import { notFound } from 'next/navigation'
import type { components } from '@gd/api-types'

import { Badge, Breadcrumbs, Button, ButtonLink, EmptyState, Price, ProductCard, Rating, StockStatus } from '../../../components/shop'
import { QuantityDemo } from './QuantityDemo'

export const metadata = { title: 'Portal theme' }

const bdt = (amount: number) => ({ amount, currency: 'BDT' })

// Full class names on purpose: Tailwind only generates utilities it can find as literal strings.
const brand = [
  ['50', 'bg-brand-50'],
  ['100', 'bg-brand-100'],
  ['200', 'bg-brand-200'],
  ['300', 'bg-brand-300'],
  ['400', 'bg-brand-400'],
  ['500', 'bg-brand-500'],
  ['600', 'bg-brand-600'],
  ['700', 'bg-brand-700'],
  ['800', 'bg-brand-800'],
  ['900', 'bg-brand-900'],
] as const

const semantic = [
  ['success', 'bg-success'],
  ['warning', 'bg-warning'],
  ['danger', 'bg-danger'],
  ['ink', 'bg-ink'],
  ['ink-muted', 'bg-ink-muted'],
  ['line', 'bg-line'],
] as const

// Typed against the generated API schema: renaming a field in the API breaks this at build time.
const faq: components['schemas']['FaqResource'] = {
  id: 1,
  question: 'How do I get in touch?',
  question_2nd: null,
  answer: 'Use the contact form and we will reply within one working day.',
  answer_2nd: null,
  sort_order: 10,
  is_visible: true,
  updated_at: '2026-11-12T00:00:00Z',
}

/** Portal theme showcase: tokens from @gd/tokens/portal.css, airy and warm. */
export default function ShowcasePage() {
  // A theme preview for development; not part of the public site.
  if (process.env.NODE_ENV === 'production') notFound()

  return (
    <main className="mx-auto max-w-4xl space-y-10 p-8">
      <header className="space-y-2">
        <h1 className="font-display text-4xl">Portal theme</h1>
        <p className="text-ink-muted">Tokens from @gd/tokens/portal.css: warm, airy, serif headings.</p>
      </header>

      <section className="space-y-3">
        <h2 className="font-display text-2xl">Brand</h2>
        <div className="grid grid-cols-5 gap-3 sm:grid-cols-10">
          {brand.map(([step, bg]) => (
            <div key={step} className="space-y-1 text-center text-sm">
              <div className={`${bg} h-14 rounded-md border border-line`} />
              {step}
            </div>
          ))}
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="font-display text-2xl">Semantic</h2>
        <div className="grid grid-cols-3 gap-3 sm:grid-cols-6">
          {semantic.map(([name, bg]) => (
            <div key={name} className="space-y-1 text-center text-sm">
              <div className={`${bg} h-14 rounded-md border border-line`} />
              {name}
            </div>
          ))}
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="font-display text-2xl">Type and radius</h2>
        <p className="font-display text-3xl">font-display: the quick brown fox</p>
        <p className="font-sans text-base">font-sans: the quick brown fox</p>
        <div className="flex gap-4">
          <div className="h-16 w-24 rounded-sm border border-line bg-surface p-2 text-sm">sm</div>
          <div className="h-16 w-24 rounded-md border border-line bg-surface p-2 text-sm">md</div>
          <div className="h-16 w-24 rounded-lg border border-line bg-surface p-2 text-sm">lg</div>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="font-display text-2xl">Buttons</h2>
        <div className="flex gap-3">
          <button className="rounded-md bg-brand-700 px-5 py-2.5 text-white hover:bg-brand-800">Primary</button>
          <button className="rounded-md border border-line bg-surface px-5 py-2.5 hover:bg-brand-50">Secondary</button>
        </div>
      </section>

      <section className="space-y-4">
        <h2 className="font-display text-2xl">Shop components (E11)</h2>
        <Breadcrumbs items={[{ label: 'Home', href: '/' }, { label: 'Shoes', href: '/' }, { label: 'Trail runner' }]} />
        <div className="flex flex-wrap items-center gap-3">
          <Button>Add to cart</Button>
          <Button variant="secondary">Wishlist</Button>
          <Button variant="accent">Buy now</Button>
          <Button variant="ghost">Details</Button>
          <Button disabled>Unavailable</Button>
          <ButtonLink href="/" size="sm">Link button</ButtonLink>
          <QuantityDemo />
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Badge>New</Badge>
          <Badge tone="sale">Sale</Badge>
          <Badge tone="success">Eco</Badge>
          <Badge tone="warning">Few left</Badge>
          <Badge tone="dark">Exclusive</Badge>
          <Rating value={4.5} count={128} />
          <Rating value={3} />
          <StockStatus onHand={40} />
          <StockStatus onHand={3} />
          <StockStatus onHand={0} />
        </div>
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <ProductCard product={{ name: 'Trail runner', href: '/', brand: 'Northline', price: bdt(450000), compareAt: bdt(600000), rating: 4.5, reviewCount: 128, isNew: true }} />
          <ProductCard product={{ name: 'Canvas tote', href: '/', price: bdt(120000), priceFrom: true, rating: 3.5, reviewCount: 12, stock: 'low' }} />
          <ProductCard product={{ name: 'Wool scarf', href: '/', brand: 'Fjord', price: bdt(89000), stock: 'out' }} />
          <ProductCard product={{ name: 'Water bottle', href: '/', price: bdt(65000) }} />
        </div>
        <Price price={bdt(450000)} compareAt={bdt(600000)} size="lg" />
        <EmptyState title="Your cart is empty" action={<ButtonLink href="/">Keep shopping</ButtonLink>}>
          Add something you like and it will show up here.
        </EmptyState>
      </section>

      <section className="space-y-3">
        <h2 className="font-display text-2xl">Typed API data</h2>
        <article className="rounded-lg border border-line bg-surface p-6">
          <h3 className="font-display text-xl">{faq.question}</h3>
          <p className="mt-2 text-ink-muted">{faq.answer}</p>
        </article>
      </section>
    </main>
  )
}
