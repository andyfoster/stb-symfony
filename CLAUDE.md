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
  year, ISBN, papers), per the scope rule above. Enums in `src/Enum` carry their display `label()`.
  Editions are whole numbers (12), shown as "12th edition" (`App\Book\Edition`,
  `BookDetails::getEditionOrdinal()`); the form and ISBN lookup accept "12th", "12e" or "Twelfth"
  and keep the number.
- Listings belong to a `seller` (nullable only for imported rows) and have a `ListingStatus`
  (draft / active / sold / archived). "Expired" is not a status: an active listing past `expiresAt`.
  Sellers can't delete listings, only archive them (`/listings/{id}/archive`, or "Take off site",
  `app_listing_remove`, which emails link to); sold and archived ones can be re-listed.
  `publish()` / `refresh()` set `publishedAt` and `expiresAt` (the home page lists A–Z by title) from
  the admin-set lifetime in the single `SiteSettings` row (`SiteSettingsProvider`, default and
  maximum 365 days, so a book posted in November is still up for July). Sellers can instead pick a
  "Hide after" date on the form (unmapped `hideAfter`), up to a year ahead: `ListingExpiry` turns
  that New Zealand date into `expiresAt` (midnight after it, stored in UTC; Doctrine saves
  wall-clock time, so never persist a non-UTC `DateTimeImmutable`). Changing it never moves
  `publishedAt`. Refresh only opens `REFRESH_WINDOW_DAYS` (7) before expiry. `ListingVoter`
  (`LISTING_VIEW`, `LISTING_EDIT`): anyone sees live listings, sellers and admins the rest.
  Every morning (9:00 NZ, `src/Schedule.php`, Symfony Scheduler) `ExpiryReminder` emails each
  seller once about listings expiring within `EXPIRY_REMINDER_DAYS` (3); `expiryReminderSentAt`
  stops repeats until the next publish/refresh. `task reminders:send` runs it now.
  `ListingFormType` (create/edit at `/sell`, `/listings/{id}/edit`) clears `book` before
  validation unless the category is Textbooks; `ListingEditor` saves, publishes and archives.
  The form relabels itself for wanted listings ("Looking for", "Budget", "Requirements";
  `listing-form` controller, mirrored in `_form.html.twig` for the first render), and
  `/sell?type=wanted` starts one.
