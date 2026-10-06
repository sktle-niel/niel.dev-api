# CODEKATHAX API

A tiny PHP + MySQL REST API for the CODEKATHAX website's **"Start a project"**
form. Each submission is **saved to MySQL** and **emailed** to the owner
(`niel.ladica07@gmail.com`).

No framework, no Composer — PHPMailer is vendored under `app/PHPMailer/`, so it
runs on plain Hostinger shared hosting.

## Endpoints

| Method | Path             | Description                                  |
| ------ | ---------------- | -------------------------------------------- |
| GET    | `/`              | Health check / endpoint list                 |
| POST   | `/requests.php`  | Create a project request (JSON body)         |

`POST /requests.php` accepts the form's JSON, validates it, stores a row in
`project_requests`, emails the details, and returns:

```json
{ "ok": true, "reference": "CKX-7G2K9Q", "emailed": true }
```

## Project layout

```
codekathaXbackend/
├─ app/                 # application code (never web-served)
│  ├─ PHPMailer/        # vendored PHPMailer (PHPMailer.php, SMTP.php, Exception.php)
│  ├─ bootstrap.php     # loads .env + helpers, returns config
│  ├─ config.php        # DB / CORS / mail settings from env
│  ├─ db.php            # PDO singleton
│  ├─ env.php           # tiny .env loader
│  ├─ http.php          # cors() / json_out() / read_json_body()
│  └─ mailer.php        # send_request_email()
├─ database/
│  └─ schema.sql        # the project_requests table (import in phpMyAdmin)
├─ public/              # web root (point the subdomain here)
│  ├─ index.php         # health check
│  └─ requests.php      # POST endpoint
├─ .env / .env.example  # config (real .env is git-ignored)
├─ DEPLOY.md            # Hostinger deployment guide
└─ start-api.bat        # run locally on http://localhost:8000
```

## Run locally (Windows + Laragon/XAMPP)

1. Start MySQL (Laragon or XAMPP).
2. In phpMyAdmin, create a database named **`codekathax`**, then **Import**
   `database/schema.sql`.
3. Copy `.env.example` to `.env` and adjust DB creds if needed. To test email,
   fill in `SMTP_USER` / `SMTP_PASS` (see DEPLOY.md). Leaving them blank still
   saves to the database — it just skips the email.
4. Double-click **`start-api.bat`** (or run `php -S localhost:8000 -t public`).
5. Visit <http://localhost:8000/> — you should see the health JSON.

The frontend points at this via `VITE_API_BASE` (see the website's `.env`).

See **DEPLOY.md** for putting this live on Hostinger.
