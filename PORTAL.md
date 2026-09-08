# SeeToSee Portal Loop

## Intended happy path

1. Member verifies their email.
2. Member buys the Portal through Stripe.
3. The verified Stripe webhook grants `portal.active`.
4. The member returns to the dashboard (`?purchase=success`) and selects **Enter**.
5. Seeme mints a signed, 45-minute booth pass.
6. The selected FJN booth verifies the pass on **create** / **join** before loading the room runtime.

## Contract

- Portal product: `subscription.portal.monthly`
- One-use consultation product: `consultation.personal`
- Entitlement: `portal.active`
- Pass TTL: 45 minutes (`2700` seconds)
- Booth IDs: `ipv1` (2 seats), `ipv2` (4 seats)
- Shared secret name: `BOOTH_SECRET` — **must be identical** on Seeme and both FJN booths
- Pass shape: `<base64url-json>.<lowercase-hex-hmac-sha256>`
- Signed payload fields: `sub`, `product`, `booth`, `seats`, `exp`, `jti`
- Pass sources (FJN, in order): JSON body `pass`, query `pass`, header `X-Booth-Pass`

## Tryout paywall (separate from Portal)

Straight-to-booth tryout lives at `PrivateBooths/booth/` (was Hostinger-only; now versioned here). See **`TRYOUT.md`** for:

- Products: `tryout.pack.3` ($10 → 3 credits), `subscription.tryout.monthly` ($25 → `tryout.active`)
- Success URL: `https://seetosee.org/PrivateBooths/booth/?purchase=success&order=`
- Cancel URL: same path with `purchase=canceled`
- Placeholder Stripe price IDs: `price_REPLACE_tryout_pack3`, `price_REPLACE_tryout_monthly`
- SQL Sedeck must run: `sql/tryout_paywall.sql`

Portal `$24` and PreFix `$12` products are unchanged. NDA Gate and dashboard ipv2 Enter are left alone.

## Pass verify — implemented

Both `FJNBoothIPv1/signal.php` and `FJNBoothIPv2/signal.php` call `require_booth_pass()` (via `booth_pass.php`) at the start of **create** and **join** only. Poll / signal / leave / ping continue to use room tokens after admission.

Missing secret, missing pass, bad signature, wrong booth, wrong seats, expired `exp`, bad product, empty `jti`, or missing `sub` → HTTP **403** JSON `{error:…}`.

## Deploy map (Hostinger)

| Local path | Live upload path |
|---|---|
| `Seeme/*` | `seetosee.org/Seeme/` |
| `FJNBoothIPv1/*` | `fareljean.com/FJNBoothIPv1/` |
| `FJNBoothIPv2/*` | `fareljean.com/FJNBoothIPv2/` |
| `SeeTest/*` | `seetosee.org/SeeTest/` |
| `PrivateBooths/booth/*` | `seetosee.org/PrivateBooths/booth/` |

Configure `BOOTH_SECRET` in:

- `Seeme/.env`
- `FJNBoothIPv1/.env`
- `FJNBoothIPv2/.env`

Same value everywhere. Do not commit real secrets.

## SeeTest

`SeeTest/rooms.html` and `SeeTest/apps/booth-*-seat/` are redirect stubs to `https://seetosee.org/Seeme/dashboard/#booth`. Naked FJN iframes are intentionally removed.

## Stripe return

- Portal / store products: Checkout success URL uses `{APP_URL}/dashboard/?purchase=success&order=…` (see `Seeme/app/StripeClient.php`). Dashboard shows a short banner pointing at `#booth` Enter buttons.
- Tryout products: return to `TRYOUT_BOOTH_URL` or `https://seetosee.org/PrivateBooths/booth/?purchase=…` (see `TRYOUT.md`).

## Smoke tests

1. `POST signal.php?action=create` with no pass → **403** `Booth pass required`
2. Create/join with wrong booth, expired `exp`, or bad HMAC → **403**
3. Valid pass in JSON body → create returns **201** / join works as before
4. After create, poll/signal/leave with room token still work without re-sending the booth pass
5. Open booth UI without `?pass=` → create/join disabled; status links to Dashboard `#booth`
6. Dashboard side nav includes **Booth**; `?purchase=success` shows Enter nudge
7. `SeeTest/rooms.html` and booth app stubs redirect to Dashboard, not naked FJN
8. Tryout booth: signed-out / no `tryout.active` / credits=0 → Pay $10 / $25 CTAs; Enter gated

## Source notes

- Stripe success and cancellation return to `/dashboard/?purchase=…` for non-tryout; tryout uses PrivateBooths booth. There is no separate `success.html` in this source.
- Booth UIs contain public STUN only; no TURN credential helper was included — left as-is.
- Runtime `booth-data`, real environment files, logs, database dumps, and Identity Ledger code are intentionally excluded.
