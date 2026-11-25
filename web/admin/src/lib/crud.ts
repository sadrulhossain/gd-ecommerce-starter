import { ApiError } from '@gd/api-client'
import type { CrudField } from '../components/CrudPage'

/** Initial form values: each field's `initial`, a bilingual field's `_2nd` twin starts empty. */
export function blank(fields: CrudField[]): Record<string, unknown> {
  return Object.fromEntries(
    fields.flatMap((f) => {
      if (f.bilingual) return [[f.name, f.initial ?? ''], [`${f.name}_2nd`, '']]
      if (f.type === 'checkgroup') return [[f.name, f.initial ?? []]]

      return [[f.name, f.initial ?? (f.type === 'checkbox' ? false : '')]]
    }),
  )
}

/** Form values for a row being edited (or blanks for a new one): a money field's `{amount, currency}` becomes its minor-unit amount. */
export function editValues(fields: CrudField[], row: Record<string, unknown> | null): Record<string, unknown> {
  const values: Record<string, unknown> = { ...blank(fields), ...(row ?? {}) }
  for (const f of fields) {
    const v = values[f.name]
    if (f.type === 'money' && v !== null && typeof v === 'object') values[f.name] = (v as { amount: number }).amount
  }

  return values
}

/** Field values sent to the API: only configured fields (and their _2nd twins), `''` for optional text becomes null. */
export function payload(fields: CrudField[], values: Record<string, unknown>): Record<string, unknown> {
  const entries = fields.flatMap((f) =>
    (f.bilingual ? [f.name, `${f.name}_2nd`] : [f.name]).map((n) => ({ n, v: values[n] === '' ? null : values[n], type: f.type })),
  )

  // A blank number or amount is left out: the API takes absent, not null, for those.
  return Object.fromEntries(entries.filter((e) => !((e.type === 'number' || e.type === 'money') && e.v === null)).map((e) => [e.n, e.v]))
}

/** First message per field from a 422 response; empty for any other error. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || !error.details) return {}

  return Object.fromEntries(Object.entries(error.details).map(([name, messages]) => [name, messages[0] ?? '']))
}
