# Deploy to InfinityFree (free hosting) — step by step

Target: `Marikina Concern/Marikina Concern/` live at `https://YOUR-ACCOUNT.infinityfreeapp.com`
with everything live (pages + `api/` + MySQL). PWA/APK steps are in `PWA_APK_GUIDE.md`.

## 0. What I already did in code (no action needed)

- PWA core added: `manifest.webmanifest`, `sw.js`, `assets/js/pwa-register.js`,
  `public/offline.php`, icons in `assets/icons/`, PWA tags in
  `includes/partials/head.php`, SW registration in `includes/partials/foot.php`.
- `.htaccess` allows `.well-known/` through the dotfile block + serves
  `.webmanifest` / `assetlinks.json` correctly + forces HTTPS on non-localhost.
- `bin/build_infinityfree_sql.php` builds one phpMyAdmin file.
  `bin/build_deploy_zip.php` builds an upload-safe `deploy.zip`.
  `.env.infinityfree.example` is the prod env template.

## 1. InfinityFree account (you do this in browser)

1. Sign up at infinityfree.com → Create Account → choose free subdomain
   (e.g. `marikina-econcern.infinityfreeapp.com`) or add your own domain.
2. Open Control Panel → MySQL Databases → Create database (note all 4):
   `DB_HOST` (like `sqlXXX.infinityfree.com`), `DB_USER`, `DB_PASS`, `DB_NAME`.
3. Control Panel → PHP version → select **8.1+** (app requires `>=8.1`).
4. No SSH/composer/cron on free tier — everything below uses File Manager + phpMyAdmin.

## 2. Build locally (run in PowerShell)

```powershell
cd "C:\xampp\htdocs\Marikina Concern\Marikina Concern"
C:\xampp\php\php.exe bin\build_infinityfree_sql.php
C:\xampp\php\php.exe -d extension=zip bin\build_deploy_zip.php
C:\xampp\php\php.exe bin\lint.php
```

You get: `database/full_install_infinityfree.sql` + `deploy.zip`.

## 3. Import database (phpMyAdmin)

1. Control Panel → phpMyAdmin → open your new database.
2. Import → choose `database/full_install_infinityfree.sql` → Go.
3. Verify tables exist: `users, admins, departments, concern_types, concerns`,
   plus BasuraAlert tables (`ba_*`), plus `migrations`.
4. First admin: open `admin/setup.php` once on the live site (gated by
   `SETUP_RECOVERY_KEY` — set a temp key in `.env`, create super admin, then
   **empty the key again**).

## 4. Upload files (File Manager — recommended over FTP for first deploy)

1. Control Panel → File Manager → open `htdocs/`.
2. Upload `deploy.zip` → Extract → move contents so `htdocs/index.php`,
   `htdocs/public/`, `htdocs/admin/`, `htdocs/.htaccess` exist at top level
   (deploy at **domain root** — required later for TWA `assetlinks.json`).
3. In `htdocs/`, copy `.env.infinityfree.example` to `.env` (shipped in the zip)
   and fill the 6 values: `APP_PUBLIC_URL` + 4× `DB_*` + optional
   `SETUP_RECOVERY_KEY`. Set `APP_BASE_URL=""` (root deploy).
4. Create writable dirs (if missing): `uploads/`, `storage/mock_images/`.
   If photo upload gives 500, check perms — InfinityFree File Manager → chmod 755.

## 5. Smoke test (must all pass before PWA/APK)

- `https://YOUR-DOMAIN/` → lands on login (not XAMPP redirect loop).
- Login + register + verify-email link uses live domain (not localhost).
- Submit concern with photo → visible in `my_concern.php` + admin `concerns.php`.
- `https://YOUR-DOMAIN/manifest.php` returns JSON. Use this one, not
  `manifest.webmanifest`: InfinityFree's bot filter answers the static file
  with an HTML challenge page, which Chrome reports as
  `Manifest: Line: 1, column: 1, Syntax error` and refuses to install.
  `head.php` links `manifest.php` for exactly this reason.
- `https://YOUR-DOMAIN/sw.js` returns JS with `no-cache` header.
- `https://YOUR-DOMAIN/public/offline.php` loads without login.
- DevTools → Application → Manifest shows icons 192/512/maskable, no errors.
- DevTools → Application → Service workers shows `sw.js` activated.

## 6. Rollback (if anything breaks)

- Keep previous working `deploy.zip` + a phpMyAdmin Export (.sql) before each
  re-deploy. Restore = re-upload old zip + re-import old SQL. MTTR target <30min.

## 7. What NOT to upload

`.env`, `app_error.log`, `vendor/`, `tests/`, `storage/qa_harnesses/`,
`storage/mock_emails/`, `storage/mock_images/` contents, `*.md`, `*.log`,
`*.zip`, `node_modules/`, `docs/` — `bin/build_deploy_zip.php` already excludes these.
Never upload the outer `repomix-output.xml` (full code dump).
