import type { ReactNode } from 'react'

/** Empty list, empty cart, no results: a short message and one next step. */
export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="mx-auto max-w-md rounded-lg border border-dashed border-line bg-surface p-10 text-center">
      <h2 className="font-display text-2xl font-semibold">{title}</h2>
      {children && <p className="mt-2 text-ink-muted">{children}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  )
}
