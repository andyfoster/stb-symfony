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
  only). `task db:seed` wipes and reloads it, including generated stand-in photos
  (`PlaceholderPhotos`: book covers for textbooks) and a few messages in Sam's inbox.
- Mailer/Notifier messages are routed to the Doctrine `async` Messenger transport
  (`auto_setup=0`, tables created by `messenger:setup-transports`). `symfony serve` (so
  `task start`) also runs a `messenger:consume async` worker, configured in
  `.symfony.local.yaml`, that restarts on code changes and also consumes `scheduler_default`
  (the recurring jobs in `src/Schedule.php`); `symfony server:status` shows it.
  `task worker` runs one in the foreground with `-vv` for debugging.

## Tests

- `task test` (or `php bin/phpunit`); one test: `task test -- --filter testFilterBySearchTypeCategoryAndPrice`.
  `phpunit.dist.xml` fails on deprecations, notices and warnings.
- Functional tests don't use PostgreSQL or migrations: `setUp()` overrides `DATABASE_URL` to
  in-memory SQLite, calls `$client->disableReboot()` (so the in-memory DB survives across
  requests), and builds the schema from all mapped entities with `SchemaTool`. Keep DQL
  portable between SQLite and Postgres (e.g. `LOWER(...) LIKE`, not `ILIKE`).
- Tests assert on template CSS classes and copy (`.listing-card .card-link`, `.listing-price`,
  `.course-code`, `.empty-state`, `.tab`), so markup changes usually need test updates.

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
  Every morning (9:00 NZ, `src/Schedule.php`, Symfony Scheduler) `ExpiryReminder` emails each
  seller once about listings expiring within `EXPIRY_REMINDER_DAYS` (3); `expiryReminderSentAt`
  stops repeats until the next publish/refresh. `task reminders:send` runs it now.
  `ListingFormType` (create/edit at `/sell`, `/listings/{id}/edit`) clears `book` before
  validation unless the category is Textbooks; `ListingEditor` saves, publishes and deletes.
