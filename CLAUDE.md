# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Single-file PHP 8.2 fileshare. No framework, no Composer dependencies.

## Commands

- `composer serve` — start dev server at `http://localhost:8000` (uses `src/router.php` for clean URL routing)
- `GET /cron` — trigger expiry cleanup (configure a system cron to hit this endpoint)

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
.env              — USERNAME and PASSWORD (copy from .env.example)
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
| `GET /cron` | Delete expired files |

**Path safety** — all upload/download/delete operations verify `realpath()` stays within `realpath(UPLOADS_DIR)`.

**Metadata** — `data/files.json` is a JSON array. Read with `loadMeta()`, write with `saveMeta()`. `findIndex()` looks up an entry by `path`.

## Nginx config

```nginx
root /path/to/fileshare/src;

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```
