# Jiyan Pan (极验云网盘)

**English** · [中文](README.md)

A lightweight, private, PHP-based multi-user cloud drive with public direct-link sharing.

> **Live demo (static)**: <https://kunkun24.github.io/jiyan-pan/> — UI only, no backend.

- Open-sourced by TG Kuankun [@sad1881](https://t.me/sad1881) — Kuankun also takes on all kinds of projects, feel free to reach out.
- Built with DeepSeek V4.1 / V4 Pro. **When reposting or redistributing, please respect the original work and keep this copyright notice.**

---

## Features

- 📤 **Upload any file type**: drag and drop to upload; admins can configure the per-file limit (2GB by default)
- 🔗 **Public direct links**: generate a direct link in one click, cap the download count or set an expiry, and revoke it at any time
- 👥 **Multi-user**: email sign-up (no verification required); registration can be disabled at deploy time
- 🗂️ **File list / My files**: public files are visible across the whole platform, private files only to their owner
- ⏱️ **Daily check-in**: earn points each day and redeem them for a larger per-file limit (50MB by default, up to 200MB)
- 📍 **Download and upload logging**: records the IP and city of every download and upload (ip2region)
- 🗑️ **Automatic expiry cleanup**: regular users' files are cleaned up after 30 days by default; admin files are kept forever
- 🛡️ **Admin panel**: account management (ban / unban / reset password / adjust points / delete), file management, system settings
- 🖥️ **iOS-style landing page**: pure black, white and grey palette — no gradients, no purple

## Screens and Functionality

| Page | Description |
| ---- | ---- |
| Landing page | Served at the root path for signed-out visitors; iOS style, frosted-glass nav + hero + feature sections |
| Login / Sign-up | `?login`; email sign-up with no verification |
| My files | Upload, rename, make public / hide, generate direct links, delete |
| File list | All public files on the platform; download directly once signed in |
| Upload file | Quota check + public toggle + illegal-content warning |
| Admin panel | `?admin=1`; account management / file management / system settings |

## Directory Structure

```
├── index.php             # Single entry point: landing page / login / drive / admin panel
├── lib.php               # Shared function library (DB, auth, points, cleanup, etc.)
├── dl.php                # Public direct-link download entry  /d/<token>
├── cleanup.php           # Expired-file cleanup (cron)
├── config.php            # Configuration (database, storage, points rules, etc.)
├── database.sql          # Database schema (no data)
├── nginx.conf.example    # Example Nginx configuration
├── .user.ini.example     # Example PHP site configuration
├── assets/               # Static assets such as the logo
├── lib/                  # ip2region lookup classes (third-party, Apache-2.0)
├── data/                 # ip2region data files (download them yourself, see data/README.md)
└── storage/              # Uploaded-file storage (created at runtime; direct access blocked in nginx)
```

## Tech Stack

- PHP 8.x (no framework, no Composer dependencies)
- MySQL / MariaDB (PDO)
- Nginx (relies on `X-Accel-Redirect` for efficient file delivery)
- Vanilla JavaScript (fetch / FormData / XHR upload progress)

## Quick Start

See [INSTALL.md](./INSTALL.md) for the full deployment steps. In short:

1. Upload the source code to your site directory and configure Nginx (see `nginx.conf.example`)
2. Import `database.sql` to create the tables
3. Edit `config.php` with your database details
4. Configure `.user.ini` (see `.user.ini.example`) and the Nginx upload limit
5. Download the ip2region data files (see `data/README.md`)
6. Register an account, then promote it to administrator (see INSTALL.md)
7. Set up a cron job for `cleanup.php`

## Security Notes

- The `storage/`, `data/` and `lib/` directories are `deny all` in Nginx; files are served only through `dl.php`
- Direct requests to `config.php` and `lib.php` return 404
- Database credentials and similar secrets live only in `config.php` — never commit your real configuration to a public repository

## Disclaimer

This program is intended for learning and exchange and for lawful private file sharing. You are responsible for what you upload;
**uploading any illegal or non-compliant content is strictly prohibited**. If you use it for unlawful purposes, you bear all consequences.

## License

[MIT](./LICENSE) © 2026 Jiyan Pan · TG Kuankun @sad1881

> `lib/Searcher.class.php` and `data/ip2region_v4.xdb` come from
> [lionsoul2014/ip2region](https://github.com/lionsoul2014/ip2region) and are licensed under Apache-2.0.
