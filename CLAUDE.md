# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

ScarfieTextbooks ("stb"): a marketplace where students buy and sell textbooks and other goods, and post things they're looking for. Stack:
Symfony 8.1, PHP 8.4, Doctrine ORM 3 on PostgreSQL 18, Twig + AssetMapper (importmap, no Node
build) + Stimulus/Turbo. SecurityBundle with email + password login (see Accounts below).

Goal and scope:
- The immediate goal is a modern replacement for an existing ScarfieTextbooks site that is
  live today as a vanilla PHP app. Aim for parity with that app before adding new features.
- Textbooks come first. The site also covers other student goods (furniture, electronics, ...), so
  don't hard-code "book" into the generic marketplace parts (listings, sellers, buying). Keep
  book-only fields (e.g. ISBN, author, edition, course) separate from them, without building a
  general item-type system before it's needed.

The codebase started as a copy of a countdown app called DayWatch; that code and naming have
been removed.

## Local development

`Taskfile.yml` is the command reference (`task` lists everything). Prefer its tasks
(`task start`, `task test`, `task check`, `task migrate:make`, `task db:seed`, ...) and keep it
up to date when adding a workflow.

- PHP runs on the host via the Symfony CLI; only Postgres (`database`, fixed host port
  **5441**) and Mailpit run in Docker. The compose project is named `scarfietextbooks`.
- `.env` holds the local Docker `DATABASE_URL`, so plain `bin/console` works as well as
  `symfony console` (which injects the same values from Docker, plus `MAILER_DSN` for Mailpit).
- Example data lives in `src/DataFixtures/AppFixtures.php` (DoctrineFixturesBundle, dev/test
  only). `task db:seed` wipes and reloads it.
- Mailer/Notifier messages are routed to the Doctrine `async` Messenger transport
  (`auto_setup=0`, tables created by `messenger:setup-transports`). `symfony serve` (so
  `task start`) also runs a `messenger:consume async` worker, configured in
  `.symfony.local.yaml`, that restarts on code changes; `symfony server:status` shows it.
  `task worker` runs one in the foreground with `-vv` for debugging.

## Tests

- `task test` (or `php bin/phpunit`); one test: `task test -- --filter testFilterBySearchTypeCategoryAndPrice`.
  `phpunit.dist.xml` fails on deprecations, notices and warnings.
- Functional tests don't use PostgreSQL or migrations: `setUp()` overrides `DATABASE_URL` to
  in-memory SQLite, calls `$client->disableReboot()` (so the in-memory DB survives across
  requests), and builds the schema from all mapped entities with `SchemaTool`. Keep DQL
  portable between SQLite and Postgres (e.g. `LOWER(...) LIKE`, not `ILIKE`).
- Tests assert on template CSS classes and copy (`.listing-card h3`, `.listing-price`,
  `.course-code`, `.empty-state`, `#listings .count`), so markup changes usually need test updates.

## Architecture

- `Listing` is the generic marketplace item: `ListingType` (for sale / wanted), `Category`,
  optional `Condition`, price in integer cents (null = make an offer / name your price;
  0 = free). Book-only data lives in the optional one-to-one `BookDetails` (author, edition,
  ISBN, course code), per the scope rule above. Enums in `src/Enum` carry their display `label()`.
- Listings belong to a `seller` (nullable only for imported rows) and have a `ListingStatus`
  (draft / active / sold). "Expired" is not a status: an active listing past `expiresAt`.
  `publish()` / `refresh()` set `publishedAt` (the home page sorts by it) and `expiresAt` from
  the admin-set lifetime in the single `SiteSettings` row (`SiteSettingsProvider`, default 42
  days). Refresh only opens `REFRESH_WINDOW_DAYS` (7) before expiry. `ListingVoter`
  (`LISTING_VIEW`, `LISTING_EDIT`): anyone sees live listings, sellers and admins the rest.
  `ListingFormType` (create/edit at `/sell`, `/listings/{id}/edit`) clears `book` before
  validation unless the category is Textbooks; `ListingEditor` saves, publishes and deletes.
- ISBN lookup: `BookLookup` asks Open Library, then Google Books (no keys), caching answers in
  `cache.app`; `/sell/lookup-isbn` serves the `isbn-lookup` Stimulus controller.