- Otago papers: `BookDetails::papers` is many-to-many with `Paper` (code like CHEM191, optional
  title, `official` when it came from Otago's list). `data/otago-papers.csv` is a starter list
  (Otago's site blocks scripted downloads); load the full list with `task papers:import -- file.csv`
  (`app:papers:import`, `PaperCatalog::importCsv()`, safe to rerun). On the form, `PaperTagsType`
  is a comma-separated text field that the `paper-tags` Stimulus controller turns into tags with
  suggestions from `/papers/search`; codes not in the list are added as unofficial papers, which
  admins can review under Papers in `/admin`. Search matches paper codes with or without spaces.
- ISBN lookup: `BookLookup` asks Open Library, then Google Books (no keys), caching answers in
  `cache.app`; `/sell/lookup-isbn` serves the `isbn-lookup` Stimulus controller.
- Photos (max 4, `ListingPhoto`): `PhotoStorage` re-encodes uploads to WebP (strips EXIF) in
  `large`/`thumb` sizes under `UPLOAD_DIR` (default `var/uploads`), outside `public/`.
  `PhotoController` (`/photos/{id}/{size}`) serves them only when the seller's email is
  verified, or to the seller and admins. Needs the `gd` extension.
- Account dashboard: everything about your account is under `/account` (`AccountController`,
  templates extend `account/_layout.html.twig` for the sidebar): overview (`app_account`),
  My listings, Messages and Your details (`app_account_details`).
- Contact the seller: `ContactSellerType` on the listing page posts to
  `/listings/{id}/contact`; `SellerMessenger` rate-limits (`config/packages/rate_limiter.yaml`),
  saves a `Message` for the seller's inbox (`/account/messages`) and emails them with Reply-To
  set to the sender. Guests and unverified users get Cloudflare Turnstile (`App\Captcha`, keys in
  `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`; `.env` has Cloudflare's always-pass test keys),
  and a hidden `website` honeypot field. Sellers can show their phone (`User::showPhone`) and set
  it under Your details.
- Admin: EasyAdmin 5 at `/admin` (`src/Controller/Admin`, `ROLE_ADMIN`, also in
  `access_control`); the "Admin" menu link only renders for admins. EasyAdmin 5's API differs
  from 4: read `vendor/easycorp/easyadmin-bundle/skills/easyadmin/SKILL.md` first.
  `task user:promote -- email` (or `prod:user:promote`) grants the role.
- `task user:reset-password -- email` (or `prod:user:reset-password`, `app:user:reset-password`)
  sets an account's password to `Password1!`, or to `--password=...`, for people who can't get
  the reset email. Tell them to change it.
- Home page (`ListingController`, `/`): a GET `ListingFilterType` form with an empty block
  prefix (clean `?q=&type=wanted&categories[]=textbooks` URLs) binds to the
  `App\Search\ListingFilter` DTO: a search, the tab (`type`, for sale by default) and any number
  of categories (toggle pills). `ListingRepository::findByFilter()` lists one tab and
  `countByType()` counts both for the tab badges. Results sit in the `<turbo-frame id="listings">`;
  the "For sale" / "Wanted" tab radios are inside it but belong to the form via
  `form="listing-filters"`, which is why the `filter-form` controller sits on a wrapper around both.
  It auto-submits into the frame so the search box keeps focus. The `view-toggle` controller
  switches rows and a card grid (`.view-grid`) and remembers the choice in localStorage. The
  controller passes `$form->createView()` deliberately, so invalid filters render with 200 and
  the full list rather than a 422.
- Categories (`App\Enum\Category`) are deliberately few (five) so they fit as one row of pills;
  merge rather than add.
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
  (test rate limits through the service). Outside HTTP calls (Turnstile, book APIs) get canned
  answers from `tests/MockHttpResponses.php`. `tests/DatabaseTestTrait.php` boots the SQLite
  client and creates users and listings; new tests log in with `loginUser()`.
- `HealthController` (`/up`) is the Kamal proxy healthcheck; keep it dependency-free.
- Styling is hand-written CSS in `assets/styles/app.css` (no framework, system fonts only),
  kept deliberately quiet: white pages, navy text, blue (`--blue`) for links and buttons, and
  gold (`--gold`) only for the monospaced wordmark and "Wanted" listings (which also get a gold
  left edge). Listings are tiger-striped rows: a photo or category icon, title and details, paper
  and condition tags, price on the right. Avoid adding cards, shadows or all-caps labels.
- The project `php.ini` (read by the Symfony CLI) raises upload limits to match the dockerfile
  and gives dev cache warmup enough memory for EasyAdmin's templates.

## Deployment

- Kamal (`config/deploy.yml`) deploys service `stb` to `stb.gitforked.com`, with Postgres as a
  Kamal accessory (container `stb-db`, user/database `stb`). `.kamal/secrets` is committed
  and holds only references: it fetches `APP_SECRET`, `POSTGRES_PASSWORD` and `MAILER_DSN` from
  1Password (account `my.1password.ca`, item `Private/ScarfieTextbooks-production`), along with
  `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`, and builds
  `DATABASE_URL` from them. Never put raw values in it. `task prod:secrets:check` confirms they
  resolve; `task deploy`, `prod:migrate` and `prod:db:boot` run the same check first. The
  registry is Kamal's local one (`localhost:5555`), so no registry password is needed. The same
  server also hosts DayWatch (`daywatch-db`), so check you're pointing at `stb-db`.
- The production image is `dockerfile` (lowercase): FrankenPHP, `APP_ENV=prod`, and it runs
  `importmap:install` + `asset-map:compile` at build time. Caddy config is `Caddyfile`.
- Listing photos live in the `stb_uploads` Docker volume, mounted on the `web` role at
  `/app/var/uploads`. Nothing backs it up yet.
- Migrations are not run on deploy (all `.kamal/hooks` are samples); run `task prod:migrate`
  after `task deploy`. Migrations create `messenger_messages`; `task prod:messenger:setup`
  (`messenger:setup-transports`, idempotent) makes sure it exists, since `auto_setup=0`.
- Email goes through Mailgun (`symfony/mailgun-mailer`, `MAILER_DSN` like
  `mailgun+api://KEY:DOMAIN@default?region=eu`), sent from `noreply@stb.gitforked.com`
  (`config/packages/mailer.yaml`), so that domain must be verified in Mailgun (SPF/DKIM DNS).
  Mail is queued on the Doctrine `async` transport and sent by the `job` role in
  `config/deploy.yml`, a second container from the same image on the same server running
  `messenger:consume async scheduler_default`, so it also runs the scheduled jobs (restarts hourly; the image's Caddy HEALTHCHECK is disabled for
  it). The `kamal console`/`shell` aliases target `--roles=web` so they run once.
  `task prod:worker:logs` / `prod:worker:failed` inspect it.
