import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatMoney, fromMinor, toMinor } from '@gd/api-client'
import { FormField } from './components/FormField'
import { blank, editValues, payload } from './lib/crud'
import { optionsText, parseOptions } from './lib/variants'
import { modules } from './modules'
import { shopPages } from './router'

afterEach(cleanup)

const byKey = (key: string) => modules.find((m) => m.key === key)!

describe('money helpers', () => {
  it('formats API money with the currency code', () => {
    expect(formatMoney({ amount: 125000, currency: 'BDT' })).toMatch(/BDT.*1,250\.00/)
    expect(formatMoney(null)).toBe('')
  })

  it('converts between input text and minor units without floats', () => {
    expect(toMinor('12.5')).toBe(1250)
    expect(toMinor('0.07')).toBe(7)
    expect(toMinor('1250')).toBe(125000)
    expect(toMinor('1.005')).toBeNull()
    expect(toMinor('-3')).toBeNull()
    expect(toMinor('abc')).toBeNull()
    expect(fromMinor(1250)).toBe('12.50')
    expect(fromMinor(7)).toBe('0.07')
    expect(fromMinor(toMinor('19.99')!)).toBe('19.99')
  })
})

describe('money field', () => {
  it('stores minor units while the user types major units, and clears to null', () => {
    const onChange = vi.fn()
    render(<FormField label="Price" name="price" type="money" value="" onChange={onChange} />)
    const input = screen.getByLabelText('Price')

    fireEvent.change(input, { target: { value: '12.5' } })
    expect(onChange).toHaveBeenLastCalledWith(1250)
    fireEvent.change(input, { target: { value: '' } })
    expect(onChange).toHaveBeenLastCalledWith(null)
  })

  it('shows an existing amount in major units', () => {
    render(<FormField label="Price" name="price" type="money" value={125000} onChange={() => {}} />)

    expect(screen.getByLabelText('Price')).toHaveProperty('value', '1250.00')
  })

  it('edit values turn {amount, currency} into the amount; a blank amount is not sent', () => {
    const fields = byKey('coupons').fields
    const values = editValues(fields, { id: 1, min_subtotal: { amount: 5000, currency: 'BDT' }, max_discount: null, code: 'X' })

    expect(values.min_subtotal).toBe(5000)
    expect(values.code).toBe('X')

    const body = payload(fields, { ...blank(fields), ...values, max_discount: '' })
    expect(body).not.toHaveProperty('max_discount')
    expect(body.min_subtotal).toBe(5000)
  })
})

describe('variant options text', () => {
  it('parses name=value pairs', () => {
    expect(parseOptions('Color=Red, Size=M')).toEqual({ Color: 'Red', Size: 'M' })
    expect(parseOptions('')).toEqual({})
    expect(parseOptions('Color=Red=Dark')).toEqual({ Color: 'Red=Dark' })
  })

  it('rejects a part without a name or value', () => {
    expect(parseOptions('Red')).toBeNull()
    expect(parseOptions('Color=')).toBeNull()
    expect(parseOptions('=Red')).toBeNull()
  })

  it('round-trips', () => {
    expect(optionsText({ Color: 'Red', Size: 'M' })).toBe('Color=Red, Size=M')
    expect(parseOptions(optionsText({ Color: 'Red' }))).toEqual({ Color: 'Red' })
    expect(optionsText(null)).toBe('')
  })
})

describe('shop module configs', () => {
  const keys = ['product-categories', 'brands', 'suppliers', 'tax-rates', 'coupons']

  it('registers the plain CRUD shop modules on /v1 endpoints that match the API permissions', () => {
    for (const key of keys) {
      expect(byKey(key).endpoint, key).toBe(`/v1/${key}`)
      expect(byKey(key).path, key).toBe(`/${key}`)
    }
  })

  it('brands and categories take an image; others do not', () => {
    expect(byKey('brands').image).toBe(true)
    expect(byKey('product-categories').image).toBe(true)
    expect(byKey('suppliers').image).toBeUndefined()
  })

  it('English only: no bilingual fields in the shop modules', () => {
    for (const key of keys) expect(byKey(key).fields.some((f) => f.bilingual), key).toBe(false)
  })

  it('coupon amounts are money fields, the value stays a plain number', () => {
    const fields = byKey('coupons').fields
    expect(fields.find((f) => f.name === 'min_subtotal')?.type).toBe('money')
    expect(fields.find((f) => f.name === 'max_discount')?.type).toBe('money')
    expect(fields.find((f) => f.name === 'value')?.type).toBe('number')
  })

  it('coupon value column shows a percent or a money amount', () => {
    const render = byKey('coupons').columns.find((c) => c.key === 'value')!.render!
    expect(render({ id: 1, type: 'percent', value: 1000 })).toBe('10%')
    expect(String(render({ id: 2, type: 'fixed', value: 5000 }))).toMatch(/BDT.*50\.00/)
  })

  it('tax rate column shows basis points as a percent', () => {
    const render = byKey('tax-rates').columns.find((c) => c.key === 'rate_bp')!.render!
    expect(render({ id: 1, rate_bp: 1500 })).toBe('15%')
  })
})

describe('shop pages', () => {
  it('products, purchase orders and reviews are routed behind their view permission, once each', () => {
    expect(shopPages.map((p) => [p.path, p.permission]).slice(0, 3)).toEqual([
      ['products', 'products.view'],
      ['purchase-orders', 'purchase-orders.view'],
      ['reviews', 'reviews.view'],
    ])
  })

  it('page paths do not collide with CRUD module paths', () => {
    const taken = new Set(modules.map((m) => m.path.slice(1)))
    for (const page of shopPages) expect(taken.has(page.path), page.path).toBe(false)
  })
})
