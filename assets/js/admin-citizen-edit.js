/* =========================================================================
   Super-admin "Edit citizen identity" behaviour.
   Pairs with includes/partials/admin_citizen_edit_modal.php, which ships the
   markup on both admin/citizens.php and admin/citizen_view.php. The page
   supplies the endpoint and calls window.__openCitizenEdit({...}).

   POST <endpoint>  action=update, id, first_name, last_name, email, mobile,
                    barangay, [mark_verified=1]
   -> 200 { ok, citizen, email_changed, marked_unverified, changed_fields, message }
   -> 4xx { ok:false, error }

   The verification warning is derived from whether the email actually differs
   from the value the row had when the modal opened, so it only appears when it
   is going to do something. Unchecking "mark verified" cannot silently cancel
   the warning: if the admin unchecks it, the default (force re-verification)
   applies again, which is the safe direction.
   ========================================================================= */
(function () {
  "use strict";

  var cfg = null;
  var modal = null;
  var originalEmail = "";

  function csrf() {
    if (typeof window.__csrfToken === "string" && window.__csrfToken) return window.__csrfToken;
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute("content") || "") : "";
  }

  function el(id) { return document.getElementById(id); }

  /* The id contract with includes/partials/admin_citizen_edit_modal.php.
   *
   * Every precondition in __openCitizenEdit used to be a bare `return`, and one
   * el() call was unguarded. A single id typo therefore produced a SILENT dead
   * button: the modal was constructed, the fields were written, then the
   * assignment threw and the exception skipped modal.show() further down. An
   * admin clicked Edit and got no dialog, no message and no console output they
   * would necessarily have looked for - on two pages at once.
   *
   * So assert the whole list up front, before the modal is built, and say which
   * ids are missing. A contract that cannot report its own breakage gets broken
   * again. */
  var REQUIRED_IDS = [
    "citEdFirst", "citEdLast", "citEdEmail", "citEdMobile", "citEdBrgy",
    "citEdTarget", "citEdSaveBtn", "citizenEditForm"
  ];
  function missingIds() {
    return REQUIRED_IDS.filter(function (id) { return !el(id); });
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function alertBox(kind, msg) {
    var box = el("citEdAlert");
    if (!box) return;
    box.innerHTML = '<div class="alert alert-' + kind + ' small mb-0">' + esc(msg) + "</div>";
    if (typeof window.__renderLucide === "function") window.__renderLucide();
  }

  function clearAlert() {
    var box = el("citEdAlert");
    if (box) box.innerHTML = "";
  }

  /* Show/hide the consequence of changing the address. Called on open, on
     every keystroke in the email field, and when the override is toggled. */
  function syncVerifyState() {
    var email = el("citEdEmail");
    var warn = el("citEdVerifyWarn");
    var wrap = el("citEdVerifiedWrap");
    var mark = el("citEdMarkVerified");
    if (!email || !warn || !wrap) return;

    var changed = email.value.trim().toLowerCase() !== originalEmail;
    warn.classList.toggle("d-none", !changed);
    wrap.classList.toggle("d-none", !changed);
    if (!changed && mark) mark.checked = false;
  }

  window.__openCitizenEdit = function (options) {
    cfg = Object.assign({}, cfg || {}, options || {});
    if (!cfg || !cfg.endpoint || !cfg.id) {
      if (window.console && console.warn) {
        console.warn("[citizen-edit] not opening: endpoint or id missing", cfg);
      }
      return;
    }

    /* Checked BEFORE the modal is constructed: a broken contract should not
       build a dialog it is never going to show. */
    var absent = missingIds();
    if (absent.length) {
      if (window.console && console.error) {
        console.error("[citizen-edit] modal markup is missing id(s): " + absent.join(", ")
          + " - check includes/partials/admin_citizen_edit_modal.php against this file");
      }
      return;
    }

    if (!modal) {
      var node = el(cfg.modalId || "citizenEditModal");
      if (!node || typeof bootstrap === "undefined") {
        if (window.console && console.error) {
          console.error("[citizen-edit] cannot open: modal node or bootstrap missing", cfg.modalId);
        }
        return;
      }
      modal = new bootstrap.Modal(node);
    }

    var c = cfg.citizen || {};
    el("citEdFirst").value = c.first_name || "";
    el("citEdLast").value = c.last_name || "";
    el("citEdEmail").value = c.email || "";
    el("citEdMobile").value = c.mobile || "";
    el("citEdBrgy").value = c.barangay || "";
    el("citEdMarkVerified").checked = false;
    originalEmail = String(c.email || "").trim().toLowerCase();

    var name = ((c.first_name || "") + " " + (c.last_name || "")).trim() || ("Citizen #" + cfg.id);
    var verifiedNow = c.email_verified_at ? "Currently verified." : "Currently NOT verified.";
    el("citEdTarget").innerHTML =
      "<strong>" + esc(name) + "</strong> &middot; Citizen #" + esc(cfg.id) + "<br>"
      + '<span class="text-muted">' + esc(verifiedNow) + "</span>";

    clearAlert();
    syncVerifyState();
    modal.show();
    if (typeof window.__renderLucide === "function") window.__renderLucide();
  };

  function save(e) {
    if (e) e.preventDefault();
    if (!cfg || !cfg.endpoint) return;

    var btn = el("citEdSaveBtn");
    if (btn && btn.disabled) return;
    if (btn) btn.disabled = true;
    clearAlert();

    var payload = {
      action: "update",
      id: cfg.id,
      csrf_token: csrf(),
      first_name: el("citEdFirst").value,
      last_name: el("citEdLast").value,
      email: el("citEdEmail").value,
      mobile: el("citEdMobile").value,
      barangay: el("citEdBrgy").value
    };
    if (el("citEdMarkVerified").checked) payload.mark_verified = "1";

    $.ajax({
      url: cfg.endpoint,
      method: "POST",
      data: payload,
      dataType: "json",
      timeout: 45000,
      headers: { "X-CSRF-Token": csrf() }
    }).done(function (res) {
      if (!res || !res.ok) {
        alertBox("danger", (res && res.error) ? res.error : "Update failed.");
        return;
      }
      var msg = (res && res.message) ? res.message : "Citizen updated.";
      if (res.changed_fields && res.changed_fields.length) {
        msg += " Changed: " + res.changed_fields.join(", ").replace(/_/g, " ") + ".";
      }
      alertBox(res.marked_unverified ? "warning" : "success", msg);
      if (typeof window.__renderLucide === "function") window.__renderLucide();
      if (typeof window.__onCitizenEdited === "function") window.__onCitizenEdited(res);
      setTimeout(function () {
        if (modal) modal.hide();
      }, 1400);
    }).fail(function (xhr) {
      var m = "Network error. Please try again.";
      if (xhr && xhr.status === 401) {
        m = "Your session expired. Please sign in again.";
      } else if (xhr && xhr.status === 403) {
        /* require_csrf_token() answers 403 with hint=csrf_mismatch; it used to
           answer 419, which this Apache rewrites to 500, so a check for 419
           could never match. A 403 without the hint is the real refusal. */
        var hint = "";
        try { hint = (xhr.responseJSON && xhr.responseJSON.hint) || ""; } catch (e) { hint = ""; }
        m = (hint === "csrf_mismatch")
          ? "Security token expired. Please reload the page and try again."
          : "Super Admin access is required to edit citizen information.";
      }
      alertBox("danger", m);
    }).always(function () {
      if (btn) btn.disabled = false;
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    var form = el("citizenEditForm");
    if (form) form.addEventListener("submit", save);
    var email = el("citEdEmail");
    if (email) {
      email.addEventListener("input", syncVerifyState);
      email.addEventListener("change", syncVerifyState);
    }
    var mark = el("citEdMarkVerified");
    if (mark) mark.addEventListener("change", syncVerifyState);
  });
})();
