import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { FormField } from './FormField'

interface ProductHit {
  name: string
  variants?: Array<{ id: number; sku: string; name: string | null }>
}

/**
 * Find a variant by SKU or product name (2+ characters), then pick it. `onChange` gets the variant id and a
 * readable label ("SKU - Product (Variant)"), so the caller can show the line without another request.
 */
export function VariantPicker({ value, onChange, error }: { value: unknown; onChange: (variantId: number | '', label: string) => void; error?: string }) {
  const [search, setSearch] = useState('')

  const hits = useQuery({
    queryKey: ['variant-search', search],
    enabled: search.trim().length >= 2,
    queryFn: async () => {
      const body = (await api.request('GET', '/v1/products', { query: { q: search.trim() } })) as { data: ProductHit[] }

      return body.data.flatMap((p) => (p.variants ?? []).map((v) => ({ value: v.id, label: `${v.sku} - ${p.name}${v.name ? ` (${v.name})` : ''}` })))
    },
  })

  return (
    <>
      <FormField label="Find item (SKU or name)" name="variant_search" value={search} onChange={(v) => setSearch(String(v))} />
      <FormField
        label="Item"
        name="variant_id"
        type="select"
        required
        options={hits.data}
        value={value}
        error={error}
        onChange={(v) => {
          const id = v === '' ? '' : Number(v)
          onChange(id, hits.data?.find((o) => o.value === id)?.label ?? '')
        }}
      />
    </>
  )
}
