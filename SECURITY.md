# CODEKATHAX API — Security notes & deploy checklist

This API is bearer-token based (no cookies), uses prepared statements throughout,
hashes passwords with bcrypt, stores visitor IPs only as salted hashes, and gates
every admin/agent action behind a server-side session check. The items below are
the **deployment / configuration** steps that the code cannot enforce on its own.

## Before going live (do all of these)

1. **Lock CORS to your real domain.** In the production `.env`, set
   `ALLOWED_ORIGINS` to the exact frontend origin(s), never `*`:
   ```
   ALLOWED_ORIGINS=https://codekathax.online
   ```
   With `*`, the Origin check on state-changing requests is skipped.

2. **Set a strong `IP_SALT`.** Replace the placeholder with a long random secret
   (used to hash visitor IPs for rate limiting). Generate one with:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Never reuse the default `ckx-local-dev-salt-change-me`.

3. **Force HTTPS.** Serve the API only over TLS and redirect HTTP → HTTPS in
   hPanel / `.htaccess`. Bearer tokens must never travel over plain HTTP. The app
   already sends `Strict-Transport-Security` once the request is HTTPS.

4. **Turn off error display.** In production `php.ini` (or hPanel):
   ```
   display_errors = Off
   log_errors = On
   ```
   All errors are already caught and logged; clients only get generic messages.

5. **Protect secrets & uploads.**
   - `.env` lives outside `public/` and is git-ignored — keep it that way and
     restrict its file permissions (e.g. `600`).
   - `public/uploads/` holds client images; its `.htaccess` disables code
     execution. This relies on **Apache** (Hostinger shared hosting). If you ever
     move to nginx, replicate the "no script execution in uploads" rule.

6. **Run all migrations** in `database/migrations/` in order (001 → 011) on the
   production database.

7. **Set `APP_ENV=production`** (or just leave it unset) so the app warns
   loudly to the error log if `ALLOWED_ORIGINS` is ever left as `*`. Set
   `APP_ENV=development` only on your local machine.

8. **Schedule `bin/cleanup.php`** as a daily cron job (hPanel → Cron Jobs:
   `php /home/.../codekathax-api/bin/cleanup.php`). It prunes expired
   sessions, reset tokens, and old rate-limit rows — a backstop for the
   probabilistic inline cleanup the code already does on hot paths.

## Built-in protections (already in the code)

- **Auth:** opaque 256-bit tokens stored only as SHA-256 hashes; sliding 30-day
  sessions; live status re-check so suspended agents lose access immediately.
- **Credential rotation:** changing a password (or completing a reset)
  revokes every other active session for that account, so a leaked/stolen
  token can't outlive the change. Login timing is normalized (a dummy bcrypt
  check runs even when the email doesn't match any account) so responses
  can't be used to enumerate which emails have accounts.
- **Password reset:** single-use, 30-minute tokens (`password_resets`,
  stored hash-only) emailed via `/password-reset.php`; both steps return
  generic responses so the flow can't be used to enumerate accounts either.
- **Authorization:** every admin endpoint sits behind `ckx_require_admin`; agent
  endpoints are scoped to the session's own agent (no cross-account access).
  Agent signups/edits can't claim the admin's configured email.
- **Rate limits:**
  - Login: 10 failed attempts / 15 min **per IP** + 10 / 15 min **per account**,
    plus a stricter 5 / 30 min **IP-independent** cap on the admin account
    specifically, since it's the single highest-privilege account and has no
    second factor yet.
  - Password reset: 5 requests / 15 min per IP (send-volume cap, not a
    lockout) + 20 confirm attempts / 15 min per IP.
  - Project form: 8 / hour + 20s min gap per IP, plus a honeypot.
  - Agent signup: 2 / day per IP, plus a honeypot.
  - Public tracker (`status.php`): 60 lookups / minute per IP.
- **Injection/XSS:** prepared statements everywhere; email HTML escapes all
  user-supplied values.
- **Uploads:** MIME allow-list (JPG/PNG/WebP) + `getimagesize` check + random
  filename + forced extension + execution-blocked folder; max 5 images, 5 MB each.
- **CSRF:** bearer tokens (not cookies) + JSON-only + Origin allow-list on writes,
  with a startup warning if `ALLOWED_ORIGINS=*` reaches a non-development environment.
- **Headers:** `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Cross-Origin-Opener-Policy`, and `Strict-Transport-Security` (over HTTPS).

## Optional future hardening

- Two-factor auth for the owner account.
- Move rate limiting to a cache (APCu/Redis) if traffic grows, to avoid DB writes
  on hot paths.
