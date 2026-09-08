# SeeToSee Portal — booth pass & deploy notes

## Booth pass (HMAC)

- Secret env: `BOOTH_SECRET` (same value on Seeme + FJN booth hosts)
- Algorithm: HMAC-SHA256 over canonical payload; token format `v1.<payload_b64url>.<sig_b64url>`
- Signed payload fields: `sub`, `product`, `booth`, `seats`, `exp`, `jti`
- Pass sources (FJN, in order): JSON body `pass`, query `pass`, header `X-Booth-Pass`

## Tryout paywall (separate from Portal)

Straight-to-booth tryout lives at `PrivateBooths/booth/` (was Hostinger-only; now versioned here). See **`TRYOUT.md`** for:

- Products: `tryout.pack.3` ($10 → 3 credits), `subscription.tryout.monthly` ($25 → `tryout.active`)
- Success URL: `https://seetosee.org/PrivateBooths/booth/?purchase=success&order=`
- Cancel URL: same path with `purchase=canceled`
- Live Stripe price IDs: `price_1UDJGgK4qiJBJ8iLhQI06Rnq` (pack), `price_1UDJGgK4qiJBJ8iL0CR8YBC9` (monthly)
- SQL Sedeck must run: `sql/tryout_paywall.sql`

Portal `$24` and PreFix `$12` products are unchanged. NDA Gate and dashboard ipv2 Enter are left alone.

## Pass verify — implemented

Both `FJNBoothIPv1/signal.php` and `FJNBoothIPv2/signal.php` call `require_booth_pass()` (via `booth_pass.php`) at the start of **create** and **join** only. Poll / signal / leave / ping continue to use room tokens after admission.

Missing secret, missing pass, bad signature, wrong booth, wrong seats, expired / reused `jti` → HTTP 401 JSON error; create/join does not proceed.

## Deploy map

| Local path | Live upload path |
|---|---|
| `Seeme/*` | `seetosee.org/Seeme/` |
| `FJNBoothIPv1/*` | `fareljean.com/FJNBoothIPv1/` |
| `FJNBoothIPv2/*` | `fareljean.com/FJNBoothIPv2/` |
| `SeeTest/*` | `seetosee.org/SeeTest/` |
| `PrivateBooths/booth/*` | `seetosee.org/PrivateBooths/booth/` |

Configure `BOOTH_SECRET` in:

- Seeme Hostinger `.env`
- FJN booth PHP hosts (same secret)

Same value everywhere. Do not commit real secrets.

## Stripe return

- Portal / store products: Checkout success URL uses `{APP_URL}/dashboard/?purchase=success&order=…` (see `Seeme/app/StripeClient.php`). Dashboard shows a short banner pointing at `#booth` Enter buttons.
- Tryout products: return to `TRYOUT_BOOTH_URL` or `https://seetosee.org/PrivateBooths/booth/?purchase=…` (see `TRYOUT.md`).

## Smoke tests

1. Seeme issues pass after paid portal entitlement
2. FJN create/join with valid pass succeeds
3. Tampered / expired / wrong-booth pass → 401
4. Reused `jti` → 401
5. Open booth UI without `?pass=` → create/join disabled; status links to Dashboard `#booth`
6. Dashboard side nav includes **Booth**; `?purchase=success` shows Enter nudge
7. `SeeTest/rooms.html` and booth app stubs redirect to Dashboard, not naked FJN
8. Tryout booth: signed-out / no `tryout.active` / credits=0 → Pay $10 / $25 CTAs; Enter gated

## Source notes

- Stripe success and cancellation return to `/dashboard/?purchase=…` for non-tryout; tryout uses PrivateBooths booth. There is no separate `success.html` in this source.
- Booth UIs contain public STUN only; no TURN credential helper was included — left as-is.
- Runtime `booth-data`, real environment files, logs, database dumps, and Identity Ledger code are intentionally excluded.
