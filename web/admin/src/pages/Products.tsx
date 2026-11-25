import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ApiError, formatMoney, type Money } from '@gd/api-client'
import { api } from '../api'
import { useAuth } from '../auth/store'
import { ConfirmDialog, Modal } from '../components/ConfirmDialog'
import { DataTable } from '../components/DataTable'
import { FormField } from '../components/FormField'
import { IconButton } from '../components/IconButton'
import { toast } from '../components/Toast'
import { fieldErrors } from '../lib/crud'
import { useOptions } from '../lib/options'
import { optionsText, parseOptions } from '../lib/variants'
import type { Listing } from '../types'

interface Variant {
  id: number
  sku: string
  name: string | null
  options: Record<string, string>
  price: Money
  compare_at_price: Money | null
  cost_price: Money | null
  is_active: boolean
}

interface Product {
  id: number
  name: string
  slug: string
  status: 'draft' | 'active' | 'archived'
  category_id: number
  category?: { name: string } | null
  brand_id: number | null
  tax_rate_id: number | null
  short_description: string | null
  description: string | null
  has_variants: boolean
  variants?: Variant[]
}

const STATUS_LABEL = { draft: 'Draft', active: 'Active', archived: 'Archived' } as const

/** "from 10.00" over the active variants, or the single price. */
function priceRange(product: Product): string {
  const prices = (product.variants ?? []).filter((v) => v.is_active).map((v) => v.price)
  if (prices.length === 0) return ''
  const lowest = prices.reduce((a, b) => (b.amount < a.amount ? b : a))

  return prices.length > 1 ? `from ${formatMoney(lowest)}` : formatMoney(lowest)
}

function firstMessage(error: ApiError): string {
  return Object.values(error.details ?? {})[0]?.[0] ?? error.message
}

class OptionsError extends Error {}

