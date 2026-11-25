import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { IconButton } from '../components/IconButton'
import { toast } from '../components/Toast'
import type { Listing } from '../types'

interface Review {
  id: number
  product_id: number
  customer_id: number
  rating: number
  title: string | null
  body: string
  status: 'pending' | 'approved' | 'rejected'
}

const STATUS_LABEL = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' } as const

/** Review moderation: customers' reviews wait as pending until approved or rejected here. */
export function ReviewsPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('pending')
  const [deleting, setDeleting] = useState<Review | null>(null)

  const list = useQuery({
    queryKey: ['crud', '/v1/reviews', page, status],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/reviews', { query: { page, status: status || undefined } })) as Listing<Review>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', '/v1/reviews'] })

  const moderate = useMutation({
    mutationFn: ({ review, action }: { review: Review; action: 'approve' | 'reject' }) => api.request('POST', `/v1/reviews/${review.id}/${action}`),
    onSuccess: () => {
      toast.success('Review updated')
      void refresh()
    },
    onError: () => toast.error('Could not update the review'),
  })
  const remove = useMutation({
    mutationFn: (review: Review) => api.request('DELETE', `/v1/reviews/${review.id}`),
    onSuccess: () => {
      toast.success('Review deleted')
      setDeleting(null)
      void refresh()
    },
    onError: () => toast.error('Could not delete the review'),
  })

  const meta = list.data?.meta

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">Reviews</h1>
      </header>

      <select
        aria-label="Filter by status"
        className="field max-w-xs"
        value={status}
        onChange={(e) => {
          setStatus(e.target.value)
          setPage(1)
        }}
      >
        <option value="">All statuses</option>
        {Object.entries(STATUS_LABEL).map(([value, label]) => (
          <option key={value} value={value}>
            {label}
          </option>
        ))}
      </select>

      {list.isError ? (
        <p role="alert" className="text-danger">
          Could not load reviews.
        </p>
      ) : (
        <DataTable
          columns={[
            { key: 'rating', header: 'Rating', render: (r) => `${r.rating} / 5` },
            { key: 'review', header: 'Review', render: (r) => (r.title ? `${r.title}: ${r.body}` : r.body) },
            { key: 'product_id', header: 'Product', render: (r) => `#${r.product_id}` },
            { key: 'status', header: 'Status', render: (r) => STATUS_LABEL[r.status] },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(r) => r.id}
          loading={list.isPending}
          empty="No reviews here."
          actions={(r) => (
            <span className="inline-flex gap-2">
              {can('reviews.moderate') && r.status !== 'approved' && <IconButton variant="row" icon="check" label={`Approve review ${r.id}`} onClick={() => moderate.mutate({ review: r, action: 'approve' })} />}
              {can('reviews.moderate') && r.status !== 'rejected' && <IconButton variant="row" icon="x" label={`Reject review ${r.id}`} onClick={() => moderate.mutate({ review: r, action: 'reject' })} />}
              {can('reviews.delete') && <IconButton variant="row-danger" icon="trash" label={`Delete review ${r.id}`} onClick={() => setDeleting(r)} />}
            </span>
          )}
        />
      )}

      {meta && meta.last_page > 1 && (
        <nav className="flex items-center justify-end gap-3" aria-label="Pagination">
          <IconButton variant="secondary" icon="chevron-left" label="Previous" disabled={page <= 1} onClick={() => setPage(page - 1)} />
          <span className="text-ink-muted">
            {meta.current_page} / {meta.last_page}
          </span>
          <IconButton variant="secondary" icon="chevron-right" label="Next" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} />
        </nav>
      )}

      <ConfirmDialog
        open={deleting !== null}
        title="Delete review"
        message="Delete this review? This cannot be undone."
        confirmLabel="Delete"
        danger
        busy={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onCancel={() => setDeleting(null)}
      />
    </section>
  )
}
