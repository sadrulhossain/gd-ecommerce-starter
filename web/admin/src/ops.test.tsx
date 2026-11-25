import { describe, expect, it } from 'vitest'
import { modules } from './modules'
import { nextStatus } from './pages/Orders'
import { parseCredentials } from './pages/PaymentMethods'
import { couriersConfig, zonesConfig } from './pages/Delivery'
import { DOC_KINDS } from './pages/StockDocuments'
import { movementLabel } from './pages/Stock'
import { shopPages } from './router'

describe('order lifecycle', () => {
  it('moves one step at a time and stops at delivered or cancelled', () => {
    expect(nextStatus('pending')).toBe('confirmed')
    expect(nextStatus('confirmed')).toBe('packed')
    expect(nextStatus('packed')).toBe('shipped')
    expect(nextStatus('shipped')).toBe('delivered')
    expect(nextStatus('delivered')).toBeNull()
    expect(nextStatus('cancelled')).toBeNull()
    expect(nextStatus('nonsense')).toBeNull()
  })
})

describe('payment credentials text', () => {
  it('parses name=value lines, keeping = inside values', () => {
    expect(parseCredentials('api_key=abc\n secret = x=y \n')).toEqual({ api_key: 'abc', secret: 'x=y' })
    expect(parseCredentials('')).toEqual({})
  })

  it('refuses a line without a name or a value', () => {
    expect(parseCredentials('novalue')).toBeNull()
    expect(parseCredentials('=x')).toBeNull()
    expect(parseCredentials('a=')).toBeNull()
  })
})

describe('stock helpers', () => {
  it('labels ledger types', () => {
    expect(movementLabel('return_to_supplier')).toBe('Return to supplier')
    expect(movementLabel('count')).toBe('Count')
  })

  it('covers the four stock documents with the API paths and rules', () => {
    expect(DOC_KINDS.map((k) => k.key)).toEqual(['damages', 'supplier-returns', 'adjustments', 'stock-counts'])
    expect(DOC_KINDS.find((k) => k.key === 'supplier-returns')).toMatchObject({ supplier: true, reason: 'optional' })
    expect(DOC_KINDS.find((k) => k.key === 'stock-counts')?.reason).toBe('none')
    expect(DOC_KINDS.filter((k) => k.reason === 'required').map((k) => k.key)).toEqual(['damages', 'adjustments'])
  })
})

describe('delivery configs', () => {
  it('writes are gated by the single delivery.manage permission', () => {
    expect(zonesConfig).toMatchObject({ endpoint: '/v1/delivery-zones', manage: 'delivery.manage' })
    expect(couriersConfig).toMatchObject({ endpoint: '/v1/couriers', manage: 'delivery.manage' })
    expect(zonesConfig.fields.find((f) => f.name === 'shipping_fee')?.type).toBe('money')
  })
})

describe('ops routes', () => {
  const routes = Object.fromEntries(shopPages.map((p) => [p.path, p.permission]))

  it('every menu path of the stock, sales and delivery modules is routed behind its view permission', () => {
    expect(routes).toMatchObject({
      stock: 'stock.view',
      'goods-receipts': 'goods-receipts.view',
      damages: 'damages.view',
      'supplier-returns': 'supplier-returns.view',
      adjustments: 'adjustments.view',
      'stock-counts': 'stock-counts.view',
      orders: 'orders.view',
      delivery: 'delivery.view',
      'cod-remittances': 'cod-remittances.view',
      reports: 'reports.view',
      'payment-methods': 'payment-methods.view',
    })
  })

  it('has no duplicate paths and none that a CRUD module also owns', () => {
    const paths = shopPages.map((p) => p.path)
    expect(new Set(paths).size).toBe(paths.length)
    const taken = new Set(modules.map((m) => m.path.slice(1)))
    for (const p of paths) expect(taken.has(p), p).toBe(false)
  })
})
