/* E-Concern PWA registration — scope-aware, safe on localhost + InfinityFree.
 * The SW file lives at the app root (same folder as manifest), so its default
 * scope already covers public/, admin/, api/, assets/. APP_BASE_URL spaces are
 * handled by app_url() server-side; here we just resolve relative to <base>. */
(function () {
  if (!('serviceWorker' in navigator)) return;
  /* Don't fight over HTTP except localhost (SW requires secure context). */
  var isLocal = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(window.location.hostname);
  if (!window.isSecureContext && !isLocal) return;

  function basePath() {
    var b = document.querySelector('base[href]');
    if (b) {
      try { return new URL(b.getAttribute('href'), window.location.href).pathname; } catch (e) { /* fall through */ }
    }
    var m = document.querySelector('link[rel="manifest"]');
    if (m) {
      try {
        var u = new URL(m.getAttribute('href'), window.location.href);
        /* Strip the manifest filename so we get the app root on both the
         * static (manifest.webmanifest) and PHP (manifest.php) routes. */
        return u.pathname.replace(/manifest\.(webmanifest|php).*$/, '');
      } catch (e) { /* fall through */ }
    }
    return '/';
  }

  /* Registers sw.js directly. The .htaccess <FilesMatch "^sw\.js$"> block
   * already sets Content-Type: application/javascript plus
   * Service-Worker-Allowed: /, which is everything the worker needs. A sw.php
   * proxy was tried and removed: InfinityFree's bot filter challenges .php
   * subresource requests just as often as .webmanifest, so it bought nothing
   * and only added a second URL to keep in sync. */
  function swUrl() {
    var base = basePath();
    if (base.charAt(base.length - 1) !== '/') base += '/';
    return base + 'sw.js';
  }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register(swUrl(), { scope: basePath() }).catch(function (err) {
      if (window.console && console.warn) console.warn('[pwa] SW registration failed:', err);
    });
  });
})();
