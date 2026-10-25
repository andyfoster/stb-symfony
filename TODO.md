# TODO

## Split test (stb.gitforked.com) from production (scarfietextbooks.com)

The config is done: Kamal destinations `test` and `production` (`config/deploy.*.yml`,
`.kamal/secrets.*`), tasks `test:*` and `prod:*`. Plain `kamal` and the old `task deploy` no
longer work.

- [ ] **1Password:** rename `Private/ScarfieTextbooks-production` to `ScarfieTextbooks-test`
      (it holds test's live database password). Then create a new `ScarfieTextbooks-production`
      with a fresh `APP_SECRET` and `POSTGRES_PASSWORD` (`openssl rand -hex 32` each) and the
      same `MAILER_DSN`, `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`. Check both with
      `task test:secrets:check` and `task prod:secrets:check`.
- [ ] **Move test onto its destination** (a few minutes' downtime on stb.gitforked.com; its
      database `stb-db` and photos `stb_uploads` are untouched). Its containers become
      `stb-web-test-*`, so first remove the old ones and their proxy route, then deploy:
  1. `ssh root@89.167.96.7 'docker exec kamal-proxy kamal-proxy remove stb-web; docker ps -a --format "{{.Names}}" | grep -E "^stb-(web|job)-[0-9a-f]{40}$" | xargs -r docker rm -f'`
  2. `task test:deploy`
- [ ] **DNS:** A records for `scarfietextbooks.com` and `www.scarfietextbooks.com` → `89.167.96.7`
      (if behind Cloudflare, SSL mode "Full", or DNS-only until Let's Encrypt has issued).
- [ ] **Cloudflare Turnstile:** add `scarfietextbooks.com` and `www.scarfietextbooks.com` to the
      widget's hostnames.
- [ ] **Start production:**
  1. `task prod:db:boot` (creates `scarfietextbooks-db`)
  2. `task prod:deploy`
  3. `task prod:migrate`, then `task prod:messenger:setup`
  4. `task prod:db:clear` (loads the paper list and creates admin user #1; change its password)
  5. `task prod:legacy:import -- dump.sql` at cutover

## Other

- [ ] `composer audit` reports an advisory in `symfony/expression-language` (fixed in 8.1.9):
      run `composer update symfony/expression-language`.
- [ ] Import the live vanilla PHP site in production at cutover:
      `task prod:legacy:import -- dump.sql` (try `--dry-run` first; locally
      `task legacy:import -- dump.sql`). It brings over only current listings and their sellers,
      and builds the Book list (~1,600 books from 6,400 listings).
- [ ] Tidy the Book list under Books in `/admin`: merge duplicates such as "Anatomy & Physiology"
      vs "Anatomy & Physiology Textbook", and fix misspelt authors ("Thibideau"). The import also
      adds ~480 unofficial papers from old free text; review them under Papers.
- [ ] Remove the "Used ScarfieTextbooks before?" notice (`security/_old_site_notice.html.twig`)
      once people have moved over.
