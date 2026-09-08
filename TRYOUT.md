# SeeToSee Tryout Paywall

Pay-to-try for `PrivateBooths/booth/` (straight-to-booth tryout). Does **not** change Portal `$24` (`subscription.portal.monthly`) or PreFix `$12` (`subscription.prefix.monthly`).

## Products

| product_id | type | amount | grants |
|---|---|---|---|
| `tryout.pack.3` | payment (one-time) | **$10** (1000 cents) | **3** tryout credits |
| `subscription.tryout.monthly` | subscription | **$25**/month (2500 cents) | entitlement `tryout.active` |

Leave existing catalog rows for Portal / PreFix / template / consultation untouched.

## Entitlements & credits

- **Subscription:** Stripe subscription sync already grants `entitlement_key` from `product_catalog`. Set `entitlement_key = 'tryout.active'` on `subscription.tryout.monthly`.
- **Pack:** After Checkout `payment` is confirmed (`commerce_orders.status = paid`), Seeme grants **+3** to `tryout_credits.balance` (idempotent via `tryout_credit_grants`).
- **Access rule (booth UI):** allow Enter when `tryout.active` **or** `tryout_credits.balance > 0`. Otherwise show Pay $10 / $25.

## Stripe Checkout return URLs

Tryout products return to the tryout booth (not Seeme dashboard):

- **Success:** `https://seetosee.org/PrivateBooths/booth/?purchase=success&order={ORDER_PUBLIC_ID}`
- **Cancel:** `https://seetosee.org/PrivateBooths/booth/?purchase=canceled&order={ORDER_PUBLIC_ID}`

Override base with env `TRYOUT_BOOTH_URL` (absolute URL, no trailing query). `APP_URL` points at Seeme, so tryout must not rely on `APP_URL` alone.

Non-tryout products keep dashboard returns: `{APP_URL}/dashboard/?purchase=success|canceled&order=…`.

## Stripe price IDs — fill after Dashboard create

Placeholders are intentionally invalid until replaced (ProductCatalog rejects IDs containing `REPLACE`):

| product_id | `stripe_price_id` placeholder | optional env key |
|---|---|---|
| `tryout.pack.3` | `price_REPLACE_tryout_pack3` | `STRIPE_TRYOUT_PACK3_PRICE_ID` |
| `subscription.tryout.monthly` | `price_REPLACE_tryout_monthly` | `STRIPE_TRYOUT_MONTHLY_PRICE_ID` |

**Sedeck must:** create Products + Prices in Stripe Dashboard → copy real `price_…` IDs → `UPDATE product_catalog` (and/or set the env keys on Hostinger). Do **not** invent secret keys; `STRIPE_SECRET_KEY` / webhook secret stay as-is.

## SQL Sedeck must run

There is **no** migration framework in this repo; `product_catalog` is DB-only. Run the statements in `sql/tryout_paywall.sql` on the live Seeme database.

## Checkout flow (v1)

1. Member must be **signed in** (Seeme session / CSRF).
2. Booth page CTAs call existing `POST /Seeme/api/commerce/checkout.php` with `product_id`.
3. Prefer existing Checkout Sessions (`StripeClient::commerceCheckout`).
4. Sign-in required before checkout — unsigned users open Seeme auth modal (or link to Seeme sign-in).

## Deploy map

| Local path | Live upload path |
|---|---|
| `PrivateBooths/booth/index.html` | `seetosee.org/PrivateBooths/booth/index.html` |
| `Seeme/app/StripeClient.php` | `seetosee.org/Seeme/app/` |
| `Seeme/app/CommerceService.php` | `seetosee.org/Seeme/app/` |
| `Seeme/app/TryoutCredits.php` | `seetosee.org/Seeme/app/` |
| `Seeme/.env.example` | names only — set `TRYOUT_BOOTH_URL` in live `.env` if needed |

## Out of scope

- NDA Gate — leave alone
- Dashboard ipv2 Enter — leave alone
- Portal / PreFix product rows and prices — leave alone
