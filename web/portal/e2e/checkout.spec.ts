import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'

// Checkout, tracking and account against the running stack. Needs sample products with stock, one active delivery zone
// and an enabled payment method (see docs/PATTERNS.md, "Portal shop"); otherwise the tests skip.

const scan = async (page: Page) => {
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()

  return violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`)
}

async function fillCart(page: Page) {
  await page.goto('/shop')
  const link = page.locator('article h3 a').first()
  test.skip((await link.count()) === 0, 'no products in the catalog')
  await page.goto((await link.getAttribute('href'))!)
  await page.getByRole('button', { name: 'Add to cart' }).click()
  test.skip(await page.getByText(/in stock/).isVisible({ timeout: 3000 }).catch(() => false), 'sample products have no stock')
  await expect(page.getByText('Added to your cart.')).toBeVisible()
}

async function checkout(page: Page) {
  await page.goto('/checkout')
  await expect(page.getByRole('heading', { level: 1, name: 'Checkout' })).toBeVisible()
  const zone = page.getByLabel('Delivery area')
  await expect(zone).toBeVisible()
  test.skip((await zone.locator('option').count()) < 2 || (await page.getByRole('radio').count()) === 0, 'no delivery zone or payment method configured')

  await page.getByLabel('Full name').fill('E2E Buyer')
  await page.getByLabel('Phone', { exact: false }).first().fill('01700000000')
  await page.getByLabel('Street address').fill('1 Test Road')
  await page.getByLabel('City').fill('Dhaka')
  await zone.selectOption({ index: 1 })
}

test('guest checks out, then tracks the order with the code', async ({ page }) => {
  await fillCart(page)
  await checkout(page)
  expect(await scan(page)).toEqual([])

  await page.getByRole('button', { name: 'Place order' }).click()
  await expect(page.getByRole('status')).toContainText('your order is placed')
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Order ')
  expect(await scan(page)).toEqual([])
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)

  const tracking = await page.getByRole('link', { name: 'Tracking link' }).getAttribute('href')
  expect(tracking).toContain('/track?number=')

  // The cart is empty after the order.
  await page.goto('/cart')
  await expect(page.getByText('Your cart is empty')).toBeVisible()

  // Tracking with the code works; a wrong code does not.
  await page.goto(tracking!)
  await page.getByRole('button', { name: 'Track order' }).click()
  await expect(page.getByRole('heading', { name: 'Order progress' })).toBeVisible()

  await page.goto('/track')
  await page.getByLabel('Order number').fill('NOPE-000000')
  await page.getByLabel('Tracking code').fill('wrong-code-123')
  await page.getByRole('button', { name: 'Track order' }).click()
  await expect(page.getByText('We could not find that order')).toBeVisible()
  expect(await scan(page)).toEqual([])
})

test('customer registers, orders, sees the order and cancels it', async ({ page }) => {
  await fillCart(page)

  await page.goto('/account')
  await expect(page.getByRole('heading', { level: 1, name: 'My account' })).toBeVisible()
  expect(await scan(page)).toEqual([])
  await page.getByRole('button', { name: 'Create account' }).first().click()
  const email = `e2e-${Date.now()}@example.com`
  await page.getByLabel('Full name').fill('E2E Customer')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('correct-horse-9')
  await page.getByRole('button', { name: 'Create account' }).last().click()
  await expect(page.getByText('Hello, E2E Customer')).toBeVisible()

  await checkout(page)
  await expect(page.getByText('This order will be saved to your account.')).toBeVisible()
  await page.getByRole('button', { name: 'Place order' }).click()
  await expect(page.getByRole('status')).toContainText('your order is placed')

  await page.goto('/account')
  await expect(page.getByRole('heading', { name: 'Your orders' })).toBeVisible()
  await expect(page.getByRole('table')).toBeVisible()
  expect(await scan(page)).toEqual([])
  await page.getByRole('link', { name: /^View/ }).first().click()

  await page.getByRole('button', { name: 'Cancel order' }).click()
  await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel order' }).click()
  await expect(page.getByText('This order was cancelled.')).toBeVisible()

  await page.goto('/account')
  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page.getByRole('button', { name: 'Sign in' }).first()).toBeVisible()
})

test('checkout with an empty cart offers the shop', async ({ page }) => {
  await page.goto('/checkout')
  await expect(page.getByText('Your cart is empty')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Keep shopping' })).toBeVisible()
})
