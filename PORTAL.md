# SeeToSee Portal Loop

## Intended happy path

1. Member verifies their email.
2. Member buys the Portal through Stripe.
3. The verified Stripe webhook grants `portal.active`.
4. The member returns to the dashboard and selects **Enter**.
5. Seeme mints a signed, 45-minute booth pass.
6. The selected FJN booth verifies the pass before loading the room runtime.

## Contract

- Portal product: `subscription.portal.monthly`
- One-use consultation product: `consultation.personal`
- Entitlement: `portal.active`
- Pass TTL: 45 minutes (`2700` seconds)
- Booth IDs: `ipv1` (2 seats), `ipv2` (4 seats)
- Shared secret name: `BOOTH_SECRET`
- Pass shape: `<base64url-json>.<lowercase-hex-hmac-sha256>`
- Signed payload fields: `sub`, `product`, `booth`, `seats`, `exp`, `jti`

## Current failure

Seeme mints and forwards the pass, but the supplied FJN booth code does not read or verify `?pass=`, while the naked `SeeTest` wrappers were not present in the supplied Seeme archive.

## Source notes

- Stripe success and cancellation currently return to `/dashboard/?purchase=...`; there is no separate `success.html` in this source.
- The supplied booth UIs contain public STUN configuration only; no TURN credential helper was included.
- `SeeTest/rooms.html`, `SeeTest/apps/booth-2-seat/`, `SeeTest/apps/booth-4-seat/`, `Seeme/portals.html`, and `Seeme/rooms.html` were not present in the supplied archives.
- Runtime `booth-data`, real environment files, logs, database dumps, and Identity Ledger code are intentionally excluded.

