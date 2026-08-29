# Soft Appeals Recovery Command Center: deployment and rollback runbook

Phase 8 deliverable, section 22 of the build plan. Sections 25 and 26 of the
plan, written against the installation as it actually is. Nothing here needs
a shell on the host: there is none. Everything is the hPanel file manager,
the hPanel cron screen, the Desk, and a `git push`.

Read this once before the first production deploy. Keep it beside the
Automation and Launch screens on the Desk, which read the live state and say
which of these steps is done.

## Where things are

| Thing | Where |
|---|---|
| Production code | `main` branch, deployed by `.github/workflows/deploy.yml` to `public_html/` |
| Staging code | any `soft-appeals/**` branch, deployed by `deploy-staging.yml` to `public_html/staging/` |
| Private config | `storage-private/soft-appeals/config/config.php` on the server, never committed |
| Databases | production `u286380648_softappeals` / staging `u286380648_sastage` |
| Database backups | `storage-private/soft-appeals/backups/` (deny-all, gitignored) |
| Vault (agreements, executed records, signatures, invoices) | `storage-private/soft-appeals/agreements` `signatures` `invoices` |
| Error log | `storage-private/soft-appeals/audit-exports/errors.log` |
| Job health log | `storage-private/soft-appeals/audit-exports/jobs.log` |
| Cron entry point | `cron/soft-appeals-jobs.php` (CLI only, deny-all folder) |
| The Desk | `/sa-desk.php`, Automation at `?view=jobs`, Launch at `?view=launch` |

## Section 25: deployment, in order

Do the steps in this order. Each flag goes on by itself, after the step
before it has been seen working. The Launch screen on the Desk shows each
step's state from the live installation.

1. **Back up files and database.**
   Database: Desk, Automation, "Run every job" (writes one now), or wait for
   the daily job. Confirm "Newest backup: Verified" on that screen.
   Files: hPanel, Files, Backups, take a manual backup of `public_html`.
   The vault files are not inside the database backup; the file backup is
   what covers them.

2. **Run migrations in staging first.**
   Push the branch. Staging migrates itself on the first request after the
   upload (SA_AUTO_MIGRATE is on off production). Open the staging Desk once
   and read the audit trail for `schema.migrate`. Every migration also runs
   up and down on CI (`schema_check.py`) before the upload starts.

3. **Deploy with every new flag disabled.**
   Merge to `main`. `deploy.yml` uploads it. The production config file must
   carry no `true` for `SA_PORTAL_ENABLED`, `SA_CLIENT_LOGIN_ENABLED`,
   `SA_E_SIGN_ENABLED`, `SA_RECOVERY_FINANCE_ENABLED` or
   `SA_DEADLINE_CRON_ENABLED`. On production an unset flag is off. Set
   `SA_AUTO_MIGRATE` to `true` for the one request that brings the schema
   up, watch the audit trail, then remove it.

4. **Run health checks.**
   Open `/soft-appeals-login.php`: a 503 page names what is missing from the
   config. Sign in. Open Automation and press "Run every job": every row
   should read Ok. Open Launch: steps 1 to 4 should read Done.

5. **Enable demo mode with fictional data.**
   `SA_DEMO_MODE` is `true` by default; the sticky "Demo" notice shows on
   every Desk screen. The six invented practices seed only an EMPTY database
   and only off production. Set `SA_DEMO_MODE` to `false` once a real
   practice is in the database.

6. **Test owner login and client isolation.**
   Sign in as owner. Accept a test inquiry, send the terms, follow the
   printed staging link as the practice, confirm preferences, open the room.
   The signer must not see money or access lines; the viewer must see and
   decide nothing. The suite proves this on every push
   (`ClientAccessTest`, `SigningTest`, `ApprovalTest`, `CloseoutTest`);
   the walk on screen is yours.

7. **Enable intake database writes.**
   `sa-lead.php` has always written leads to `fs-metrics/`. The Desk's
   importer (Records, Import old leads) reads that folder into the database
   and never writes back. Nothing to switch.

8. **Enable client login.**
   `SA_PORTAL_ENABLED` and `SA_CLIENT_LOGIN_ENABLED` to `true` in the
   production config.

9. **Enable documents after legal approval.**
   Six blockers stand, listed on the Launch screen (`Config::PRODUCTION_SIGNING_BLOCKERS`).
   Production signing is clamped shut in code while any remains, whatever
   the config says. Clearing them: approve the four document texts in
   `Domain\DocumentTemplates`, type the legal entity name under Desk
   Settings, review consent and retention, edit the blocker list down to
   nothing, push, deploy, then set `SA_E_SIGN_ENABLED` to `true`.

10. **Enable recovery finance after reconciliation tests.**
    `ReconciliationTest` and `CloseoutTest` run on every push. Walk verify,
    invoice, closeout on staging once, then set
    `SA_RECOVERY_FINANCE_ENABLED` to `true` in the production config.

