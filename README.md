# SeeToSee Portal

Source repository for the SeeToSee Portal system.

## Structure

- `Seeme/` — dashboard, authentication, commerce, and booth-pass integration
- `FJNBoothIPv1/` — two-seat booth
- `FJNBoothIPv2/` — four-seat booth
- `SeeTest/` — redirect stubs to Dashboard booth admission (no naked FJN embeds)
- `PORTAL.md` — architecture, live paths, and deployment notes

## Security

Live `.env` files and runtime booth data are intentionally excluded. Use the supplied `.env.example` files for configuration names only.
