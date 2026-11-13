# Security policy

Rethread handles accounts, messages and payments, so we take security reports seriously.

## Reporting a vulnerability

**Please don't open a public issue.** Instead, use GitHub's private reporting:
go to the repository's **Security** tab › **Report a vulnerability**.

Include what you found, the steps to reproduce it, and the impact you expect. We'll reply within a few days, keep you updated, and credit you in the fix unless you'd rather stay anonymous.

## Supported versions

Only the latest `main` branch gets security fixes.

## Running your own copy

- Never commit `config.local.php` or `.env` (they're in `.gitignore`).
- Set `APP_ENV=production` on a live site. That forces HTTPS and disables the web installer.
- Follow the go-live steps in [DEPLOY.md](DEPLOY.md) and check **Admin › Go-live checklist**.
