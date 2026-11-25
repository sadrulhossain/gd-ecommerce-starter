import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { DataTable } from '../components/DataTable'
import { Pager } from '../components/Pager'
import type { Listing } from '../types'

interface Level {
  variant_id: number
  sku: string
  name: string | null
  product: string | null
  on_hand: number
}

interface Movement {
  id: number
  sku: string | null
  type: string
  quantity: number
  balance_after: number
  unit_cost: Money | null
  note: string | null
  created_at: string
}

const MOVEMENT_TYPES = ['receipt', 'damage', 'return_to_supplier', 'adjustment', 'count', 'sale', 'sale_cancel']

/** Human label for a ledger type: "return_to_supplier" -> "Return to supplier". */
export function movementLabel(type: string): string {
  const text = type.replaceAll('_', ' ')

  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Read-only stock: what is on hand per variant, and the append-only ledger behind it. */
export function StockPage() {
  const [tab, setTab] = useState<'levels' | 'movements'>('levels')

  return (
    <section className="space-y-4">
      <h1 className="text-2xl font-semibold">Stock</h1>
      <div role="tablist" aria-label="Stock views" className="flex gap-2">
        {(['levels', 'movements'] as const).map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'btn btn-primary' : 'btn btn-secondary'} onClick={() => setTab(t)}>
            {t === 'levels' ? 'On hand' : 'Ledger'}
          </button>
        ))}
      </div>
      {tab === 'levels' ? <Levels /> : <Movements />}
    </section>
  )
}

function Levels() {
  const [page, setPage] = useState(1)
  const [q, setQ] = useState('')
  const [max, setMax] = useState('')

  const list = useQuery({
    queryKey: ['stock-levels', page, q, max],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/stock/levels', { query: { page, q: q || undefined, max: max || undefined } })) as Listing<Level>,
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <input type="search" className="field max-w-sm" placeholder="SKU or product" aria-label="Search stock" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
        <input type="number" className="field max-w-40" placeholder="On hand up to" aria-label="On hand up to" value={max} onChange={(e) => { setMax(e.target.value); setPage(1) }} />
      </div>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load stock.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'sku', header: 'SKU' },
            { key: 'product', header: 'Product', render: (l) => `${l.product ?? ''}${l.name ? ` (${l.name})` : ''}` },
            { key: 'on_hand', header: 'On hand', render: (l) => (l.on_hand <= 0 ? <span className="text-danger">{l.on_hand}</span> : l.on_hand) },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(l) => l.variant_id}
          loading={list.isPending}
          empty="No stock rows yet."
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
    </div>
  )
}

function Movements() {
  const [page, setPage] = useState(1)
  const [type, setType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const list = useQuery({
    queryKey: ['stock-movements', page, type, from, to],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/stock/movements', { query: { page, type: type || undefined, from: from || undefined, to: to || undefined } })) as Listing<Movement>,
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <select aria-label="Movement type" className="field max-w-xs" value={type} onChange={(e) => { setType(e.target.value); setPage(1) }}>
          <option value="">All types</option>
          {MOVEMENT_TYPES.map((t) => (
            <option key={t} value={t}>{movementLabel(t)}</option>
          ))}
        </select>
        <input type="date" className="field max-w-44" aria-label="From date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1) }} />
        <input type="date" className="field max-w-44" aria-label="To date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1) }} />
      </div>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load the ledger.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'created_at', header: 'When', render: (m) => new Date(m.created_at).toLocaleString() },
            { key: 'sku', header: 'SKU' },
            { key: 'type', header: 'Type', render: (m) => movementLabel(m.type) },
            { key: 'quantity', header: 'Change', render: (m) => (m.quantity > 0 ? `+${m.quantity}` : String(m.quantity)) },
            { key: 'balance_after', header: 'Balance' },
            { key: 'unit_cost', header: 'Unit cost', render: (m) => formatMoney(m.unit_cost) },
            { key: 'note', header: 'Note' },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(m) => m.id}
          loading={list.isPending}
          empty="No movements yet."
        />
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
    </div>
  )
}
