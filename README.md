# Fileshare

A self-hosted file sharing app in a single PHP file. No framework, no Composer dependencies, no database.

<img width="1698" height="1014" alt="image" src="https://github.com/user-attachments/assets/81d5e234-25a7-44a6-9332-623a15235fe9" />


## Features

- Upload files into optional folder paths
- Public or private visibility per file
- Configurable expiry (1h – 30d, or never)
- Automatic expiry cleanup via a cron endpoint
- CSRF protection on all mutating actions
- Bcrypt password hashing, session fixation protection, brute-force delay
- `Content-Security-Policy` and `X-Content-Type-Options` headers

## Requirements

- PHP 8.2+ with the `fileinfo` extension
- Nginx (or Apache) — see configuration below

## Installation

```bash
git clone <repo> fileshare
cd fileshare
cp .env.example .env
```

Edit `.env`:

```bash
USERNAME=admin

# Generate a bcrypt hash for your password:
# php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
PASSWORD=$2y$12$...

# Generate a random cron secret:
# php -r "echo bin2hex(random_bytes(32));"
CRON_SECRET=...
```

Create the required directories and make them writable by the PHP-FPM user:

```bash
mkdir -p uploads data
chown www-data: uploads data   # replace www-data with your PHP-FPM user if different
```

## Development server

```bash
composer serve
# → http://localhost:8000
```

## Project structure

```
src/
  index.php       — bootstrap, constants, router
  handlers.php    — route handlers and view renderers
  helpers.php     — env loading, metadata I/O, path and output utilities
  router.php      — PHP built-in server router (dev only)
  simple.min.css  — Simple.css (local copy)
  views/
    login.php     — login page
    dashboard.php — file management dashboard
uploads/          — uploaded files (mirrors user-supplied folder structure)
data/
  files.json      — file metadata (path, private, expires, uploaded)
  .lock           — flock coordination file (created automatically)
.env              — credentials and secrets (not web-accessible)
```

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/` | Login form (unauthenticated) or dashboard |
| `POST` | `/login` | Authenticate |
| `GET` | `/logout` | End session |
| `POST` | `/upload` | Upload a file |
| `GET` | `/download/{path}` | Download a file (403 if private and not logged in) |
| `POST` | `/delete/{path}` | Delete a file |
| `POST` | `/toggle/{path}` | Toggle public/private |
| `POST` | `/expiry/{path}` | Set or update expiry |
| `GET` | `/cron?secret=<CRON_SECRET>` | Delete expired files (403 without valid secret) |

## Nginx configuration

```nginx
server {
    listen 443 ssl;
    root /path/to/fileshare/src;

    # Match the application's 50 MB limit
    client_max_body_size 50M;

    # HSTS — remove max-age line and re-deploy if you ever need to revert to HTTP
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

> **Checklist before going live**
> - Set `client_max_body_size` to match `MAX_UPLOAD_BYTES` (50 MB)
> - Enable TLS and configure the `ssl_certificate` / `ssl_certificate_key` directives
> - Add the `Strict-Transport-Security` header only after TLS is confirmed working
> - Confirm `uploads/`, `data/`, and `.env` are outside the Nginx `root`
> - Confirm `uploads/` and `data/` are writable by the PHP-FPM user (`chown www-data: uploads data`)
> - Set `upload_max_filesize = 50M` and `post_max_size = 52M` in `php.ini`

## Cron

Set up a system cron to trigger expiry cleanup. The `CRON_SECRET` from `.env` is required as a query parameter:

```
# Run expiry cleanup every hour
0 * * * * curl -s "https://example.com/cron?secret=<CRON_SECRET>"
```

## Security notes

- `uploads/`, `data/`, and `.env` are outside the webroot and not served by Nginx
- All file paths are validated with `realpath()` against `UPLOADS_DIR` to prevent path traversal
- Passwords are stored as bcrypt hashes; login has a 300 ms fixed delay against brute force
- Session ID is rotated on login to prevent session fixation
- The cron endpoint is protected by a secret compared with `hash_equals()` to prevent timing attacks
- CSRF tokens are required on all POST forms
