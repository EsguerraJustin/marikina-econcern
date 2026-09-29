/* E-Concern global bootstrap: appends CSRF header to jQuery AJAX automatically, + autosave helpers */
(function () {
  if (window.__econcernGlobalLoaded) return;
  window.__econcernGlobalLoaded = true;

  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? (meta.getAttribute('content') || '') : '';

  if (window.jQuery && token) {
    var $ = window.jQuery;
    $.ajaxSetup({
      headers: { 'X-CSRF-Token': token }
    });

    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
      if (options.type && ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(String(options.type).toUpperCase()) !== -1) {
        if (!jqXHR || typeof jqXHR.setRequestHeader !== 'function') return;
        jqXHR.setRequestHeader('X-CSRF-Token', token);
        if (!options.headers) options.headers = {};
        options.headers['X-CSRF-Token'] = token;
      }
    });
  }

  window.__csrfToken = token;
  window.__csrfFieldHtml = token
    ? '<input type="hidden" name="csrf_token" value="' + String(token).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }) + '" autocomplete="off">'
    : '';
})();
