/**
 * Money as the API sends it: integer minor units plus a currency code (BDT has 2 decimals).
 * This is the one place amounts are formatted or parsed; never use floats for stored values.
 */
export interface Money {
  amount: number
  currency: string
}

const DECIMALS = 2
const FACTOR = 10 ** DECIMALS

/** `{amount: 125000, currency: 'BDT'}` -> "BDT 1,250.00". Empty for null. */
export function formatMoney(money: Money | null | undefined): string {
  if (!money) return ''

  return new Intl.NumberFormat('en', { style: 'currency', currency: money.currency, currencyDisplay: 'code' }).format(money.amount / FACTOR)
}

/** Minor units -> the text of an input: 125000 -> "1250.00". */
export function fromMinor(amount: number): string {
  const sign = amount < 0 ? '-' : ''
  const abs = Math.abs(amount)

  return `${sign}${Math.floor(abs / FACTOR)}.${String(abs % FACTOR).padStart(DECIMALS, '0')}`
}

/** An input's text -> minor units: "12.5" -> 1250. Null when the text is not a non-negative amount with at most 2 decimals. */
export function toMinor(text: string): number | null {
  const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(text.trim())
  if (!match) return null

  return Number(match[1]) * FACTOR + Number((match[2] ?? '').padEnd(DECIMALS, '0'))
}
