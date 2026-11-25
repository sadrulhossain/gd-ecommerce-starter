import { IconButton } from './IconButton'

/** Previous / next controls for a paginated list; renders nothing for a single page. */
export function Pager({ meta, page, onPage }: { meta?: { current_page: number; last_page: number }; page: number; onPage: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null

  return (
    <nav className="flex items-center justify-end gap-3" aria-label="Pagination">
      <IconButton variant="secondary" icon="chevron-left" label="Previous" disabled={page <= 1} onClick={() => onPage(page - 1)} />
      <span className="text-ink-muted">
        {meta.current_page} / {meta.last_page}
      </span>
      <IconButton variant="secondary" icon="chevron-right" label="Next" disabled={page >= meta.last_page} onClick={() => onPage(page + 1)} />
    </nav>
  )
}
