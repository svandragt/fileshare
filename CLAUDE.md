# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP 8.2 fileshare. No framework, no Composer, no database — the only requirement is PHP with `ext-fileinfo`.

## Commands

- `make serve` — start dev server at `http://localhost:8000` (override with `PORT=8080`; uses `src/router.php` for clean URL routing)
- `make test` — run the smoke test; no framework, exits non-zero on failure
- `make lint` — syntax-check every PHP file
- `make check` — lint and test, the same commands CI runs
- `make help` — list the targets
- `GET /cron` — trigger expiry cleanup; requires an `X-Cron-Secret` header (or legacy `?secret=`) matching `CRON_SECRET` in `.env`

## Architecture

The webroot is `src/`, so everything outside it (`uploads/`, `data/`, `.env`) is not web-accessible.

```
src/
  index.php       — config, bootstrap, and the routing match expression
  handlers.php    — one handler function per route
  helpers.php     — metadata, auth, and path-safety helpers
  views/          — HTML templates
  router.php      — PHP built-in server router (dev only)
  simple.min.css  — local copy of Simple CSS
uploads/          — uploaded files, mirroring user-supplied folder structure
data/
  files.json      — metadata array: { path, private, expires (unix ts|null), uploaded }
.env              — USERNAME, PASSWORD (bcrypt hash), CRON_SECRET (copy from .env.example)
```

**Routing** — `src/index.php` parses `REQUEST_URI` and dispatches via a `match` expression:

| Path | Action |
|---|---|
| `GET /` | Login form or dashboard |
| `POST /login` | Authenticate |
| `GET /logout` | End session |
| `POST /upload` | Upload file |
| `POST /api/upload` | Upload file with `Authorization: Bearer $API_UPLOAD_SECRET`; JSON reply, `{error, max_bytes}` on a size rejection |
| `GET /download/{path}` | Serve file (403 if private and not logged in) |
| `GET /view/{path}` | Render HTML file inline in a sandboxed page (404 for non-HTML) |
| `POST /delete/{path}` | Delete file |
| `POST /toggle/{path}` | Toggle private/public |
| `POST /expiry/{path}` | Set expiry |
| `GET /cron` | Delete expired files (403 without valid secret) |

**Path safety** — all upload/download/delete operations verify `realpath()` stays within `realpath(UPLOADS_DIR)`.

**Metadata** — `data/files.json` is a JSON array. Read with `loadMeta()`, write with `saveMeta()`. `findIndex()` looks up an entry by `path`.

## Nginx config

```nginx
server {
    listen 443 ssl;
    root /path/to/fileshare/src;
    client_max_body_size 50M;  # must match MAX_UPLOAD_BYTES in index.php

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

Also set `upload_max_filesize = 50M` and `post_max_size = 52M` in `php.ini`.
