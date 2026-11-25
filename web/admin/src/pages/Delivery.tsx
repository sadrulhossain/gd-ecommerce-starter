import { useState } from 'react'
import { formatMoney, type Money } from '@gd/api-client'
import { CrudPage, type CrudConfig } from '../components/CrudPage'

const yesNo = (v: unknown) => (v ? 'Yes' : 'No')

/** Not in `modules` (one route, two lists); both use the single `delivery.manage` permission for writes. */
export const zonesConfig: CrudConfig = {
  key: 'delivery-zones',
  path: '/delivery',
  title: 'Delivery zones',
  noun: 'Delivery zone',
  endpoint: '/v1/delivery-zones',
  manage: 'delivery.manage',
  rowLabel: (r) => String(r.name),
  columns: [
    { key: 'name', header: 'Name' },
    { key: 'shipping_fee', header: 'Shipping fee', render: (r) => formatMoney(r.shipping_fee as Money) },
    { key: 'is_active', header: 'Active', render: (r) => yesNo(r.is_active) },
    { key: 'sort', header: 'Order' },
  ],
  fields: [
    { name: 'name', label: 'Name', required: true },
    { name: 'shipping_fee', label: 'Shipping fee', type: 'money', required: true },
    { name: 'sort', label: 'Sort order', type: 'number', initial: 0 },
    { name: 'is_active', label: 'Active', type: 'checkbox', initial: true },
  ],
}

export const couriersConfig: CrudConfig = {
  key: 'couriers',
  path: '/delivery',
  title: 'Couriers',
  noun: 'Courier',
  endpoint: '/v1/couriers',
  manage: 'delivery.manage',
  rowLabel: (r) => String(r.name),
  columns: [
    { key: 'name', header: 'Name' },
    { key: 'phone', header: 'Phone' },
    { key: 'is_active', header: 'Active', render: (r) => yesNo(r.is_active) },
  ],
  fields: [
    { name: 'name', label: 'Name', required: true },
    { name: 'phone', label: 'Phone' },
    { name: 'is_active', label: 'Active', type: 'checkbox', initial: true },
  ],
}

/** Delivery zones (shipping fee) and couriers on one screen. */
export function DeliveryPage() {
  const [tab, setTab] = useState<'zones' | 'couriers'>('zones')

  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Delivery" className="flex gap-2">
        {(['zones', 'couriers'] as const).map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'btn btn-primary' : 'btn btn-secondary'} onClick={() => setTab(t)}>
            {t === 'zones' ? 'Zones' : 'Couriers'}
          </button>
        ))}
      </div>
      {tab === 'zones' ? <CrudPage config={zonesConfig} /> : <CrudPage config={couriersConfig} />}
    </div>
  )
}
