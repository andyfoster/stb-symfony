# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

ScarfieTextbooks ("stb"): a marketplace where students buy and sell textbooks and other goods, and post things they're looking for. Stack:
Symfony 8.1, PHP 8.4, Doctrine ORM 3 on PostgreSQL 18, Twig + AssetMapper (importmap, no Node
build) + Stimulus/Turbo. No authentication is configured yet, even though SecurityBundle is
installed.

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
  (`auto_setup=0`, tables created by `messenger:setup-transports`), so emails are only sent
  while `task worker` is running.

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
- Home page (`ListingController`, `/`): a GET `ListingFilterType` form with an empty block
  prefix (clean `?q=&type=&category=&maxPrice=` URLs) binds to the `App\Search\ListingFilter`
  DTO, and `ListingRepository::findByFilter()` builds the query. Results sit in the
  `<turbo-frame id="listings">`; the `filter-form` Stimulus controller auto-submits into it so
  the search box keeps focus. The controller passes `$form->createView()` deliberately, so
  invalid filters render with 200 and the full list rather than a 422.
- `HealthController` (`/up`) is the Kamal proxy healthcheck; keep it dependency-free.

## Deployment

- Kamal (`config/deploy.yml`) deploys service `stb` to `stb.gitforked.com`, with Postgres as a
  Kamal accessory (container `stb-db`, user/database `stb`). `.kamal/secrets` is committed
  and holds only references: it fetches `APP_SECRET` and `POSTGRES_PASSWORD` from 1Password
  (account `my.1password.ca`, item `Private/ScarfieTextbooks-production`) and builds
  `DATABASE_URL` from them. Never put raw values in it. `task prod:secrets:check` confirms they
  resolve; `task deploy`, `prod:migrate` and `prod:db:boot` run the same check first. The
  registry is Kamal's local one (`localhost:5555`), so no registry password is needed. The same
  server also hosts DayWatch (`daywatch-db`), so check you're pointing at `stb-db`.
- The production image is `dockerfile` (lowercase): FrankenPHP, `APP_ENV=prod`, and it runs
  `importmap:install` + `asset-map:compile` at build time. Caddy config is `Caddyfile`.
- Migrations are not run on deploy (all `.kamal/hooks` are samples); run `task prod:migrate`
  after `task deploy`.
