# Contributing to Rethread

Thanks for helping! Bug reports, fixes, features, docs and translations are all welcome.

## Get it running (about 5 minutes)

You need **either** Docker **or** a local PHP + MySQL stack (MAMP, XAMPP, Laragon, Homebrew…).

### Option A: Docker (recommended)

```bash
git clone https://github.com/<you>/rethread.git
cd rethread
docker compose up -d
docker compose exec app php install.php
```

Open http://localhost:8080. The demo accounts are listed in the [README](README.md#demo-accounts-test-data-only).

### Option B: MAMP / XAMPP / your own Apache

1. Put the folder in your web root (for example `htdocs/rethread`).
2. Copy `config.local.php.example` to `config.local.php` and set `LOCAL_APP_ENV = 'local'`, your database login and `LOCAL_APP_URL` (for example `http://localhost/rethread`). Delete the lines you don't need.
3. Run `php install.php` to create the database and demo data.

Apache needs `mod_rewrite` and `AllowOverride All`; PHP needs `pdo_mysql`, `gd` (with WebP), `curl`, `mbstring` and `openssl`.

### Option C: PHP's built-in server (no Apache)

```bash
php install.php
php -S localhost:8000 scripts/dev-router.php
```

Set `APP_URL=http://localhost:8000` (in `config.local.php` or the environment) so links in emails are right.

## Making a change

1. **Open an issue first** for anything bigger than a small fix, so we can agree on the approach before you spend time on it.
2. Fork the repo and create a branch from `main`: `git checkout -b fix/short-description`.
3. Make your change. Keep pull requests focused: one fix or feature per PR.
4. Run the tests (below) and check the pages you touched in a browser, on a phone-sized window too.
5. Open a pull request and fill in the template. CI runs the tests on every PR.

## Tests

```bash
php tests/run.php            # unit and database tests
php tests/run.php --http     # also loads the main pages of your running site
php tests/run.php --filter=review
```

The runner builds a throwaway `rethread_test` database from the installer's demo data, so your own data is never touched. Add a test for every bug fix and new feature in `tests/run.php`.

## Code style

The project is deliberately plain: **PHP 8.3+, MySQL 8, no framework, no Composer, no build step**. Please keep it that way unless an issue agrees otherwise.

- One page = one PHP file at the root (`item.php`, `catalog.php`…). Shared logic lives in `inc/`. Admin pages live in `admin/`.
- Database: always use `q($sql, $params)` with `?` placeholders. Never put user input in SQL strings.
- Output: escape everything with `e()`. Every form that changes data includes `csrf_field()` and the handler calls `check_csrf()`.
- Schema changes go in `install.php` as **additive migrations** (`CREATE TABLE IF NOT EXISTS`, add a column only if it's missing), so existing sites upgrade by re-running `php install.php`. Add new tables to the `$tables` reset list.
- Mobile first: check your page at 375 px wide.
- 4-space indentation, LF line endings, UTF-8 (`.editorconfig` sets this up in most editors).
- Write user-facing text in plain, friendly English.

## Commit messages

Short summary line in the imperative ("Add price filter to member page"), then a blank line and the why, if it isn't obvious.

## Reporting security issues

Please **don't** open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).

## Code of conduct

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
