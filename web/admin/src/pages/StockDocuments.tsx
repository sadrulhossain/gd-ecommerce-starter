import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog, Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { Pager } from '../components/Pager'
import { VariantPicker } from '../components/VariantPicker'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import { useOptions } from '../lib/options'
import type { Listing } from '../types'

export interface DocKind {
  /** API path and permission prefix: damages, supplier-returns, adjustments, stock-counts. */
  key: 'damages' | 'supplier-returns' | 'adjustments' | 'stock-counts'
  title: string
  noun: string
  /** Label of the per-line number: what it means differs by document. */
  quantityLabel: string
  quantityHint: string
  reason: 'required' | 'optional' | 'none'
  supplier: boolean
}

export const DOC_KINDS: DocKind[] = [
  { key: 'damages', title: 'Damages', noun: 'damage', quantityLabel: 'Quantity damaged', quantityHint: 'Units written off.', reason: 'required', supplier: false },
  { key: 'supplier-returns', title: 'Supplier returns', noun: 'supplier return', quantityLabel: 'Quantity returned', quantityHint: 'Units sent back to the supplier.', reason: 'optional', supplier: true },
  { key: 'adjustments', title: 'Adjustments', noun: 'adjustment', quantityLabel: 'Change', quantityHint: 'Signed: 5 adds five units, -3 removes three.', reason: 'required', supplier: false },
  { key: 'stock-counts', title: 'Stock counts', noun: 'stock count', quantityLabel: 'Counted quantity', quantityHint: 'What you counted. Posting adjusts stock to this number.', reason: 'none', supplier: false },
]

interface DocLine {
  id?: number
  variant_id: number
  sku?: string
  name?: string
  quantity: number
  expected_quantity?: number | null
  variance?: number | null
  unit_cost?: Money | null
}

interface Doc {
  id: number
  number: string
  status: 'draft' | 'posted'
  reason: string | null
  supplier?: { name: string } | null
  notes: string | null
  posted_at: string | null
  lines?: DocLine[]
}

function failure(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 422) return Object.values(error.details ?? {})[0]?.[0] ?? fallback

  return fallback
}

/** One screen for the four draft-then-post stock documents; posting writes the ledger and the document is then immutable. */
export function StockDocumentsPage({ kind }: { kind: DocKind }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const base = `/v1/${kind.key}`
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)

  const list = useQuery({
    queryKey: ['crud', base, page, status],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', base, { query: { page, status: status || undefined } })) as Listing<Doc>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', base] })

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">{kind.title}</h1>
        {can(`${kind.key}.create`) && <IconButton showText variant="primary" icon="plus" label={`New ${kind.noun}`} onClick={() => setCreating(true)} />}
      </header>
      <select aria-label="Filter by status" className="field max-w-xs" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="posted">Posted</option>
      </select>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load the list.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'number', header: 'Number' },
            { key: 'status', header: 'Status', render: (d) => (d.status === 'draft' ? 'Draft' : 'Posted') },
            { key: 'reason', header: 'Reason' },
            { key: 'supplier', header: 'Supplier', render: (d) => d.supplier?.name ?? '' },
            { key: 'posted_at', header: 'Posted', render: (d) => (d.posted_at ? new Date(d.posted_at).toLocaleDateString() : '') },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(d) => d.id}
          loading={list.isPending}
          actions={(d) => <IconButton variant="row" icon="eye" label={`Open ${d.number}`} onClick={() => setOpenId(d.id)} />}
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />

      <Modal size="xl" open={creating} title={`New ${kind.noun}`} onClose={() => setCreating(false)}>
        {creating && <NewDocument kind={kind} onCancel={() => setCreating(false)} onCreated={(id) => { setCreating(false); setOpenId(id); void refresh() }} />}
      </Modal>
      <Modal size="xl" open={openId !== null} title={kind.title} onClose={() => setOpenId(null)}>
        {openId !== null && <DocumentDetail kind={kind} id={openId} onChanged={() => void refresh()} onGone={() => { setOpenId(null); void refresh() }} />}
      </Modal>
    </section>
  )
}

