# Communications Control Center setup

This feature extends the existing V27 dashboard. It does not create a second
dashboard and it does not change Stripe, PreFix, NDA Gate, WebBook, Portal,
booth, or WebRTC behavior.

## Private server configuration

Set these values in the server's private `.env` file. Do not commit the file
or paste its contents into GitHub.

- `MAIL_FROM_ADDRESS` — the approved authenticated sending mailbox.
- `CONTROL_CENTER_SENDER_EMAIL` — must exactly match `MAIL_FROM_ADDRESS` for
  Control Center sends. Set this privately to the approved sender mailbox.
- `MAIL_ADMIN_RECIPIENTS` — existing verification/admin-alert recipients.
- `CONTROL_CENTER_OPERATOR_EMAILS` — the three approved, verified operator
  account addresses, comma-separated.
- `COMMUNICATION_RECEIPT_HOURS=168` — one-week receipt lifetime by default.

The operator list is intentionally absent from the public source package. An
account must be active, email-verified, and present in the private allowlist.

## Database

1. Back up the production database.
2. Apply `database/communications_install.sql` once to the same database used
   by `/Seeme/.env`.
3. Record the migration version in the deployment log.

The migration creates only the communications campaign, delivery, receipt,
and weekly-schedule tables.

## Test order

1. Sign in as an approved operator.
2. Confirm the Control Center appears in the existing dashboard.
3. Send the test message.
4. Confirm each of the three operator delivery rows shows Sent.
5. Open each one-time receipt link.
6. Reload the dashboard and confirm all three rows show Confirmed.
7. Only then resume weekly scheduling or send a verified-member campaign.

## Weekly runner

Configure a private Hostinger cron job to run:

```text
php /path/to/Seeme/scripts/communications-weekly.php
```

The runner is safe to invoke frequently. It sends only when the server-side
schedule is due, the schedule is resumed, and the three-recipient operator
receipt test is complete.

## Rollback

Disable the weekly schedule, stop the cron job, and remove only the
communications UI/API files and migration-created tables after reviewing the
campaign records. Do not restore older copies of the existing authentication,
Stripe, PreFix, NDA, WebBook, or booth files over live production.
