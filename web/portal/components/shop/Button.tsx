import Link from 'next/link'
import type { ComponentProps, ReactNode } from 'react'

type Variant = 'primary' | 'secondary' | 'accent' | 'ghost'
type Size = 'sm' | 'md' | 'lg'

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-brand-700 text-white shadow-soft hover:bg-brand-800 hover:shadow-lift',
  secondary: 'border border-line bg-surface text-ink hover:border-brand-600 hover:bg-brand-50',
  accent: 'bg-accent-400 text-night hover:bg-accent-300',
  ghost: 'text-brand-700 hover:bg-brand-50',
}
const SIZES: Record<Size, string> = { sm: 'px-4 py-2 text-sm', md: 'px-5 py-2.5 text-base', lg: 'px-7 py-3.5 text-lg' }

/** Class list shared by the button and the link styled as a button. */
export function buttonClass(variant: Variant = 'primary', size: Size = 'md', block = false): string {
  return `inline-flex items-center justify-center gap-2 rounded-full font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:pointer-events-none disabled:opacity-50 ${VARIANTS[variant]} ${SIZES[size]} ${block ? 'w-full' : ''}`
}

interface Shared {
  variant?: Variant
  size?: Size
  block?: boolean
  children: ReactNode
}

/** Shop button. Primary is white on brand-700 (AA). */
export function Button({ variant, size, block, className = '', type = 'button', ...rest }: Shared & ComponentProps<'button'>) {
  return <button type={type} className={`${buttonClass(variant, size, block)} ${className}`} {...rest} />
}

/** A link styled as a button. */
export function ButtonLink({ variant, size, block, className = '', ...rest }: Shared & ComponentProps<typeof Link>) {
  return <Link className={`${buttonClass(variant, size, block)} ${className}`} {...rest} />
}
