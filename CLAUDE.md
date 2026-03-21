# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Single-file PHP 8.2 fileshare. No framework, no Composer dependencies.

## Commands

- `composer serve` — start dev server at `http://localhost:8000` (uses `src/router.php` for clean URL routing)
- `composer build` — build `dist/fileshare.phar` and `dist/simple.min.css` for production deployment
- `GET /cron?secret=<CRON_SECRET>` — trigger expiry cleanup; requires `?secret=` matching `CRON_SECRET` in `.env`

## Architecture

All application logic lives in `src/index.php`. The webroot is `src/`, so everything outside it (`uploads/`, `data/`, `.env`) is not web-accessible.

```
src/
  index.php       — router, handlers, and HTML views
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
| `GET /download/{path}` | Serve file (403 if private and not logged in) |
| `POST /delete/{path}` | Delete file |
| `POST /toggle/{path}` | Toggle private/public |
| `POST /expiry/{path}` | Set expiry |
| `GET /cron?secret=<CRON_SECRET>` | Delete expired files (403 without valid secret) |

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

## PHAR packaging

Run `composer build` to produce `dist/fileshare.phar` and `dist/simple.min.css`. Requires `phar.readonly = Off` on the build machine (not needed on production).

Deploy by placing both dist files in the same directory alongside `uploads/`, `data/`, and `.env`:

```
/srv/fileshare/
├── fileshare.phar
├── simple.min.css
├── .env
├── uploads/
└── data/
```

Nginx config for PHAR deployment:

```nginx
server {
    listen 443 ssl;
    root /srv/fileshare;
    client_max_body_size 50M;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;

    location / {
        try_files $uri /fileshare.phar$is_args$args;
    }

    location ~ \.phar$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

`simple.min.css` is served directly by Nginx as a static file. All other requests are handled by PHP-FPM executing the PHAR.
