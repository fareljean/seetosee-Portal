# APPLY — Portal admission loop (pass verify)

Brief changelog + Hostinger upload steps. Do **not** upload real `.env` values from this working copy.

## What changed

- **FJN pass gate:** `booth_pass.php` + `require_booth_pass()` on create/join for IPv1 and IPv2.
- **Booth UIs:** require `?pass=`; send `pass` in create/join JSON; disable lobby without pass.
- **Dashboard:** side-nav **Booth** link; `?purchase=success` (and optional `portal`) banner → `#booth`; `dashboard.js` cache `27.2.8`.
- **SeeTest:** rooms + booth app stubs redirect to Dashboard `#booth` (no naked FJN iframes).
- **Docs:** `PORTAL.md` marks verify implemented; deploy map + smoke tests.

## Files touched

```
FJNBoothIPv1/booth_pass.php          (new)
FJNBoothIPv1/signal.php
FJNBoothIPv1/index.html
FJNBoothIPv2/booth_pass.php          (new)
FJNBoothIPv2/signal.php
FJNBoothIPv2/index.html
Seeme/dashboard/index.html
Seeme/dashboard/dashboard.js
SeeTest/rooms.html                   (new)
SeeTest/apps/booth-2-seat/index.html (new)
SeeTest/apps/booth-4-seat/index.html (new)
SeeTest/README-MISSING.md
PORTAL.md
APPLY-PORTAL.md                      (this file)
```

## Hostinger upload paths

| Upload these | To |
|---|---|
| `FJNBoothIPv1/booth_pass.php`, `signal.php`, `index.html` | `fareljean.com/FJNBoothIPv1/` |
| `FJNBoothIPv2/booth_pass.php`, `signal.php`, `index.html` | `fareljean.com/FJNBoothIPv2/` |
| `Seeme/dashboard/index.html`, `dashboard.js` | `seetosee.org/Seeme/dashboard/` |
| `SeeTest/rooms.html`, `SeeTest/apps/**`, `SeeTest/README-MISSING.md` | `seetosee.org/SeeTest/` |

Also ensure live `.env` on Seeme + both FJN booths share the **same** `BOOTH_SECRET` (set in Hostinger file manager / panel — never from git).

## Post-upload check

1. Hit IPv1/IPv2 create without pass → 403.
2. Enter from Dashboard → pass in URL → create works.
3. SeeTest room URLs land on Dashboard `#booth`.