- Home page (`ListingController`, `/`): a GET `ListingFilterType` form with an empty block
  prefix (clean `?q=&type=&category=&maxPrice=` URLs) binds to the `App\Search\ListingFilter`
  DTO, and `ListingRepository::findByFilter()` builds the query. Results sit in the
  `<turbo-frame id="listings">`; the `filter-form` Stimulus controller auto-submits into it so
  the search box keeps focus. The controller passes `$form->createView()` deliberately, so
  invalid filters render with 200 and the full list rather than a 422.
- Accounts: `User` (table `app_user`) is identified by email, always stored lower-case;
  `UserRepository::loadUserByIdentifier()` is the provider's loader, so lookups are
  case-insensitive. `password` is nullable so later social (OAuth) or magic-link (`login_link`)
  sign-ins can create password-less accounts on the same `main` firewall. Firewall: `form_login`
  (fields `email`/`password`), `remember_me`, `login_throttling` (needs `symfony/rate-limiter`),
  POST-only `logout` with CSRF. Sign-up and email confirmation use
  `symfonycasts/verify-email-bundle` (`App\Security\EmailVerifier`; the link carries the user
  id so it works signed out), forgot-password uses `symfonycasts/reset-password-bundle`
  (`ResetPasswordRequest`). Unverified users can still log in and see a banner. The dev login
  from fixtures is `sam@example.com` / `password`.
- CSRF is stateless (`config/packages/csrf.yaml`): hand-written forms need a `_csrf_token`
  field with `data-controller="csrf-protection"`, and tests send `Sec-Fetch-Site: same-origin`.
  In the test env Messenger's `async` transport is `in-memory://` (assert with
  `assertQueuedEmailCount()`) and `cache.app` is an array, so throttling resets per request
  (test rate limits through the service). Outside HTTP calls (book APIs) get canned
  answers from `tests/MockHttpResponses.php`. `tests/DatabaseTestTrait.php` boots the SQLite
  client and creates users and listings; new tests log in with `loginUser()`.
- `HealthController` (`/up`) is the Kamal proxy healthcheck; keep it dependency-free.

## Deployment

- Kamal (`config/deploy.yml`) deploys service `stb` to `stb.gitforked.com`, with Postgres as a
  Kamal accessory (container `stb-db`, user/database `stb`). `.kamal/secrets` is committed
  and holds only references: it fetches `APP_SECRET`, `POSTGRES_PASSWORD` and `MAILER_DSN` from
  1Password (account `my.1password.ca`, item `Private/ScarfieTextbooks-production`) and builds
  `DATABASE_URL` from them. Never put raw values in it. `task prod:secrets:check` confirms they
  resolve; `task deploy`, `prod:migrate` and `prod:db:boot` run the same check first. The
  registry is Kamal's local one (`localhost:5555`), so no registry password is needed. The same
  server also hosts DayWatch (`daywatch-db`), so check you're pointing at `stb-db`.
- The production image is `dockerfile` (lowercase): FrankenPHP, `APP_ENV=prod`, and it runs
  `importmap:install` + `asset-map:compile` at build time. Caddy config is `Caddyfile`.
- Migrations are not run on deploy (all `.kamal/hooks` are samples); run `task prod:migrate`
  after `task deploy`. Migrations create `messenger_messages`; `task prod:messenger:setup`
  (`messenger:setup-transports`, idempotent) makes sure it exists, since `auto_setup=0`.
- Email goes through Mailgun (`symfony/mailgun-mailer`, `MAILER_DSN` like
  `mailgun+api://KEY:DOMAIN@default?region=eu`), sent from `noreply@stb.gitforked.com`
  (`config/packages/mailer.yaml`), so that domain must be verified in Mailgun (SPF/DKIM DNS).
  Mail is queued on the Doctrine `async` transport and sent by the `job` role in
  `config/deploy.yml`, a second container from the same image on the same server running
  `messenger:consume async` (restarts hourly; the image's Caddy HEALTHCHECK is disabled for
  it). The `kamal console`/`shell` aliases target `--roles=web` so they run once.
  `task prod:worker:logs` / `prod:worker:failed` inspect it.
