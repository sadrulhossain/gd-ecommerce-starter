import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'

interface Method {
  id: number
  code: string
  name: string
  is_enabled: boolean
  sort: number
  credential_keys: string[]
  driver_registered: boolean
}

/** "key=value" lines to an object; null when a line has no key or value. Empty text gives {}. */
export function parseCredentials(text: string): Record<string, string> | null {
  const out: Record<string, string> = {}
  for (const line of text.split('\n').map((l) => l.trim()).filter(Boolean)) {
    const at = line.indexOf('=')
    if (at < 1 || at === line.length - 1) return null
    out[line.slice(0, at).trim()] = line.slice(at + 1).trim()
  }

  return out
}

/** Payment methods shown at checkout. Credentials are write-only: only their names come back. */
export function PaymentMethodsPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [editing, setEditing] = useState<Method | 'new' | null>(null)

  const list = useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => (await api.request('GET', '/v1/payment-methods')) as { data: Method[]; meta: { drivers: string[] } },
  })

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">Payment methods</h1>
        {can('payment-methods.create') && <IconButton showText variant="primary" icon="plus" label="Add payment method" onClick={() => setEditing('new')} />}
      </header>
      {list.isError ? (
        <p role="alert" className="text-danger">Could not load payment methods.</p>
      ) : (
        <DataTable
          columns={[
            { key: 'name', header: 'Name' },
            { key: 'code', header: 'Driver', render: (m) => (m.driver_registered ? m.code : `${m.code} (no driver)`) },
            { key: 'is_enabled', header: 'Enabled', render: (m) => (m.is_enabled ? 'Yes' : 'No') },
            { key: 'credential_keys', header: 'Credentials set', render: (m) => m.credential_keys.join(', ') || 'None' },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(m) => m.id}
          loading={list.isPending}
          actions={(m) => can('payment-methods.update') && <IconButton variant="row" icon="pencil" label={`Edit ${m.name}`} onClick={() => setEditing(m)} />}
        />
      )}
      <Modal open={editing !== null} title={editing === 'new' ? 'Add payment method' : 'Edit payment method'} onClose={() => setEditing(null)}>
        {editing !== null && (
          <MethodForm
            method={editing === 'new' ? null : editing}
            drivers={(list.data?.meta.drivers ?? []).filter((d) => !list.data?.data.some((m) => m.code === d))}
            onDone={() => {
              setEditing(null)
              void queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
            }}
          />
        )}
      </Modal>
    </section>
  )
}

function MethodForm({ method, drivers, onDone }: { method: Method | null; drivers: string[]; onDone: () => void }) {
  const [values, setValues] = useState({ code: '', name: method?.name ?? '', is_enabled: method?.is_enabled ?? true, sort: method?.sort ?? 0, credentials: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [replace, setReplace] = useState(method === null)

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { name: values.name, is_enabled: values.is_enabled, sort: Number(values.sort) || 0 }
      if (method === null) body.code = values.code
      if (replace) body.credentials = parseCredentials(values.credentials)

      return method === null ? api.request('POST', '/v1/payment-methods', { body }) : api.request('PATCH', `/v1/payment-methods/${method.id}`, { body })
    },
    onSuccess: () => {
      toast.success('Payment method saved')
      onDone()
    },
    onError: (error) => {
      setErrors(fieldErrors(error))
      if (!(error instanceof ApiError && error.status === 422)) toast.error('Could not save the payment method')
    },
  })

  const submit = () => {
    setErrors({})
    if (replace && parseCredentials(values.credentials) === null) return setErrors({ credentials: 'Use one name=value per line.' })
    save.mutate()
  }

  return (
    <form className="space-y-3" noValidate onSubmit={(e) => { e.preventDefault(); submit() }}>
      {method === null && (
        <FormField label="Driver" name="code" type="select" required options={drivers.map((d) => ({ value: d, label: d }))} value={values.code} error={errors.code} onChange={(v) => setValues({ ...values, code: String(v) })} />
      )}
      <FormField label="Name" name="name" required value={values.name} error={errors.name} onChange={(v) => setValues({ ...values, name: String(v) })} />
      <FormField label="Sort order" name="sort" type="number" value={values.sort} error={errors.sort} onChange={(v) => setValues({ ...values, sort: Number(v) || 0 })} />
      <FormField label="Enabled" name="is_enabled" type="checkbox" value={values.is_enabled} onChange={(v) => setValues({ ...values, is_enabled: Boolean(v) })} />
      {method !== null && (
        <FormField label="Replace credentials" name="replace" type="checkbox" hint={`Stored values are never shown. Set now: ${method.credential_keys.join(', ') || 'none'}.`} value={replace} onChange={(v) => setReplace(Boolean(v))} />
      )}
      {replace && (
        <FormField label="Credentials" name="credentials" type="textarea" hint="One name=value per line. Empty clears them." value={values.credentials} error={errors.credentials} onChange={(v) => setValues({ ...values, credentials: String(v) })} />
      )}
      <div className="flex justify-end gap-2 pt-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onDone} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Save" disabled={save.isPending} />
      </div>
    </form>
  )
}
