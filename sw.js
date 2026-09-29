/* Marikina E-Concern — Service Worker (PWA)
 * Scope-aware: works at domain root AND in a subfolder (InfinityFree or XAMPP).
 * Strategy:
 *  - Static assets (CSS/JS/img/icons/fonts): cache-first, versioned by CACHE_VERSION bump
 *  - PHP pages / API: network-first with offline fallback to public/offline.php
 *  - Never cache POST, admin setup/recovery, or verification links
 */
'use strict';

var CACHE_VERSION = 'econcern-v1';
var STATIC_CACHE = 'econcern-static-' + CACHE_VERSION;
var PAGES_CACHE = 'econcern-pages-' + CACHE_VERSION;

/* Resolved at install time from registration scope, so the same file works at
 * "/" and at "/htdocs-subfolder/" without edits. */
function scopeRoot() {
  try {
    var s = (self.registration && self.registration.scope) || self.location.href;
    return new URL('./', s).toString();
  } catch (e) {
    return self.location.href;
  }
}

function toScopeUrl(rel) {
  try {
    return new URL(rel, scopeRoot()).toString();
  } catch (e) {
    return rel;
  }
}

/* manifest.php, not manifest.webmanifest: InfinityFree's bot filter answers
 * the static .webmanifest with an HTML challenge page, which Chrome reports as
 * "Manifest: Line: 1, column: 1, Syntax error" and refuses to install. The PHP
 * route returns the same bytes on a request class the filter lets through. */
var CORE_ASSETS = [
  'manifest.php',
  'public/offline.php',
  'assets/css/variables.css',
  'assets/css/common.css',
  'assets/js/app.js',
  'assets/js/pwa-register.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/maskable-512.png',
  'assets/icons/apple-touch-icon.png',
  'assets/img/MarikinaLogo.jpg',
  'assets/img/Marikina-e-Concern.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(function (cache) {
      var urls = CORE_ASSETS.map(toScopeUrl);
      return cache.addAll(urls).catch(function () {
        /* Best-effort: missing one asset must not break install. */
        return Promise.all(
          urls.map(function (u) {
            return cache.add(u).catch(function () { return null; });
          })
        );
      });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.map(function (k) {
          if (k !== STATIC_CACHE && k !== PAGES_CACHE) return caches.delete(k);
          return null;
        })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

function isStaticRequest(url) {
  return (
    /\.(css|js|png|jpg|jpeg|gif|webp|svg|woff2?|ico)(\?.*)?$/.test(url.pathname) ||
    url.pathname.indexOf('/assets/') !== -1
  );
}

function isUncacheable(url, request) {
  if (request.method !== 'GET') return true;
  if (url.pathname.indexOf('/admin/setup.php') !== -1) return true;
  if (url.pathname.indexOf('/verify_email.php') !== -1) return true;
  if (url.pathname.indexOf('/reset_password.php') !== -1) return true;
  return false;
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  var url;
  try {
    url = new URL(request.url);
  } catch (e) {
    return;
  }
  /* Only handle same-origin. CDN (bootstrap/jquery/fonts) stays network-only. */
  if (url.origin !== self.location.origin) return;
  /* Only handle requests inside our scope (subfolder-safe). */
  if (url.href.indexOf(scopeRoot()) !== 0) return;
  if (isUncacheable(url, request)) return;

  if (isStaticRequest(url)) {
    event.respondWith(
      caches.match(request, { ignoreSearch: true }).then(function (hit) {
        if (hit) return hit;
        return fetch(request).then(function (res) {
          if (res && res.ok) {
            var copy = res.clone();
            caches.open(STATIC_CACHE).then(function (c) { c.put(request, copy); });
          }
          return res;
        });
      })
    );
    return;
  }

  /* Pages + API: network-first, offline fallback for navigations. */
  event.respondWith(
    fetch(request).then(function (res) {
      if (res && res.ok && request.method === 'GET') {
        var copy = res.clone();
        caches.open(PAGES_CACHE).then(function (c) { c.put(request, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(request, { ignoreSearch: true }).then(function (hit) {
        if (hit) return hit;
        if (request.mode === 'navigate') {
          return caches.match(toScopeUrl('public/offline.php'));
        }
        return Response.error();
      });
    })
  );
});
