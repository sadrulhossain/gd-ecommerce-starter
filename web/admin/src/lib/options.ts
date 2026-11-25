import { useQuery } from '@tanstack/react-query'
import { api } from '../api'
import type { FieldOption } from '../components/FormField'

/** Select options from a non-paginated list endpoint (`{data: [...]}`), cached for a minute. */
export function useOptions(endpoint: string, label: (item: Record<string, unknown>) => string, query?: Record<string, string>) {
  return useQuery({
    queryKey: ['options', endpoint, query],
    staleTime: 60_000,
    queryFn: async (): Promise<FieldOption[]> => {
      const body = (await api.request('GET', endpoint, { query })) as { data: Array<Record<string, unknown> & { id: number }> }

      return body.data.map((item) => ({ value: item.id, label: label(item) }))
    },
  })
}
