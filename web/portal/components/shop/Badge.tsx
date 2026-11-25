import type { ReactNode } from 'react'

const TONES = {
  neutral: 'bg-brand-50 text-brand-800',
  sale: 'bg-sale-soft text-sale',
  success: 'bg-brand-100 text-brand-900',
  warning: 'bg-accent-300 text-night',
  dark: 'bg-night text-white',
} as const

/** Small label: "New", "Sale", "Low stock". The text carries the meaning; colour only supports it. */
export function Badge({ tone = 'neutral', children }: { tone?: keyof typeof TONES; children: ReactNode }) {
  return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${TONES[tone]}`}>{children}</span>
}