- Otago papers: `BookDetails::papers` is many-to-many with `Paper` (code like CHEM191, optional
  title, `official` when it came from Otago's list). `data/otago-papers.csv` is a starter list
  (Otago's site blocks scripted downloads); load the full list with `task papers:import -- file.csv`
  (`app:papers:import`, `PaperCatalog::importCsv()`, safe to rerun). On the form, `PaperTagsType`
  is a comma-separated text field that the `paper-tags` Stimulus controller turns into tags with
  suggestions from `/papers/search`; codes not in the list are added as unofficial papers, which
  admins can review under Papers in `/admin`. Search matches paper codes with or without spaces.
- ISBN lookup: `BookLookup` asks Open Library, then Google Books (no keys), caching answers in
  `cache.app`; `/sell/lookup-isbn` serves the `isbn-lookup` Stimulus controller. `BookDetails`
  stores ISBNs normalised (`BookLookup::normalise()`). Textbooks with an ISBN and no photo show
  an Open Library cover (`listing/_book_cover.html.twig`, loaded straight from
  covers.openlibrary.org; the `book-cover` controller falls back to the category icon).
- Title suggestions: as you type a listing title, the `title-suggest` controller asks
  `/sell/suggest-titles` (`ListingRepository::suggestBookTitles()`) for textbook titles listed
  before (published or sold, never drafts), one per title and author, most sold first. Picking
  one sets the category to Textbooks and fills an empty author.
- Photos (max 4, `ListingPhoto`) live under `UPLOAD_DIR` (default `var/uploads`), outside
  `public/`, one folder per photo. `PhotoStorage::add()` keeps an `original` (auto-rotated, at
  most 2400px, WebP, EXIF stripped); the sizes pages use (`PhotoStorage::SIZES`: `large`, `thumb`)
  are made from it by `generate()`: in the background after upload (`GeneratePhotoSizes` on the
  `async` worker), on demand by `PhotoController` when one is missing, or all again with
  `task photos:regenerate` (`--missing`, `--size=thumb`, `--queue`) after changing `SIZES`.
  `RemovePhotoFiles` deletes a photo's files whenever Doctrine removes it; `task photos:prune`
  clears folders left by rows the database deleted itself (e.g. a deleted user's listings).
  `PhotoController` (`/photos/{id}/{size}`) serves them only when the seller's email is
  verified, or to the seller and admins. Needs the `gd` extension.
- Admin photos: the listings admin shows each listing's photos, and Photos (`ListingPhotoCrudController`)
  pages through every photo with whether it's shown on the site, to delete or regenerate them.
  The listing enums implement `TranslatableInterface` so EasyAdmin shows their `label()`.
- Account dashboard: everything about your account is under `/account` (`AccountController`,
  templates extend `account/_layout.html.twig` for the sidebar): overview (`app_account`),
  My listings, Messages and Your details (`app_account_details`).
- Contact the seller: `ContactSellerType` on the listing page posts to
  `/listings/{id}/contact`; `SellerMessenger` rate-limits (`config/packages/rate_limiter.yaml`),
  saves a `Message` for the seller's inbox (`/account/messages`) and emails them with Reply-To
  set to the sender. Senders give an email, a mobile (`Message::senderPhone`) or both
  (`Assert\Expression` on `senderEmail`); logged-in senders get their account's phone filled in,
  and phone-only messages have no Reply-To, so the seller texts or calls back. Guests and unverified users get Cloudflare Turnstile (`App\Captcha`, keys in
  `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`; `.env` has Cloudflare's always-pass test keys;
  the widget is `interaction-only`, shows Cloudflare's error code if it fails, and rejected tokens
  are logged with Cloudflare's reason; the site's hostname must be on the widget's list in Cloudflare),
  and a hidden `website` honeypot field. Sellers can show their phone (`User::showPhone`) and set
  it under Your details.
- Admin: EasyAdmin 5 at `/admin` (`src/Controller/Admin`, `ROLE_ADMIN`, also in
  `access_control`); the "Admin" menu link only renders for admins. EasyAdmin 5's API differs
  from 4: read `vendor/easycorp/easyadmin-bundle/skills/easyadmin/SKILL.md` first.
  `task user:promote -- email` (or `prod:user:promote`) grants the role.
- `task user:reset-password -- email` (or `prod:user:reset-password`, `app:user:reset-password`)
  sets an account's password to `Password1!`, or to `--password=...`, for people who can't get
  the reset email. Tell them to change it. Admins can do the same from a user's "Reset password"
  action in `/admin` (both use `App\Security\PasswordSetter`).
- Home page (`ListingController`, `/`): every live listing is on the page
  (`ListingRepository::findLive()`), and the `listing-filter` Stimulus controller shows and hides
  them as you search, switch the "For sale" / "Wanted" tabs or pick category pills, with no
  request and no change to the address bar. Each card carries `data-type`, `data-category` and
  `data-search` (`ListingFilter::searchText()`). `ListingFilterType` (GET, empty block prefix:
  `?q=&type=wanted&categories[]=textbooks`) still works without JavaScript and for links:
  `ListingFilter::matches()` applies the same rules on the server, so keep the PHP and JS in step.
  The tab radios use `form="listing-filters"`. The `view-toggle` controller switches rows and a
  card grid (`.view-grid`) and remembers it in localStorage. Invalid filters render with 200 and
  everything shown. If listings grow into the thousands, this needs paging again.
- Categories (`App\Enum\Category`) are deliberately few (five) so they fit as one row of pills;
  merge rather than add.
- `Condition` has a `description()` shown under the form's star-rated condition buttons
  and under Details on the listing page.
  It's required for things for sale (`Assert\Expression` on `Listing::$condition`) and cleared for
  wanted listings. Listings have no pick-up location.
- Accounts: `User` (table `app_user`) is identified by email, always stored lower-case;
  `UserRepository::loadUserByIdentifier()` is the provider's loader, so lookups are
  case-insensitive. `password` is nullable so later social (OAuth) or magic-link (`login_link`)
  sign-ins can create password-less accounts on the same `main` firewall. Firewall: `form_login`
  (fields `email`/`password`), `remember_me`, `login_throttling` (needs `symfony/rate-limiter`),
  POST-only `logout` with CSRF. Sign-up and email confirmation use
  `symfonycasts/verify-email-bundle` (`App\Security\EmailVerifier`; the link carries the user
  id so it works signed out), forgot-password uses `symfonycasts/reset-password-bundle`
  (`ResetPasswordRequest`). Accounts from the old site keep their unsalted SHA-1 hex hash:
  `LegacySha1PasswordHasher` (`migrate_from` in `security.yaml`) checks it, trimmed and
  untrimmed as the old site did, and `UserRepository::upgradePassword()` stores a modern hash on
  first login. Unverified users can still log in and see a banner. The dev login
  from fixtures is `sam@example.com` / `password`.
- Legacy import: `task legacy:import -- dump.sql` (`app:legacy:import`, `--dry-run` rolls back)
  reads the old site's MariaDB dump in PHP (`App\Legacy\MysqlDumpReader`, which also undoes the
  old latin1 columns' double-encoded UTF-8), and `LegacyImporter` moves over, in one transaction,
  only the listings the old site still shows (`for_sale = 'y'`, added or refreshed within its
  8 weeks; `--listed-within=DAYS` widens that) and the `member_list` accounts selling them. Every
  other account is deliberately left behind; login, sign-up and forgot-password say so
  (`security/_old_site_notice.html.twig`, remove it once people have moved over). Rerunning skips
  accounts whose email is already here and listings by `Listing::legacyId` (old `listing_id`).
  Emails that differ only by case are one account, taking the password and details of the row
  logged into most recently. Imported accounts are **unverified**, because the old site never
  confirmed addresses, except those that finished an old password reset (`hash_table.used`),
  which proves the inbox works. Old admins (`access = 1`) aren't made admins; `access = 0`
  accounts get no password. Listings are textbooks with `BookDetails`, expiring a year (the
  default lifetime) after they were last listed on the old site; `quality` 5 ("looks like new") to 1 ("falling apart") maps to Like new,
  Good, Fair, Well used (2 and 1). Paper codes are pulled out of the free text
  (`LegacyImporter::paperCodes()`), unknown ones as unofficial papers. The book's year and
  `dept` aren't kept.
- Book list: `Book` holds every textbook the old site ever had, once per book, with no personal
  details: times listed and sold (`for_sale = 'n'`, taken down), typical price, editions,
  papers, first and last listed. Listings are the same book when their `Book::titleKey()`
  (no punctuation, editions, years or paper codes) matches and their authors share one of
  `Book::surnames()`. Imports match books by `matchKey` or `aliases` and refresh their counts,
  keeping admin edits to the title and author. Under Books in `/admin` (`BookCrudController`)
  admins sort, search and edit them, and the Merge batch action (`Book::merge()`) joins
  duplicates, keeping the merged one's key as an alias so reimports add to it. A deleted
  book comes back on the next import. Title suggestions on the listing form
  (`App\Book\TitleSuggestions`) combine the Book list with listings made here
  (`ListingRepository::suggestBookTitles()` skips imported ones, which the list already counts).
- Audit log: `App\Audit\AuditLog::record()` writes an `AuditEvent` (`AuditType`, actor, email,
  subject type/id/label, IP, user agent, small JSON context) and the same line to the `audit`
  Monolog channel (`var/log/audit.log` in dev, JSON on stderr in prod). Rows go in with a plain
  INSERT, not a flush, so recording never flushes other changes or breaks a request if it fails;
  record a deletion before flushing it, while the subject still has its id. `SecurityAuditListener`
  covers logins (form, remember-me, after sign-up), failed and throttled ones (the email typed,
  never the password) and logouts; `AdminAuditListener` covers EasyAdmin creates, edits (changed
  field names only) and deletes, plus admin access changes. Services record the rest: listings
  and photos (`ListingEditor`), seller messages including their body (`SellerMessenger`,
  context key `messageBody`), password resets, legacy SHA-1 upgrades (`UserRepository`), `PasswordSetter`, email confirmation and
  `app:user:promote`. Admins read it under Audit log in `/admin`, and on each user's detail page.
  `PruneAuditLog` (nightly, `src/Schedule.php`) deletes events older than
  `AuditLog::RETENTION_DAYS` (365).
- `framework.yaml` trusts `X-Forwarded-*` from private addresses (`trusted_proxies: private_ranges`),
  which in production is only kamal-proxy, so `getClientIp()` is the visitor's and `isSecure()`
  is true behind its TLS (`TrustedProxyTest`). Revisit it if the app's port is ever published.
- CSRF is stateless (`config/packages/csrf.yaml`): hand-written forms need a `_csrf_token`
  field with `data-controller="csrf-protection"`, and tests send `Sec-Fetch-Site: same-origin`.
  In the test env Messenger's `async` transport is `in-memory://` (assert with
  `assertQueuedEmailCount()`) and `cache.app` is an array, so throttling resets per request
  (test rate limits through the service). Outside HTTP calls (Turnstile, book APIs) get canned
  answers from `tests/MockHttpResponses.php`. `tests/DatabaseTestTrait.php` boots the SQLite
  client and creates users and listings; new tests log in with `loginUser()`.
- `HealthController` (`/up`) is the Kamal proxy healthcheck; keep it dependency-free.
- `/about` (`AboutController`, linked from the footer) explains how listings work; keep it in
  step with the expiry and archiving rules. HTML emails extend `emails/_layout.html.twig`, and
  those about a listing include `emails/_listing_actions.html.twig` (mark sold or archive).
- Success and error flashes render as toasts at the bottom left (`toast` Stimulus controller,
  they dismiss themselves); keep the `.flash` / `.flash-error` classes, tests assert on them.
  Listing photos are small tiles that open in a `<dialog>` lightbox (`gallery` controller), and
  the contact form sits in a `<dialog>` popup (`modal` controller, reopened automatically when
  the form has errors; Turnstile renders once the popup opens). The photo slots on the
  listing form (`photo-slots`) add each pick to the next free slot and put the files back on
  the input with DataTransfer. Pages don't have "Back to …" links; the header and dashboard
  sidebar cover navigation.
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
  `importmap:install`, `assets:install` (EasyAdmin's CSS and icons in `public/bundles`, which
  is git-ignored and skipped by `composer install --no-scripts`) and `asset-map:compile` at
  build time. Caddy config is `Caddyfile`.
- Listing photos live in the `stb_uploads` Docker volume, mounted on the `web` role at
  `/app/var/uploads`. Nothing backs it up automatically; `task prod:uploads:backup` downloads it.
- Production database: `task prod:db:shell` (psql in `stb-db`, live data), `prod:db:logs`, and
  `prod:db:dump`, which saves a `pg_dump --format=custom` to `var/backups/` (git-ignored). Dumps
  and the photo backup stream over plain `ssh` to `Taskfile.yml`'s `PROD_SSH` (keep it in step with
  `config/deploy.yml`), because `kamal ... exec` adds its own lines to the output.
  `task db:restore -- var/backups/….dump` loads a dump into the local Docker database;
  `task db:dump` saves the local one first if you want to keep it.
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
