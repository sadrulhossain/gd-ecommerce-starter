# Payments

How a payment works in v1, and how to add a gateway (PSO or PSP). Code: `api/app/Payments`. Rules every driver keeps: money is integer minor units plus a currency code; every payment event
carries an idempotency key; credentials are encrypted and never logged; checkout code never names a driver.

## What exists in v1

| Piece | Role |
|-------|------|
| `PaymentProvider` | the driver contract: `code()`, `initiate(PaymentRequest)`, `refund(PaymentRequest)`, both returning a `PaymentResult` |
| `PaymentManager` | the only caller of drivers; refuses unknown or disabled methods, stores one `payment_events` row per key |
| `config/payments.php` | registry: method code to driver class |
| `payment_methods` | name, sort, enabled flag, `credentials` (`encrypted:array`, hidden, write-only in the admin API) |
| `CodProvider` | cash on delivery: `initiate` is `pending`, `refund` is `refunded` |

Checkout validates the method, `OrderService::place()` calls `PaymentManager::initiate()` with key `order-<id>-initiate` inside the order transaction, and stores the result status on the order.

## What does not exist yet

v1 covers **offline methods only**. There is no payment webhook route, no redirect to a gateway page, no `verify` or `capture`, no sandbox driver and no timeout that cancels an unpaid order.
An offline method (bank transfer, a second cash method) is one driver class plus one registry line. An **online** gateway needs the shared work in "Add an online gateway" first; do that once, and every later gateway is again one class plus one line.

## Add an offline method

1. `api/app/Payments/Drivers/BankTransferProvider.php` implementing `PaymentProvider`. `code()` returns `bank_transfer`.
2. Register it: `'bank_transfer' => BankTransferProvider::class` in `config/payments.php`.
3. Admin > Payment methods: add the method (the code is offered from the registry), name it, enable it.
4. Tests: copy `tests/Feature/Payments/PaymentFrameworkTest.php`: the method resolves, a disabled one is 422, a repeated key does not call the driver twice.

Note: `DeliveryService` asks the courier to collect cash only when the order's method is `cod`.

## Add an online gateway (PSO or PSP)

Shared work, once:

1. **Redirect.** Return the gateway URL from the driver in `PaymentResult::$data`, add it to the checkout response and have the portal follow it. Do this generically (any method that returns a URL), never per driver.
2. **Webhook.** Add `POST /api/v1/webhooks/payments/{method}` (public, no auth, its own throttle) and a `handleWebhook(Request, credentials)` method on the contract. It must:
   - verify the gateway's signature over the **raw body** with `hash_equals`, using the secret from the method's credentials, and reject an unsigned or wrong request before reading it;
   - reject old messages (a signed timestamp inside a window of about 5 minutes);
   - be replay safe: store the event under a unique key built from the gateway's event id, and do nothing for a key already seen;
   - compare amount and currency with the order before marking it paid; never trust a status or amount from the browser redirect;
   - answer fast and do the order change in a queued, idempotent job.
3. **Unpaid orders.** A pending order reserves stock (`StockService::available()`). Add a scheduled command that cancels online orders still unpaid after a timeout, through `OrderService::cancel()`.
4. **Network calls.** `initiate` runs inside the order transaction while stock rows are locked. Move the call for an online method after the commit (same idempotency key), so a slow gateway cannot stall every checkout.
5. Update `security:routes` expectations only if the audit flags the new route, and add the review of it to [SECURITY.md](SECURITY.md).

Per gateway: the driver class, the registry line, a `payment_methods` row with credentials, and tests for a good signature, a bad signature, a replay, a wrong amount and a refund larger than the paid amount.

## Secrets

Credentials are encrypted with `APP_KEY`. Losing or changing `APP_KEY` makes them unreadable (set them again in the admin). Never put them in `PaymentResult::$data`, an exception message or a log line.
The audit log records that credentials changed, never their values.
