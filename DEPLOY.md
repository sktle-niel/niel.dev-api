# Deploying the CODEKATHAX API to Hostinger

This API runs on standard **Hostinger shared hosting** (PHP 8 + MySQL +
phpMyAdmin). PHPMailer is bundled, so there is nothing to `composer install`.

The plan: host the API on a **subdomain** (e.g. `api.codekathax.online`) whose
document root points at the project's `public/` folder, keeping `app/`,
`.env`, and the SQL outside the web root.

---

## 1. Create the database (hPanel → Databases → MySQL)

1. Create a new database + user. Hostinger prefixes them, e.g.
   `u123456_codekathax` (DB) and `u123456_ckxuser` (user). Note the password.
2. Open **phpMyAdmin** for that database, go to the **Import** tab, and import
   `database/schema.sql`. You should now see a `project_requests` table (and,
   among the others, a `password_resets` table — schema.sql is kept current
   with all migrations through `011_password_resets.sql`).

## 2. Create the subdomain

1. hPanel → **Domains → Subdomains** → create `api.codekathax.online`.
2. Note its document root. By default it is something like
   `public_html/api`. You will point this at `public/` in the next step.

## 3. Upload the files

Upload the **whole project** to a folder OUTSIDE `public_html` if your plan
allows (e.g. `/home/u123456/codekathax-api/`), then set the subdomain's
document root to that folder's `public/`.

If you can only upload inside `public_html`, upload the project to
`public_html/codekathax-api/` and set the subdomain document root to
`public_html/codekathax-api/public`. The included `.htaccess` files block
direct access to `app/`, `.env`, and the SQL as a safety net.

Use **hPanel → File Manager** or **FTP**. Make sure these go up:
`app/` (incl. `app/PHPMailer/`), `public/`, `database/`, and `.env`.

## 4. Configure `.env` on the server

Edit `.env` (in the project root, NOT in `public/`) with the real values:

```ini
APP_ENV=production

DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456_codekathax
DB_USER=u123456_ckxuser
DB_PASS=your-db-password

# Non-www is canonical — the frontend's .htaccess redirects www -> non-www,
# so only the non-www origin ever actually shows up in browser requests.
ALLOWED_ORIGINS=https://codekathax.online

SITE_URL=https://codekathax.online

IP_SALT=paste-a-long-random-string-here

MAIL_TO=niel.ladica07@gmail.com
MAIL_FROM=no-reply@codekathax.online
MAIL_FROM_NAME=CODEKATHAX Website
MAIL_ENABLED=true
```

> Set `ALLOWED_ORIGINS` to the exact origin(s) your website is served from, and
> `SITE_URL` to the same origin — it's what agent, tracker, and password-reset
> links in emails are built from.

## 5. Set up email (SMTP)

**Recommended on Hostinger — a domain mailbox:**

1. hPanel → **Emails** → create a mailbox, e.g. `no-reply@codekathax.online`.
2. Add to `.env`:

   ```ini
   SMTP_HOST=smtp.hostinger.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=no-reply@codekathax.online
   SMTP_PASS=the-mailbox-password
   ```

**Or use Gmail (works anywhere):**

1. Turn on 2-Step Verification on your Google account.
2. Google Account → **Security → App passwords** → generate one (16 chars).
3. Add to `.env`:

   ```ini
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=niel.ladica07@gmail.com
   SMTP_PASS=the-16-char-app-password
   ```

If SMTP is left blank, submissions are still saved to MySQL — only the email is
skipped (the API returns `"emailed": false`).

## 6. Schedule the cleanup cron (recommended)

hPanel → **Advanced → Cron Jobs** → run once a day:

```
php /home/u123456/codekathax-api/bin/cleanup.php
```

Prunes expired sessions, password-reset tokens, and old rate-limit rows —
see `SECURITY.md`.

## 7. Test the live API

- Visit `https://api.codekathax.online/` → should return the health JSON.
- Submit the form on your website. Check:
  - phpMyAdmin → `project_requests` has a new row.
  - Your inbox (`niel.ladica07@gmail.com`) received the email.

## 8. Point the website at the API

The frontend's `.env.production` already sets:

```ini
VITE_API_BASE=https://api.codekathax.online
```

so `npm run build` picks it up automatically — no extra step needed unless
the API subdomain changes. See the frontend project's own `DEPLOY.md` for the
rest of the site deployment.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `Database error` / 500 | Check `.env` DB creds; confirm `schema.sql` was imported. |
| CORS error in browser | Add your site's exact origin to `ALLOWED_ORIGINS`. |
| Saved but `emailed: false` | SMTP not set or wrong. For Gmail use an **App Password**, not your login. |
| Gmail blocks the send | Use port 465 + `ssl`, and an App Password (2FA must be on). |
| 403 when opening `/app/...` | Expected — `app/` is intentionally blocked from the web. |