11. **Enable cron last.**
    hPanel, Advanced, Cron Jobs. Once a day, early morning, the exact line
    shown on the Automation screen:

        /usr/bin/php /home/u286380648/domains/frimpomaasync.com/public_html/cron/soft-appeals-jobs.php run

    (Staging's line, printed on its own Automation screen, ends in
    `public_html/staging/cron/...`. The Desk prints the real path for the
    installation it runs on; copy it from there rather than from here.)

    Then set `SA_DEADLINE_CRON_ENABLED` to `true`. Until it is, that line
    prints a refusal and exits 3, and the Desk's "Run every job" button is
    the only thing that runs the jobs. Every job is safe to run more often
    than daily: nothing sends twice and nothing is created twice.

## Section 25: rollback

1. **Disable all new flags.** Every `SA_*_ENABLED` to `false` in the
   production config. Takes effect on the next request; nothing is cached.
2. **Restore the prior code release.** `git revert` the merge on `main` (or
   reset `main` to the previous tag) and push. `deploy.yml` uploads the
   prior tree; the FTPS deploy deletes files the new tree lacks.
3. **Keep the database intact.** A later schema with earlier code is safe
   here: every migration only adds. Roll a migration back only if a tested
   down step is specifically needed; `database/migrate.php down` needs a
   PHP CLI, so on this host that is a decision, not a routine.
4. **Restore the latest verified backup only for confirmed corruption.**
   Never onto live rows. Create a fresh empty database in hPanel, point a
   throwaway config at it, run migrations against it, then:

        /usr/bin/php cron/soft-appeals-jobs.php restore storage-private/soft-appeals/backups/sa-backup-YYYYMMDD-HHMMSS.json.gz --dsn="mysql:host=localhost;dbname=NEW;charset=utf8mb4" --user=... --password=...

   The restore refuses any target holding a row, refuses a file whose hash
   does not match, and turns foreign keys back on when it is done. Then
   switch `SA_DB_DSN` in the config to the new database. The vault files
   are untouched by all of this; every row names its file by hash.
5. **Reconcile communications before resending anything.** Desk, Audit
   trail, Messages: every send is a row with its state. Nothing is ever
   marked delivered. Resending rotates the token and the old link dies.
6. **Record the rollback.** It is in the audit trail as `schema.migrate`,
   `backup.restore` and the flag changes are visible on the Launch screen.
   Write a line in `docs/handoffs/` saying what and why.

No rollback deletes signed records or audit events. There is no code path
that deletes either.

## Backups: what they are and how they are proved

- The daily job writes every `sa_` table, every row, as one gzipped JSON
  file with a SHA-256 beside it. Kept 14 days, never fewer than 7 files.
- The verify job checks the newest one exists, is under 36 hours old,
  matches its hash and decodes. A failure is an urgent card on Home and a
  red row on Automation.
- The restore is proved on every CI run (`BackupTest`): a backup from a
  full walk is put into a fresh database and compared row for row,
  including the executed-document hashes.
- By hand, at any time: `php cron/soft-appeals-jobs.php backup`, `verify`,
  `restore <file> --dsn=...`.

## The jobs, and what each one does

| Job | What | Safe to rerun because |
|---|---|---|
| `invitations.expire` | marks a lapsed unused one-time link revoked | the WHERE names unrevoked rows only |
| `tasks.internal` | countersignatures waiting 2+ days, approved batches unsubmitted 3+ days, follow-ups due, her questions past date, invoices past due | keyed attention items |
| `deadlines.batches` | every batch deadline crossing 30, 14, 7, 3, 1 day; unconfirmed dates labelled and never called controlling | keyed per batch and threshold |
| `payments.pending` | overturned batches with no verified reimbursement | keyed per batch |
| `closeout.access` | closeouts with undecided access rows | keyed per closeout |
| `reminders.client` | one reminder per cadence period per item waiting on a practice | idempotency key = item + period |
| `backup.daily` | writes a backup, prunes old ones | a new file each day is the point |
| `backup.verify` | verifies the newest | reads only |
| `housekeeping` | drops rate-limit rows, run rows and resolved items past 90 days | deletes are idempotent |
| `digest.morning` | emails the counts once a day after the digest hour | idempotency key = the date |

Every job takes a database lock first (`sa_job_locks`), so two crons cannot
run one job at once. A lock lapses after ten minutes, so a job that died
does not block tomorrow's. Every run is a row in `sa_job_runs` and a line in
`jobs.log`; a failure is recorded, surfaced on the Desk, and the next job
still runs.

## Responsive and accessibility notes

- Every Desk table sits in `.sa-tablewrap`, which scrolls sideways; the
  page never scrolls sideways.
- Inputs are 16px so iOS does not zoom on focus.
- The Desk has a skip link to `#desk-main` and a `<main>` landmark; every
  section is labelled by its heading; the current rail item carries
  `aria-current="page"`; focus is visible on every control (`.sa :focus-visible`).
- System fonts only; no remote font request anywhere in the application.
- The client pages carry the same rules and are tested for the absence of
  any file input or person-level field (`PortalBoundaryTest`).

## Checks before any push

    python3 tests/SoftAppeals/schema_check.py
    python3 tests/SoftAppeals/static_check.py

CI runs both plus `php -l` on every file and the full suite. A red run does
not upload. FTP timeouts on Hostinger are retried three times by the
workflow and are not a failure of the build.
