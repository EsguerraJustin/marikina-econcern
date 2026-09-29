/* =========================================================================
   Live password-rule checklist.

   Both profile pages render the same markup:

     <div data-live-password-rules="#newPassword">
       <div class="mc-pwrule" data-rule="len">...At least 8 characters</div>
       ...
     </div>

   The consumer for it shipped in only one place, so the admin checklist
   rendered permanently neutral. It lives here instead so the two pages
   cannot drift, and it mirrors validate_password_rules() in
   includes/helpers.php so the list matches the server verdict.

   Call window.__initLivePasswordRules() after this file has executed.
   Load it as a plain (non-deferred) <script> immediately BEFORE the page
   script that calls it: a `defer`red copy has not run yet when a page
   script executes during parsing, so the checklist would never bind.
   ========================================================================= */
(function () {
  "use strict";

  var TESTS = {
    len: function (v) { return v.length >= 8; },
    low: function (v) { return /[a-z]/.test(v); },
    up:  function (v) { return /[A-Z]/.test(v); },
    num: function (v) { return /[0-9]/.test(v); },
    sym: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };

  function bind(host) {
    if (!host || host.dataset.rulesBound === "1") return;

    var field = document.querySelector(
      host.getAttribute("data-live-password-rules") || "#newPassword"
    );
    if (!field) return;

    var items = host.querySelectorAll("[data-rule]");
    if (!items.length) return;

    function paint() {
      var value = String(field.value || "");
      for (var i = 0; i < items.length; i++) {
        var test = TESTS[items[i].getAttribute("data-rule")];
        items[i].classList.toggle("mc-pwrule-ok", !!test && test(value));
      }
    }

    host.dataset.rulesBound = "1";
    field.addEventListener("input", paint);
    paint();
  }

  window.__initLivePasswordRules = function (root) {
    var hosts = (root || document).querySelectorAll("[data-live-password-rules]");
    for (var i = 0; i < hosts.length; i++) bind(hosts[i]);
  };
})();
