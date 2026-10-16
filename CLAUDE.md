# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

ScarfieTextbooks ("stb"): a marketplace for buying and selling university textbooks. Stack:
Symfony 8.1, PHP 8.4, Doctrine ORM 3 on PostgreSQL 18, Twig + AssetMapper (importmap, no Node
build) + Stimulus/Turbo. No authentication is configured yet, even though SecurityBundle is
installed.

Goal and scope:
- The immediate goal is a modern replacement for an existing ScarfieTextbooks site that is
  live today as a vanilla PHP app. Aim for parity with that app before adding new features.
- For now only books are listed. The site is meant to cover other kinds of items later, so
  don't hard-code "book" into the generic marketplace parts (listings, sellers, buying). Keep
  book-only fields (e.g. ISBN, author, edition, course) separate from them, without building a
  general item-type system before it's needed.

The codebase was copied from a small countdown app called **DayWatch**, and none of the
textbook domain exists yet. Everything below about `Event` (entity, form, controller,
templates, test, the existing migration) is DayWatch leftover, kept as a working example of
the project's patterns, and is expected to be replaced. DayWatch naming also survives in
infrastructure: the `daywatch` database/user and `daywatch-local-db` container in
`compose.yaml`, `.env`/`.env.dev`, `Taskfile.yml` (`db:shell`), and the branding in
`templates/base.html.twig`. Deployment (`config/deploy.yml`) already uses `stb`.

## Local development

- `compose.yaml` only runs PostgreSQL (`database`, host port **5440**); `compose.override.yaml`
  adds Mailpit. PHP runs on the host: `.env.dev` points `DATABASE_URL` at `127.0.0.1:5440`.
  - `docker compose up -d database`
  - `symfony serve -d`
  - `symfony console doctrine:migrations:migrate`
- `Taskfile.yml` (untracked, work in progress) wraps everything as `docker compose exec app ...`,
  but no `app` service exists in the compose files yet, so its tasks won't work until one is added.
  Its task names (`test`, `lint`, `migrate:make`, `worker`, ...) are the intended entry points.
- Mailer/Notifier messages are routed to the Doctrine `async` Messenger transport
  (`auto_setup=0`), so emails are only sent while `messenger:consume async` is running.

## Tests

- `php bin/phpunit` runs everything; `php bin/phpunit --filter testCreateAndEditEventFromHomePage`
  runs one test. `phpunit.dist.xml` fails on deprecations, notices and warnings.
- Functional tests don't use PostgreSQL or migrations: each test overrides `DATABASE_URL` to
  in-memory SQLite in `setUp()`, calls `$client->disableReboot()` (so the in-memory DB survives
  across requests), and builds the schema with `SchemaTool`. Follow that pattern for new
  controller tests; a new entity must be added to the `createSchema([...])` list.
- Tests assert on CSS classes and copy in the templates (`.event-card`, `.countdown`,
  `.countdown-unit`, `.empty-state`, flash text, button labels). Changing template markup or
  wording usually means updating `tests/Controller/EventControllerTest.php`.

## Architecture notes (DayWatch example code)

- `Event::calculateDaysRemaining()` holds the countdown logic (signed day difference from
  today); templates render from it.
- `EventController` serves `/` and a legacy `/events/` listing alias; create/edit redirect back
  with HTTP 303 and invalid forms re-render with 422 (Symfony form rendering default).
- `HealthController` (`/up`) is the Kamal proxy healthcheck; keep it dependency-free.

## Deployment

- Kamal (`config/deploy.yml`) deploys service `stb` to `stb.gitforked.com`, with Postgres as a
  Kamal accessory. Secrets (`APP_SECRET`, `DATABASE_URL`, `POSTGRES_PASSWORD`) come from
  `.kamal/secrets`.
- The production image is `dockerfile` (lowercase): FrankenPHP, `APP_ENV=prod`, and it runs
  `importmap:install` + `asset-map:compile` at build time. Caddy config is `Caddyfile`.
- Migrations are not run on deploy (all `.kamal/hooks` are samples); run them with
  `kamal console doctrine:migrations:migrate`.