/** Products: list, create (with a first variant), edit with the variants editor, and the publish workflow. */
export function ProductsPage() {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Product | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Product | null>(null)

  const list = useQuery({
    queryKey: ['crud', '/v1/products', page, search],
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.request('GET', '/v1/products', { query: { page, q: search || undefined } })) as Listing<Product>,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['crud', '/v1/products'] })

  const workflow = useMutation({
    mutationFn: ({ product, action }: { product: Product; action: 'publish' | 'unpublish' | 'archive' }) => api.request('POST', `/v1/products/${product.id}/${action}`),
    onSuccess: () => {
      toast.success('Status updated')
      void refresh()
    },
    onError: (error) => toast.error(error instanceof ApiError && error.status === 422 ? firstMessage(error) : 'Could not change the status'),
  })
  const remove = useMutation({
    mutationFn: (product: Product) => api.request('DELETE', `/v1/products/${product.id}`),
    onSuccess: () => {
      toast.success('Product deleted')
      setDeleting(null)
      void refresh()
    },
    onError: () => toast.error('Could not delete the product'),
  })

  const meta = list.data?.meta

  return (
    <section className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">Products</h1>
        {can('products.create') && <IconButton showText variant="primary" icon="plus" label="New product" onClick={() => setEditing('new')} />}
      </header>

      <input
        type="search"
        className="field max-w-sm"
        placeholder="Search by name or SKU"
        aria-label="Search products"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value)
          setPage(1)
        }}
      />

      {list.isError ? (
        <p role="alert" className="text-danger">
          Could not load products.
        </p>
      ) : (
        <DataTable
          columns={[
            { key: 'name', header: 'Name' },
            { key: 'category', header: 'Category', render: (p) => p.category?.name ?? '' },
            { key: 'price', header: 'Price', render: priceRange },
            { key: 'variants', header: 'Variants', render: (p) => String(p.variants?.length ?? 0) },
            { key: 'status', header: 'Status', render: (p) => STATUS_LABEL[p.status] },
          ]}
          rows={list.data?.data ?? []}
          rowKey={(p) => p.id}
          loading={list.isPending}
          actions={(p) => (
            <span className="inline-flex gap-2">
              {can('products.publish') && p.status === 'draft' && <IconButton variant="row" icon="eye" label={`Publish ${p.name}`} onClick={() => workflow.mutate({ product: p, action: 'publish' })} />}
              {can('products.publish') && p.status === 'archived' && <IconButton variant="row" icon="eye" label={`Publish ${p.name}`} onClick={() => workflow.mutate({ product: p, action: 'publish' })} />}
              {can('products.publish') && p.status === 'active' && <IconButton variant="row" icon="x" label={`Unpublish ${p.name}`} onClick={() => workflow.mutate({ product: p, action: 'unpublish' })} />}
              {can('products.publish') && p.status !== 'archived' && <IconButton variant="row" icon="folder" label={`Archive ${p.name}`} onClick={() => workflow.mutate({ product: p, action: 'archive' })} />}
              {can('products.update') && <IconButton variant="row" icon="pencil" label={`Edit ${p.name}`} onClick={() => setEditing(p)} />}
              {can('products.delete') && <IconButton variant="row-danger" icon="trash" label={`Delete ${p.name}`} onClick={() => setDeleting(p)} />}
            </span>
          )}
        />
      )}

      {meta && meta.last_page > 1 && (
        <nav className="flex items-center justify-end gap-3" aria-label="Pagination">
          <IconButton variant="secondary" icon="chevron-left" label="Previous" disabled={page <= 1} onClick={() => setPage(page - 1)} />
          <span className="text-ink-muted">
            {meta.current_page} / {meta.last_page}
          </span>
          <IconButton variant="secondary" icon="chevron-right" label="Next" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} />
        </nav>
      )}

      <Modal size="xl" open={editing !== null} title={editing === 'new' ? 'New product' : 'Edit product'} onClose={() => setEditing(null)}>
        {editing !== null && (
          <ProductForm
            key={editing === 'new' ? 'new' : editing.id}
            product={editing === 'new' ? null : editing}
            onDone={() => {
              setEditing(null)
              void refresh()
            }}
            onCancel={() => setEditing(null)}
            onVariantsChanged={() => void refresh()}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        title="Delete product"
        message={`Delete "${deleting?.name ?? ''}"? This cannot be undone.`}
        confirmLabel="Delete"
        danger
        busy={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onCancel={() => setDeleting(null)}
      />
    </section>
  )
}

function ProductForm({ product, onDone, onCancel, onVariantsChanged }: { product: Product | null; onDone: () => void; onCancel: () => void; onVariantsChanged: () => void }) {
  const categories = useOptions('/v1/product-categories', (c) => String(c.name))
  const brands = useOptions('/v1/brands', (b) => String(b.name))
  const taxRates = useOptions('/v1/tax-rates', (t) => `${String(t.name)} (${Number(t.rate_bp) / 100}%)`)
  const [values, setValues] = useState<Record<string, unknown>>({
    name: product?.name ?? '',
    category_id: product?.category_id ?? '',
    brand_id: product?.brand_id ?? '',
    tax_rate_id: product?.tax_rate_id ?? '',
    short_description: product?.short_description ?? '',
    description: product?.description ?? '',
    sku: '',
    price: '',
    cost_price: '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const set = (name: string, value: unknown) => setValues((v) => ({ ...v, [name]: value }))
  const blankToNull = (v: unknown) => (v === '' ? null : v)

  const save = useMutation({
    mutationFn: () => {
      const base = {
        name: values.name,
        category_id: values.category_id,
        brand_id: blankToNull(values.brand_id),
        tax_rate_id: blankToNull(values.tax_rate_id),
        short_description: blankToNull(values.short_description),
        description: blankToNull(values.description),
      }
      if (product) return api.request('PATCH', `/v1/products/${product.id}`, { body: base })

      const variant: Record<string, unknown> = { sku: values.sku, price: values.price }
      if (values.cost_price !== '' && values.cost_price !== null) variant.cost_price = values.cost_price

      return api.request('POST', '/v1/products', { body: { ...base, has_variants: false, variants: [variant] } })
    },
    onSuccess: () => {
      toast.success('Product saved')
      onDone()
    },
    onError: (error) => {
      // A new product's variant errors come back as variants.0.sku; show them on the form's own fields.
      const raw = fieldErrors(error)
      setErrors({ ...raw, sku: raw['variants.0.sku'] ?? '', price: raw['variants.0.price'] ?? '', cost_price: raw['variants.0.cost_price'] ?? '' })
      toast.error(error instanceof ApiError && error.status === 422 ? 'Please fix the highlighted fields' : 'Could not save the product')
    },
  })

  const text = (name: string, label: string, extra: { required?: boolean; type?: 'textarea' | 'money'; hint?: string } = {}) => (
    <FormField label={label} name={name} type={extra.type} required={extra.required} hint={extra.hint} value={values[name]} error={errors[name] || undefined} onChange={(v) => set(name, v)} />
  )

  return (
    <div className="space-y-4">
      <form
        className="space-y-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          setErrors({})
          save.mutate()
        }}
      >
        {text('name', 'Name', { required: true })}
        <FormField label="Category" name="category_id" type="select" required options={categories.data} value={values.category_id} error={errors.category_id} onChange={(v) => set('category_id', v)} />
        <FormField label="Brand" name="brand_id" type="select" options={brands.data} value={values.brand_id} error={errors.brand_id} onChange={(v) => set('brand_id', v)} />
        <FormField label="Tax rate" name="tax_rate_id" type="select" options={taxRates.data} hint="Empty uses the default tax rate." value={values.tax_rate_id} error={errors.tax_rate_id} onChange={(v) => set('tax_rate_id', v)} />
        {text('short_description', 'Short description', { type: 'textarea' })}
        {text('description', 'Description', { type: 'textarea' })}
        {!product && (
          <fieldset className="space-y-3 rounded-lg border border-line p-3">
            <legend className="px-1 text-sm text-ink-muted">First variant</legend>
            {text('sku', 'SKU', { required: true })}
            {text('price', 'Price', { required: true, type: 'money', hint: 'In BDT, for example 1250.00.' })}
            {text('cost_price', 'Cost price', { type: 'money' })}
            <p className="text-sm text-ink-muted">Save the product, then add more variants (sizes, colours) from its edit screen.</p>
          </fieldset>
        )}
        <div className="flex justify-end gap-2 pt-2">
          <IconButton showText variant="secondary" icon="x" label="Cancel" onClick={onCancel} />
          <IconButton showText type="submit" variant="primary" icon="save" label="Save" disabled={save.isPending} />
        </div>
      </form>
      {product && <VariantsPanel productId={product.id} onChanged={onVariantsChanged} />}
    </div>
  )
}

function VariantsPanel({ productId, onChanged }: { productId: number; onChanged: () => void }) {
  const queryClient = useQueryClient()
  const can = useAuth((s) => s.can)
  const [editing, setEditing] = useState<Variant | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Variant | null>(null)
  const key = ['variants', productId]

  const list = useQuery({ queryKey: key, queryFn: async () => ((await api.request('GET', `/v1/products/${productId}/variants`)) as { data: Variant[] }).data })
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    onChanged()
  }
  const remove = useMutation({
    mutationFn: (variant: Variant) => api.request('DELETE', `/v1/variants/${variant.id}`),
    onSuccess: () => {
      toast.success('Variant deleted')
      setDeleting(null)
      refresh()
    },
    onError: (error) => {
      setDeleting(null)
      toast.error(error instanceof ApiError && error.status === 422 ? firstMessage(error) : 'Could not delete the variant')
    },
  })

  return (
    <section className="space-y-3 border-t border-line pt-4" aria-label="Variants">
      <div className="flex items-center justify-between gap-2">
        <h3 className="font-semibold">Variants</h3>
        {can('products.update') && editing === null && <IconButton showText variant="secondary" icon="plus" label="Add variant" onClick={() => setEditing('new')} />}
      </div>

      <DataTable
        columns={[
          { key: 'sku', header: 'SKU' },
          { key: 'options', header: 'Options', render: (v) => optionsText(v.options) || (v.name ?? '') },
          { key: 'price', header: 'Price', render: (v) => formatMoney(v.price) },
          { key: 'cost_price', header: 'Cost', render: (v) => formatMoney(v.cost_price) },
          { key: 'is_active', header: 'Active', render: (v) => (v.is_active ? 'Yes' : 'No') },
        ]}
        rows={list.data ?? []}
        rowKey={(v) => v.id}
        loading={list.isPending}
        actions={
          can('products.update')
            ? (v) => (
                <span className="inline-flex gap-2">
                  <IconButton variant="row" icon="pencil" label={`Edit variant ${v.sku}`} onClick={() => setEditing(v)} />
                  <IconButton variant="row-danger" icon="trash" label={`Delete variant ${v.sku}`} onClick={() => setDeleting(v)} />
                </span>
              )
            : undefined
        }
      />

      {editing !== null && (
        <VariantForm
          key={editing === 'new' ? 'new' : editing.id}
          productId={productId}
          variant={editing === 'new' ? null : editing}
          onDone={() => {
            setEditing(null)
            refresh()
          }}
          onCancel={() => setEditing(null)}
        />
      )}

      <ConfirmDialog
        open={deleting !== null}
        title="Delete variant"
        message={`Delete variant "${deleting?.sku ?? ''}"?`}
        confirmLabel="Delete"
        danger
        busy={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onCancel={() => setDeleting(null)}
      />
    </section>
  )
}

function VariantForm({ productId, variant, onDone, onCancel }: { productId: number; variant: Variant | null; onDone: () => void; onCancel: () => void }) {
  const [values, setValues] = useState<Record<string, unknown>>({
    sku: variant?.sku ?? '',
    name: variant?.name ?? '',
    options: optionsText(variant?.options),
    price: variant?.price.amount ?? '',
    compare_at_price: variant?.compare_at_price?.amount ?? '',
    cost_price: variant?.cost_price?.amount ?? '',
    is_active: variant?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const set = (name: string, value: unknown) => setValues((v) => ({ ...v, [name]: value }))

  const save = useMutation({
    mutationFn: () => {
      const options = parseOptions(String(values.options))
      if (options === null) throw new OptionsError()

      const body: Record<string, unknown> = {
        sku: values.sku,
        name: values.name === '' ? null : values.name,
        options,
        price: values.price,
        compare_at_price: values.compare_at_price === '' ? null : values.compare_at_price,
        cost_price: values.cost_price === '' ? null : values.cost_price,
        is_active: values.is_active,
      }

      return variant ? api.request('PATCH', `/v1/variants/${variant.id}`, { body }) : api.request('POST', `/v1/products/${productId}/variants`, { body })
    },
    onSuccess: () => {
      toast.success('Variant saved')
      onDone()
    },
    onError: (error) => {
      if (error instanceof OptionsError) {
        setErrors({ options: 'Use the form Name=Value, separated by commas (Color=Red, Size=M).' })

        return
      }
      setErrors(fieldErrors(error))
      toast.error(error instanceof ApiError && error.status === 422 ? 'Please fix the highlighted fields' : 'Could not save the variant')
    },
  })

  const field = (name: string, label: string, extra: { type?: 'money' | 'checkbox'; required?: boolean; hint?: string } = {}) => (
    <FormField label={label} name={`variant_${name}`} type={extra.type} required={extra.required} hint={extra.hint} value={values[name]} error={errors[name]} onChange={(v) => set(name, v)} />
  )

  return (
    <form
      className="space-y-3 rounded-lg border border-line p-3"
      aria-label={variant ? `Edit variant ${variant.sku}` : 'New variant'}
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        setErrors({})
        save.mutate()
      }}
    >
      {field('sku', 'Variant SKU', { required: true })}
      {field('name', 'Variant name')}
      {field('options', 'Options', { hint: 'For example Color=Red, Size=M. Every variant of a product uses the same option names.' })}
      {field('price', 'Variant price', { type: 'money', required: true })}
      {field('compare_at_price', 'Compare-at price', { type: 'money', hint: 'The old price shown struck through.' })}
      {field('cost_price', 'Variant cost price', { type: 'money' })}
      {field('is_active', 'Variant active', { type: 'checkbox' })}
      <div className="flex justify-end gap-2">
        <IconButton showText variant="secondary" icon="x" label="Cancel variant" onClick={onCancel} />
        <IconButton showText type="submit" variant="primary" icon="save" label="Save variant" disabled={save.isPending} />
      </div>
    </form>
  )
}
