import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { DataTable } from '../components/DataTable'

interface Sales {
  from: string
  to: string
  orders: number
  revenue: Money
  by_status: Record<string, number>
  days: Array<{ date: string; orders: number; revenue: Money }>
}

interface Cod {
  couriers: Array<{ courier_id: number; name: string; collected: Money; remitted_expected: Money; remitted_received: Money; difference: Money; outstanding: Money }>
  outstanding: Money
}

interface Valuation {
  variants: number
  units: number
  cost_value: Money
  retail_value: Money
}

const get = async <T,>(path: string, query?: Record<string, string>): Promise<T> => ((await api.request('GET', path, { query })) as { data: T }).data

/** Reports: sales by day, COD reconciliation per courier, and stock valuation. All read-only. */
export function ReportsPage() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const range = { ...(from ? { from } : {}), ...(to ? { to } : {}) }

  const sales = useQuery({ queryKey: ['report-sales', from, to], queryFn: () => get<Sales>('/v1/reports/sales', range) })
  const cod = useQuery({ queryKey: ['report-cod'], queryFn: () => get<Cod>('/v1/reports/cod-reconciliation') })
  const stock = useQuery({ queryKey: ['report-stock'], queryFn: () => get<Valuation>('/v1/reports/stock-valuation') })

  return (
    <section className="space-y-8">
      <h1 className="text-2xl font-semibold">Reports</h1>

      <div className="space-y-3">
        <h2 className="text-xl font-semibold">Sales</h2>
        <div className="flex flex-wrap gap-2">
          <input type="date" className="field max-w-44" aria-label="From date" value={from} onChange={(e) => setFrom(e.target.value)} />
          <input type="date" className="field max-w-44" aria-label="To date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        {sales.isError ? (
          <p role="alert" className="text-danger">Could not load the sales report.</p>
        ) : (
          sales.data && (
            <>
              <p>
                {sales.data.from} to {sales.data.to}: <strong>{sales.data.orders}</strong> orders, <strong>{formatMoney(sales.data.revenue)}</strong> revenue (cancelled orders excluded).
              </p>
              <p className="text-ink-muted">{Object.entries(sales.data.by_status).map(([s, n]) => `${s}: ${n}`).join(', ') || 'No orders in this range.'}</p>
              <DataTable
                columns={[
                  { key: 'date', header: 'Date' },
                  { key: 'orders', header: 'Orders' },
                  { key: 'revenue', header: 'Revenue', render: (d) => formatMoney(d.revenue) },
                ]}
                rows={sales.data.days}
                rowKey={(d) => d.date}
                empty="No sales in this range."
              />
            </>
          )
        )}
      </div>

      <div className="space-y-3">
        <h2 className="text-xl font-semibold">COD reconciliation</h2>
        {cod.isError ? (
          <p role="alert" className="text-danger">Could not load the COD report.</p>
        ) : (
          cod.data && (
            <>
              <p>Outstanding with couriers: <strong>{formatMoney(cod.data.outstanding)}</strong></p>
              <DataTable
                columns={[
                  { key: 'name', header: 'Courier' },
                  { key: 'collected', header: 'Collected', render: (c) => formatMoney(c.collected) },
                  { key: 'remitted_expected', header: 'Remitted (expected)', render: (c) => formatMoney(c.remitted_expected) },
                  { key: 'remitted_received', header: 'Remitted (received)', render: (c) => formatMoney(c.remitted_received) },
                  { key: 'difference', header: 'Difference', render: (c) => formatMoney(c.difference) },
                  { key: 'outstanding', header: 'Outstanding', render: (c) => formatMoney(c.outstanding) },
                ]}
                rows={cod.data.couriers}
                rowKey={(c) => c.courier_id}
                empty="No courier activity yet."
              />
            </>
          )
        )}
      </div>

      <div className="space-y-3">
        <h2 className="text-xl font-semibold">Stock valuation</h2>
        {stock.isError ? (
          <p role="alert" className="text-danger">Could not load the stock valuation.</p>
        ) : (
          stock.data && (
            <dl className="grid max-w-md grid-cols-2 gap-x-4">
              <dt className="text-ink-muted">Variants in stock</dt><dd>{stock.data.variants}</dd>
              <dt className="text-ink-muted">Units</dt><dd>{stock.data.units}</dd>
              <dt className="text-ink-muted">At cost</dt><dd>{formatMoney(stock.data.cost_value)}</dd>
              <dt className="text-ink-muted">At retail</dt><dd>{formatMoney(stock.data.retail_value)}</dd>
            </dl>
          )
        )}
      </div>
    </section>
  )
}
