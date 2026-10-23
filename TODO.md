# TODO

## Send account emails in production (Mailgun)

The code side is done (`config/deploy.yml` has a `job` worker role, `.kamal/secrets` fetches
`MAILER_DSN`). Until the steps below are done, `task prod:secrets:check`, and so `task deploy`,
fail.

- [ ] **Mailgun:** add the sending domain `stb.gitforked.com` (matches the From address
      `noreply@stb.gitforked.com` in `config/packages/mailer.yaml`). Pick US or EU, then create a
      sending API key.
- [ ] **DNS for `stb.gitforked.com`** (Mailgun shows the exact values):
  - [ ] SPF: TXT on `stb.gitforked.com`: `v=spf1 include:mailgun.org ~all`
  - [ ] DKIM: TXT on `<selector>._domainkey.stb.gitforked.com` with Mailgun's key
  - [ ] DMARC: TXT on `_dmarc.stb.gitforked.com`: `v=DMARC1; p=none` (Gmail/Yahoo require one)
  - [ ] Optional: MX `mxa`/`mxb.mailgun.org` (EU: `mxa`/`mxb.eu.mailgun.org`) for bounces, and a
        tracking CNAME `email.stb.gitforked.com` → `mailgun.org` (EU: `eu.mailgun.org`)
  - [ ] Wait for Mailgun to show the domain as verified
- [ ] **1Password:** add a `MAILER_DSN` field to `Private/ScarfieTextbooks-production`
      (account `my.1password.ca`), e.g. `mailgun+api://API_KEY:stb.gitforked.com@default?region=eu`
      (`region=us` for US). Then check it resolves with `task prod:secrets:check`.
- [ ] **Deploy**, in this order:
  1. `task deploy` (creates the new `stb-job-*` worker container; DayWatch is untouched)
  2. `task prod:migrate` (also creates `messenger_messages`)
  3. `task prod:messenger:setup` (safety check, safe to rerun)
  4. Sign up as a test user and watch `task prod:worker:logs` for the confirmation email

## Other

- [ ] `composer audit` reports an advisory in `symfony/expression-language` (fixed in 8.1.9):
      run `composer update symfony/expression-language`.
- [ ] Import the live vanilla PHP site in production at cutover: copy the latest MariaDB dump
      into the `web` container and run `app:legacy:import` there (try `--dry-run` first; locally
      `task legacy:import -- dump.sql`). It brings over only current listings and their sellers,
      and builds the Book list (~1,600 books from 6,400 listings).
- [ ] Tidy the Book list under Books in `/admin`: merge duplicates such as "Anatomy & Physiology"
      vs "Anatomy & Physiology Textbook", and fix misspelt authors ("Thibideau"). The import also
      adds ~480 unofficial papers from old free text; review them under Papers.
- [ ] Remove the "Used ScarfieTextbooks before?" notice (`security/_old_site_notice.html.twig`)
      once people have moved over.
