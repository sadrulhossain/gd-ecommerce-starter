import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

// Shop: listing, product page, cart. Needs sample products (php artisan db:seed --class='Database\Seeders\Site\SiteCatalogSeeder').

async function firstProduct(page: Page): Promise<string> {
  await page.goto('/shop')
  const link = page.locator('article h3 a').first()
  test.skip((await link.count()) === 0, 'no products in the catalog')

  return (await link.getAttribute('href'))!
}

const scan = async (page: Page) => {
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()

  return violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`)
}

test('shop lists products and filters by search', async ({ page }) => {
  await firstProduct(page)
  await expect(page.getByRole('heading', { level: 1, name: 'Shop' })).toBeVisible()
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0)

  await page.getByRole('searchbox', { name: 'Search products' }).fill('zzzz-no-such-product')
  await page.getByRole('button', { name: 'Apply' }).click()
  await expect(page.getByText('No products match.')).toBeVisible()
  expect(await scan(page)).toEqual([])
})

test('product page, add to cart, change and remove', async ({ page }) => {
  const href = await firstProduct(page)
  expect(await scan(page)).toEqual([])

  await page.goto(href)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.locator('script[type="application/ld+json"]')).toBeAttached()
  expect(await scan(page)).toEqual([])

  await page.getByRole('button', { name: 'Add to cart' }).click()
  test.skip(await page.getByText(/in stock/).isVisible({ timeout: 3000 }).catch(() => false), 'sample products have no stock: post a stock adjustment first')
  await expect(page.getByText('Added to your cart.')).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: /Cart/ })).toContainText('1')

  await page.getByRole('link', { name: 'View cart' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Your cart' })).toBeVisible()
  const line = page.getByRole('listitem').filter({ has: page.getByRole('button', { name: /^Remove/ }) }).first()
  await expect(line).toBeVisible()
  expect(await scan(page)).toEqual([])

  await line.getByRole('button', { name: 'Increase quantity' }).click()
  await expect(line.getByRole('spinbutton')).toHaveValue('2')

  await line.getByRole('button', { name: /^Remove/ }).click()
  await expect(page.getByText('Your cart is empty')).toBeVisible()
})

test('an empty cart page offers the shop', async ({ page }) => {
  await page.goto('/cart')
  await expect(page.getByText('Your cart is empty')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Keep shopping' })).toBeVisible()
})
