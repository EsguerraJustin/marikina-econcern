# Marikina E-Concern — Citizen Portal

A PHP/MySQL citizen-reporting portal for the City Government of Marikina, with a
BasuraAlert (waste collection) module, a PWA, and a Trusted Web Activity Android
app built from that PWA.

- **Live site:** `https://marikina-econcern-ba.infinityfree.io`
- **Package:** `ph.gov.marikina.econcern`
- **Stack:** PHP 8.1+, MySQL, Bootstrap 5, vanilla JS, Service Worker, Bubblewrap TWA

## Modules

| Area | What it does |
|---|---|
| **Citizen** | Register, verify by email, log in, submit concerns with photo evidence, track status |
| **Admin** | Department-scoped triage, assignment, status updates, exports |
| **BasuraAlert** | Waste collection schedules, drop-off map, announcements, reminders, SMS/email notifications |

## Layout

```
admin/          Admin UI + admin JSON API
api/            Citizen-facing JSON endpoints
assets/         CSS, JS, icons, images
bin/            Build + maintenance scripts (see below)
database/       Schema and migrations, including full InfinityFree install SQL
docs/           Deployment, PWA and APK guides
includes/       Shared code: config, auth, helpers, partials, BasuraAlert
public/         Citizen-facing pages
storage/        Runtime fixtures for the app's mock mode
tests/          PHPUnit tests
```

## Setup

1. Copy `.env.example` to `.env` and fill in the values.
2. Import `database/full_install_infinityfree.sql` (or `schema.sql` plus the
   migrations) via phpMyAdmin.
3. Make `uploads/` and `storage/mock_images/` writable.
4. Open `/admin/setup.php` once to create the first super admin. It is gated by
   `SETUP_RECOVERY_KEY` — set a temporary key, create the account, then empty
   the key again.

## Scripts

| Script | Purpose |
|---|---|
| `bin/lint.php` | PHP syntax check across the project |
| `bin/build_infinityfree_sql.php` | Builds the phpMyAdmin-ready install file |
| `bin/build_deploy_zip.php` | Packs a deploy-safe archive for InfinityFree File Manager |
| `bin/build_github_zip.php` | Packs a source-only archive safe for a public repo (self-verifying) |
| `bin/build_apk_project.js` | Generates the TWA project non-interactively, then `bubblewrap build` |
| `bin/migrate.php`, `bin/recover_admin.php` | Maintenance |

Run PHP scripts with the zip extension where needed:

```powershell
C:\xampp\php\php.exe -d extension=zip bin\build_github_zip.php
```

## PWA and Android apps

The web app is installable as a PWA and is also packaged as native Android
apps via **Trusted Web Activity**. Full instructions are in
[`docs/PWA_APK_GUIDE.md`](docs/PWA_APK_GUIDE.md).

Two separate APKs are built from one codebase:

| App | Package | Launches at | Build dir |
|---|---|---|---|
| E-Concern (citizen) | `ph.gov.marikina.econcern` | `/public/index.php` | `apk/` |
| E-Concern Admin | `ph.gov.marikina.econcern.admin` | `/admin/login.php` | `apk-admin/` |

They install side by side (different package IDs) and carry distinct launcher
icons — the Marikina seal for the citizen app, the Basura logo for admin. Both
are signed with the same keystore, so `.well-known/assetlinks.json` lists both
package names.

```powershell
node bin\build_apk_project.js all        # generates both projects
cd apk          ; bubblewrap build --manifest=./twa-manifest.json
cd ..\apk-admin ; bubblewrap build --manifest=./twa-manifest.json
```

Icons are regenerated from the original artwork with `bin/build_icons.ps1`.

Two host-specific details worth knowing:

- The manifest is served through **`manifest.php`**, not the static
  `.webmanifest`. InfinityFree's bot filter answers the static file with an HTML
  challenge page, which Chrome reports as
  `Manifest: Line: 1, column: 1, Syntax error` and refuses to install.
- The TWA build must **not** fetch the manifest from the live domain — the same
  filter would hand Bubblewrap the challenge page. `manifest.apk.json` is served
  from localhost for the build instead. See `bin/build_apk_project.js`.

TWA verification needs `.well-known/assetlinks.json` to list the signing
certificate's SHA-256 fingerprint. Without it the app falls back to a Custom Tab
and shows a browser URL bar.

## Security notes

`.env` is never committed — it is excluded from both archives and listed in
`.gitignore`. It holds live database credentials and third-party API keys
(Brevo, Cloudinary, TextBee SMS, Calendarific, OpenWeather), so treat it as
secret and rotate any value that is ever exposed.

`bin/build_github_zip.php` re-reads `.env` after packing and aborts if any real
secret value appears in the output. Run it rather than zipping the folder by
hand.
