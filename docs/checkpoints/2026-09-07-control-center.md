# SeeToSee Control Center checkpoint

Date: 2026-09-07
Status: planning and test branch only
Baseline: main at `dd291ca09ae5ee1e91fff64f79fcab746a5dd8ec`

## Safety boundary

This branch is the working area for the Control Center. It is not the live deployment branch.

- `main` remains unchanged.
- Hostinger files, the production database, Stripe, booth folders, and mail credentials are unchanged.
- Do not copy this branch to Hostinger until the feature is tested and explicitly approved.
- Never commit `.env` files, mailbox passwords, SMTP passwords, API keys, session secrets, member lists, or production database exports.
- Operator addresses and recipient lists belong in private server-side configuration, not this public repository.

## Current live baseline

- Existing V27 dashboard remains the dashboard source of truth.
- Authentication and verified-email flow remain the source of truth for access.
- Existing verification, welcome, and admin-notification mail remains separate from the Control Center UI.
- Paid booth routing and Portal/booth paths remain separate from this Control Center work.

## Planned Control Center scope

The next changes belong on this branch only:

1. Add a Control Center entry to the existing V27 dashboard.
2. Enforce operator access server-side through a private allowlist or database role.
3. Add a small test-message screen that sends only to the approved private operator recipients.
4. Record send results and errors without exposing secrets.
5. Add member campaigns, receipt acknowledgements, and weekly checkups only after the private operator test succeeds.

## Review gate before deployment

Before any production upload, verify:

- server-side operator authorization;
- CSRF protection and rate limits;
- recipient validation and unsubscribe/suppression handling;
- audit logging;
- no front-end-only admin bypass;
- no mass send enabled by default;
- successful private operator test;
- password reset, email verification, dashboard, Stripe, and booth smoke tests.

