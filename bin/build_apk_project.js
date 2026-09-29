/* Generates a Bubblewrap/TWA project non-interactively.
 *
 * `bubblewrap init` always runs its confirmTwaConfig() prompt loop, which needs
 * a TTY; piping stdin into it dies with ERR_USE_AFTER_CLOSE. This script calls
 * the same generator the CLI uses, with values set explicitly.
 *
 * The manifest is read from the LOCAL server, not from InfinityFree: the host's
 * bot filter answers .webmanifest/.php subresource requests with an HTML
 * challenge page, which is exactly what broke the browser install. The icons
 * are fetched from localhost at build time and baked into the APK, so the
 * filter never touches them.
 *
 * Two apps are produced from one codebase:
 *   client -> apk/       ph.gov.marikina.econcern
 *   admin  -> apk-admin/ ph.gov.marikina.econcern.admin
 * They share a keystore, so assetlinks.json lists both package names.
 *
 * Usage: node bin\build_apk_project.js [client|admin]
 */
const path = require('path');
const os = require('os');
const fs = require('fs');

const CLI = 'C:/Users/Justin/AppData/Roaming/npm/node_modules/@bubblewrap/cli';
const core = require(path.join(CLI, 'node_modules/@bubblewrap/core'));
const shared = require(path.join(CLI, 'dist/lib/cmds/shared.js'));

const HOST = 'marikina-econcern-ba.infinityfree.io';
const KEYSTORE = path.join(os.homedir(), 'ekey.jks');
const KEY_ALIAS = 'econcern';
const PORT = 8099;

const APPS = {
  client: {
    manifest: 'manifest.apk.json',
    target: 'apk',
    packageId: 'ph.gov.marikina.econcern',
    name: 'E-Concern',
    launcherName: 'E-Concern',
    startUrl: '/public/index.php',
  },
  admin: {
    manifest: 'manifest.admin.json',
    target: 'apk-admin',
    packageId: 'ph.gov.marikina.econcern.admin',
    name: 'E-Concern Admin',
    launcherName: 'E-Concern Admin',
    // Shorter than the 12-char launcher limit is not required, but Android
    // truncates labels under the icon; keep it readable.
    startUrl: '/admin/login.php',
  },
};

const noopPrompt = {
  printMessage: () => {},
  promptInput: async () => { throw new Error('unexpected prompt'); },
};

/* The PHP built-in server serving the manifest is flaky under concurrent
 * requests and intermittently answers with its HTML error page, which surfaces
 * as `Unexpected token '<'`. Retry rather than fail the build over it. */
async function withRetry(attempt, attempts = 5) {
  let lastErr;
  for (let i = 1; i <= attempts; i++) {
    try {
      return await attempt();
    } catch (e) {
      lastErr = e;
      if (i < attempts) {
        console.log(`  attempt ${i} failed (${e.message}); retrying...`);
        await new Promise((r) => setTimeout(r, 500 * i));
      }
    }
  }
  throw lastErr;
}

async function generate(which) {
  const app = APPS[which];
  if (!app) {
    throw new Error(`unknown app "${which}" (expected: ${Object.keys(APPS).join(', ')})`);
  }
  const target = path.resolve(__dirname, '..', app.target);
  const localManifest = `http://127.0.0.1:${PORT}/${app.manifest}`;

  console.log(`\n=== ${which} -> ${app.target}/ ===`);
  fs.mkdirSync(target, { recursive: true });

  const m = await withRetry(() => core.TwaManifest.fromWebManifest(localManifest));

  m.host = HOST;
  m.startUrl = app.startUrl;
  m.name = app.name;
  m.launcherName = app.launcherName;
  m.packageId = app.packageId;
  m.appVersionCode = 1;
  m.appVersionName = '1';
  m.display = core.DisplayModes.STANDALONE;
  m.orientation = core.Orientations.PORTRAIT;
  m.signingKey = { path: KEYSTORE, alias: KEY_ALIAS };

  /* webManifestUrl is deliberately left pointing at the localhost server.
   * TwaGenerator re-fetches it to resolve shortcut icons, and the live URL
   * answers with InfinityFree's HTML challenge page — the build dies with
   * `Unexpected token '<'`. Icons are baked into the APK at generation time,
   * so the localhost value is a build-time artifact only and is never dialled
   * at runtime. */

  await m.saveToFile(path.join(target, 'twa-manifest.json'));
  const generator = new core.TwaGenerator();
  await shared.generateTwaProject(noopPrompt, generator, target, m);
  await shared.generateManifestChecksumFile(path.join(target, 'twa-manifest.json'), target);

  const d = m.toJSON ? m.toJSON() : m;
  console.log(`  packageId  : ${d.packageId}`);
  console.log(`  startUrl   : ${d.startUrl}`);
  console.log(`  iconUrl    : ${d.iconUrl}`);
  console.log(`  shortcuts  : ${(d.shortcuts || []).length}`);
  console.log(`  target     : ${app.target}/`);
}

(async () => {
  const which = process.argv[2] || 'client';
  const list = which === 'all' ? Object.keys(APPS) : [which];
  for (const name of list) {
    await generate(name);
  }
  console.log('\nNow run: bubblewrap build --manifest=./twa-manifest.json (inside each target dir)');
})().catch((e) => { console.error('ERR:', e.message); process.exit(1); });
