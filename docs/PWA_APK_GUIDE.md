# PWA → APK guide (after the site is LIVE on InfinityFree)

You asked for: everything live + an installable mobile app + a real `.apk` file.
Correct order: **live site → verify PWA → build APK on your PC → install APK**.
The APK is a thin wrapper — it still calls your live InfinityFree backend
(requires internet; offline page shows when signal is gone).

## A. Verify PWA (in Chrome on the LIVE domain)

1. Open `https://YOUR-DOMAIN/` → F12 → Lighthouse → check
   Progressive Web App → all green (installable, manifest, icons, theme-color,
   viewport, offline page, `sw.js` controls page).
2. DevTools → Application → Manifest: icons 192/512/maskable load, no 404.
3. DevTools → Application → Service Workers: `sw.js` active, scope = `/`.
4. Phone test: Chrome on Android → ⋮ → Add to Home screen / Install app →
   launches standalone (no URL bar), splash shows Marikina logo.
5. If install prompt never appears: check HTTPS (not http), `manifest.webmanifest`
   reachable, icons reachable, `start_url` loads (200, not redirect loop).

## B. Path 1 — TWA via Bubblewrap (recommended, smallest APK, store-ready)

Needs: Node 18+, **JDK 17**, Android SDK. JDK 24 will NOT work — Gradle 8.11.1
dies with `Unsupported class file major version 68`.

> **Do not point `bubblewrap init` at the live domain.** InfinityFree's bot
> filter answers manifest and icon fetches with an HTML challenge page, so the
> build fails. `bubblewrap init` is also fully interactive and dies without a
> TTY (`ERR_USE_AFTER_CLOSE`), so `bin/build_apk_project.js` drives the same
> generator non-interactively from a localhost-served manifest.

```powershell
npm i -g @bubblewrap/cli

# 1. Point Bubblewrap at the JDK and Android SDK (one-time).
#    ~/.bubblewrap/config.json must be UTF-8 WITHOUT a BOM, or Bubblewrap
#    fails with `Unexpected token '\ufeff'`.
bubblewrap updateConfig --jdkPath "C:\Users\Justin\jdks\jdk-17" `
                        --androidSdkPath "$env:LOCALAPPDATA\Android\Sdk"

#    Modern SDKs have no top-level tools/ or bin/ folder, but Bubblewrap's
#    validator requires one. Bridge it:
New-Item -ItemType Junction -Path "$env:LOCALAPPDATA\Android\Sdk\bin" `
  -Target "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools\latest\bin"
```

```powershell
# 2. Serve the build manifest locally — the host's filter can't reach it.
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t .

# 3. Generate into apk/, then build.
node bin\build_apk_project.js
cd apk
$env:BUBBLEWRAP_KEYSTORE_PASSWORD = "..."   # set to skip the password prompt
$env:BUBBLEWRAP_KEY_PASSWORD       = "..."
bubblewrap build --manifest=./twa-manifest.json
# Output: app-release-signed.apk (+ .aab for the Play Store)
```

`manifest.apk.json` (build-only) differs from `manifest.webmanifest` (shipped):
`start_url` / `scope` / `id` are absolute live URLs, because Bubblewrap derives
the TWA host from the **manifest URL's origin** and would otherwise bake
`127.0.0.1:8099` into the app. Icons stay relative so they load from localhost
and get baked into the APK.

Then TWA verification (removes the browser bar). `assetlinks.json` is
**excluded from `deploy.zip` on purpose — upload it by hand**:

```powershell
keytool -list -v -keystore "$env:USERPROFILE\ekey.jks" -alias econcern
# copy the SHA256 fingerprint into .well-known/assetlinks.json
# Verify: https://YOUR-DOMAIN/.well-known/assetlinks.json returns JSON
```

Submit file: the `.apk` sideloads directly on any Android for defense.
The `.aab` is what Play Store wants later (not needed for class).

Known InfinityFree gotcha: free subdomains sometimes block custom cache headers —
`assetlinks.json` must return HTTP 200 `application/json` with no redirect.
If it 404s, check File Manager that `.well-known/` uploaded (dot-folders are
hidden in some FTP clients — use File Manager, enable show-hidden).

## C. Path 2 — Capacitor fallback (if TWA verification fails)

```powershell
npm i -g @capacitor/cli
npm init -y
npm i @capacitor/core @capacitor/cli @capacitor/android
npx cap init "E-Concern" "ph.gov.marikina.econcern" --web-dir="."
```

`capacitor.config.ts`:

```ts
import { CapacitorConfig } from '@capacitor/cli';
const config: CapacitorConfig = {
  appId: 'ph.gov.marikina.econcern',
  appName: 'E-Concern',
  server: { url: 'https://YOUR-DOMAIN/', cleartext: false },
};
export default config;
```

```powershell
npx cap add android
# Open android/ in Android Studio → Build → Build APK → app-debug.apk
```

Trade-off: bigger APK, WebView chrome (not Trusted Web Activity), but zero
dependence on `assetlinks.json`.

## D. What to bring to defense

1. Live URL on projector (everything live: citizen + admin + API).
2. Phone with PWA installed (Add to Home Screen) — shows mobile-app UX.
3. `.apk` file on USB + already installed on a second phone — satisfies
   "may APK file" requirement even if panel asks for the file.
4. One-liner: "PWA ang mobile app, TWA/Capacitor wrapper ang APK,
   iisang InfinityFree backend ang gamit ng lahat."