function NewDocument({ kind, onCancel, onCreated }: { kind: DocKind; onCancel: () => void; onCreated: (id: number) => void }) {
  const suppliers = useOptions('/v1/suppliers', (s) => String(s.name))
  const [head, setHead] = useState({ reason: '', supplier_id: '', notes: '' })
  const [lines, setLines] = useState<Array<{ variant_id: number; label: string; quantity: number }>>([])
  const [pick, setPick] = useState<{ variant_id: number | ''; label: string; quantity: string }>({ variant_id: '', label: '', quantity: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const addLine = () => {
    const quantity = Number(pick.quantity)
    if (pick.variant_id === '' || pick.quantity === '' || !Number.isInteger(quantity)) return setErrors({ lines: 'Pick an item and enter a whole quantity.' })
    if (lines.some((l) => l.variant_id === pick.variant_id)) return setErrors({ lines: 'That item is already on the list.' })
    setErrors({})
    setLines([...lines, { variant_id: pick.variant_id, label: pick.label, quantity }])
    setPick({ variant_id: '', label: '', quantity: '' })
  }

  const create = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = { lines: lines.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity })), notes: head.notes || null }
      if (kind.reason !== 'none') body.reason = head.reason || null
      if (kind.supplier) body.supplier_id = head.supplier_id
      return (await api.request('POST', `/v1/${kind.key}`, { body })) as { data: { id: number } }
    },
    onSuccess: (body) => { toast.success('Draft created'); onCreated(body.data.id) },
    onError: (e) => { setErrors(fieldErrors(e)); toast.error(failure(e, 'Could not create the document')) },
  })

  return (
    <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); setErrors({}); create.mutate() }}>
      {kind.reason !== 'none' && <FormField label="Reason" name="reason" required={kind.reason === 'required'} value={head.reason} error={errors.reason} onChange={(v) => setHead({ ...head, reason: String(v) })} />}
      {kind.supplier && <FormField label="Supplier" name="supplier_id" type="select" required options={suppliers.data} value={head.supplier_id} error={errors.supplier_id} onChange={(v) => setHead({ ...head, supplier_id: String(v) })} />}
      <FormField label="Notes" name="notes" type="textarea" value={head.notes} error={errors.notes} onChange={(v) => setHead({ ...head, notes: String(v) })} />

      <fieldset className="space-y-3 rounded-lg border border-line p-3">
        <legend className="px-1 text-sm font-medium">Lines</legend>
        <DataTable
          columns={[{ key: 'label', header: 'Item' }, { key: 'quantity', header: kind.quantityLabel }]}
          rows={lines}
          rowKey={(l) => l.variant_id}
          empty="No lines yet."
          actions={(l) => <IconButton variant="row-danger" icon="trash" label={`Remove ${l.label}`} onClick={() => setLines(lines.filter((x) => x.variant_id !== l.variant_id))} />}
        />
        <VariantPicker value={pick.variant_id} onChange={(id, label) => setPick({ ...pick, variant_id: id, label })} />
        <FormField label={kind.quantityLabel} name="line_quantity" type="number" hint={kind.quantityHint} value={pick.quantity} onChange={(v) => setPick({ ...pick, quantity: v === null ? '' : String(v) })} />
        {(errors.lines || errors['lines.0.quantity'] || errors['lines.0.variant_id']) && <p role="alert" className="text-danger">{errors.lines ?? errors['lines.0.quantity'] ?? errors['lines.0.variant_id']}</p>}
        <div className="flex justify-end"><IconButton showText variant="secondary" icon="plus" label="Add line" onClick={addLine} /></div>
      </fieldset>

      <div className="flex justify-end gap-2 pt-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onCancel} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Create draft" disabled={create.isPending || lines.length === 0} />
      </div>
    </form>
  )
}

function DocumentDetail({ kind, id, onChanged, onGone }: { kind: DocKind; id: number; onChanged: () => void; onGone: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const key = ['stock-document', kind.key, id]
  const [confirming, setConfirming] = useState<'post' | 'delete' | null>(null)

  const doc = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/${kind.key}/${id}`)) as { data: Doc }).data })
  const act = useMutation({
    mutationFn: (action: 'post' | 'delete') => (action === 'post' ? api.request('POST', `/v1/${kind.key}/${id}/post`) : api.request('DELETE', `/v1/${kind.key}/${id}`)),
    onSuccess: (_d, action) => {
      toast.success(action === 'post' ? 'Posted to stock' : 'Draft deleted')
      setConfirming(null)
      if (action === 'delete') return onGone()
      void queryClient.invalidateQueries({ queryKey: key })
      onChanged()
    },
    onError: (e) => { setConfirming(null); toast.error(failure(e, 'Could not complete that')) },
  })

  const d = doc.data
  if (doc.isError) return <p role="alert" className="text-danger">Could not load the document.</p>
  if (!d) return <p className="text-ink-muted">Loading...</p>

  const counting = kind.key === 'stock-counts'

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-xl font-semibold">{d.number}</h3>
        <span className="text-ink-muted">{d.status === 'draft' ? 'Draft' : 'Posted'}{d.supplier ? ` - ${d.supplier.name}` : ''}{d.reason ? ` - ${d.reason}` : ''}</span>
      </header>
      {d.notes && <p className="text-ink-muted">{d.notes}</p>}
      <DataTable
        columns={[
          { key: 'sku', header: 'SKU' },
          { key: 'name', header: 'Item' },
          ...(counting ? [{ key: 'expected_quantity', header: 'Expected' }] : []),
          { key: 'quantity', header: kind.quantityLabel },
          ...(counting ? [{ key: 'variance', header: 'Variance' }] : []),
          { key: 'unit_cost', header: 'Unit cost', render: (l: DocLine) => formatMoney(l.unit_cost ?? null) },
        ]}
        rows={d.lines ?? []}
        rowKey={(l) => l.id ?? l.variant_id}
      />
      {d.status === 'draft' && (
        <div className="flex flex-wrap justify-end gap-2">
          {can(`${kind.key}.delete`) && <IconButton showText variant="danger" icon="trash" label="Delete draft" onClick={() => setConfirming('delete')} />}
          {can(`${kind.key}.post`) && <IconButton showText variant="primary" icon="check" label="Post" disabled={(d.lines ?? []).length === 0} onClick={() => setConfirming('post')} />}
        </div>
      )}
      <ConfirmDialog
        open={confirming !== null}
        title={confirming === 'post' ? 'Post document' : 'Delete draft'}
        message={confirming === 'post' ? `Post ${d.number}? It changes stock and cannot be edited afterwards; a mistake is corrected with a new document.` : `Delete draft ${d.number}?`}
        confirmLabel={confirming === 'post' ? 'Post' : 'Delete'}
        danger={confirming === 'delete'}
        busy={act.isPending}
        onConfirm={() => confirming && act.mutate(confirming)}
        onCancel={() => setConfirming(null)}
      />
    </div>
  )
}
