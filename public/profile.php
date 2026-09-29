<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';
require_once __DIR__ . '/../includes/Avatar.php';

$pageTitle = 'Profile';
$activeNav = 'profile';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$barangays = ba_list_barangays($mysqli);
$prefs = ba_get_user_prefs($mysqli, (int) $user['id']);

$currentBarangay = (string) ($user['barangay'] ?? '');
$barangayOptions = [];
$barangayOptions[] = '<option value="">— Select your barangay —</option>';
foreach ($barangays as $b) {
    $sel = $currentBarangay === (string) $b['name'] ? ' selected' : '';
    $barangayOptions[] = '<option value="' . e((string) $b['name']) . '"' . $sel . '>' . e((string) $b['name']) . '</option>';
}
$barangaySelectHtml = implode("\n", $barangayOptions);

$otpEnabled = (int) ($user['otp_enabled'] ?? 1) === 1;
$currentMobile = (string) ($user['mobile'] ?? '');
// A mobile that will not normalise can never receive an OTP, so "Protected —
// code sent to X" would be a false claim. Derive the real state instead.
$currentMobileOk = $currentMobile !== '' && normalize_ph_mobile($currentMobile) !== false;

$firstName = (string) ($user['first_name'] ?? '');
$lastName  = (string) ($user['last_name'] ?? '');
$userEmail = (string) ($user['email'] ?? '');

// Avatar partial inputs (see includes/partials/avatar.php).
$avatarPublicId = (string) ($user['avatar_public_id'] ?? '');
/* The asset version. avatar_public_id is deterministic (citizen_<id>) and the
   upload overwrites in place, so the delivery URL would be identical before and
   after a replacement and the browser would keep showing the cached old photo.
   mc_avatar_url() folds this into the path so the URL actually changes. */
$avatarVersion = mc_avatar_version_from_url($user['avatar_url'] ?? '');
$avatarName = trim($firstName . ' ' . $lastName);
$avatarSize = 'lg';
$avatarRole = '';
$avatarEditable = true;
$avatarInputId = 'cAvatarFile';
$avatarImgId = 'cAvatarImg';
$avatarActionsId = 'cAvatarBox';
?>
<div class="mc-profile-page">

<div class="mc-profile-hero">
    <div class="mc-profile-hero-inner">
        <div class="mc-profile-hero-title-row">
            <?php require __DIR__ . '/../includes/partials/avatar.php'; ?>
            <div class="mc-profile-hero-title">
                <h1>Profile</h1>
                <p>Account information, security and BasuraAlert notification settings</p>
            </div>
        </div>
        <div class="mc-profile-photo-actions">
            <div class="mc-profile-photo-btns">
                <button type="button" class="mc-profile-photo-btn" id="cAvatarPickBtn">
                    <i data-lucide="camera" class="lucide"></i><span>Change photo</span>
                </button>
                <button type="button" class="mc-profile-photo-btn mc-profile-photo-btn--danger" id="cAvatarRemoveBtn">
                    <i data-lucide="trash-2" class="lucide"></i><span>Remove photo</span>
                </button>
            </div>
            <p class="mc-profile-photo-hint">JPG, PNG or WebP &middot; up to 2&nbsp;MB. Until you add one, your initials are shown.</p>
            <div id="cAvatarAlert" role="status" aria-live="polite"></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="mc-profile-card">
            <div class="mc-profile-card-head">
                <h2><i data-lucide="id-card" class="lucide"></i>Account Information</h2>
            </div>
            <div class="mc-profile-card-body">
                <form id="identityForm" autocomplete="off" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cFirst">First Name</label>
                            <input type="text" id="cFirst" name="first_name" class="form-control" maxlength="100"
                                   value="<?= e($firstName) ?>" required autocomplete="given-name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cLast">Last Name</label>
                            <input type="text" id="cLast" name="last_name" class="form-control" maxlength="100"
                                   value="<?= e($lastName) ?>" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="cEmail">Email Address</label>
                        <input type="email" id="cEmail" name="email" class="form-control" maxlength="190"
                               value="<?= e($userEmail) ?>" required autocomplete="email"
                               aria-describedby="cEmailHelp">
                        <div class="form-text" id="cEmailHelp">
                            Your sign-in address. Changing it requires your current password, and we will
                            email a verification link to the new address before the change is fully active.
                        </div>
                    </div>
                    <div class="mt-3" id="cPwWrap" hidden>
                        <label class="form-label" for="cPw">Current Password <span class="text-danger">(required to change email)</span></label>
                        <input type="password" id="cPw" name="current_password" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="cMobile">Mobile Number</label>
                        <input type="tel" id="cMobile" name="mobile" class="form-control" maxlength="30"
                               value="<?= e($currentMobile) ?>" required autocomplete="tel" inputmode="numeric"
                               placeholder="09XXXXXXXXX" aria-describedby="cMobileHelp">
                        <div class="form-text" id="cMobileHelp">Used for SMS OTP and collection alerts. Philippine format: 09XXXXXXXXX.</div>
                    </div>
                    <div class="mt-3">
                        <div class="form-text mb-2">
                            Barangay is set separately below — it drives which collection schedule and announcements you see.
                        </div>
                        <button class="btn btn-primary" type="submit" id="cIdBtn">Save Profile</button>
                    </div>
                </form>
                <div class="mt-2" id="identityAlert" role="status" aria-live="polite"></div>
            </div>
        </div>
        <div class="mc-profile-card">
            <div class="mc-profile-card-head">
                <h2><i data-lucide="map-pin" class="lucide"></i>My Barangay</h2>
            </div>
            <div class="mc-profile-card-body">
                <form id="barangayForm" method="POST" onsubmit="event.preventDefault(); window.__barangaySubmit ? window.__barangaySubmit() : null;">
                    <div class="text-muted small">Select your barangay to receive correct BasuraAlert schedule information and announcements.</div>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_barangay">
                    <div class="mt-3">
                        <label for="barangaySelect" class="form-label">Barangay</label>
                        <select id="barangaySelect" name="barangay" class="form-select" required><?= $barangaySelectHtml ?></select>
                        <div class="form-text">Don't see your barangay? Choose the closest or notify an administrator.</div>
                    </div>
                    <div class="d-grid mt-3">
                        <button class="btn btn-primary" type="submit" id="barangayBtn">Save Barangay</button>
                    </div>
                    <div class="mt-2" id="barangayAlert" role="status" aria-live="polite"></div>
                </form>
            </div>
        </div>

        <div class="mc-profile-card">
            <div class="mc-profile-card-head">
                <h2><i data-lucide="shield-check" class="lucide"></i>Login Security</h2>
            </div>
            <div class="mc-profile-card-body">
                <form id="otpForm" method="POST" onsubmit="event.preventDefault(); window.__otpSubmit ? window.__otpSubmit() : null;">
                    <div class="text-muted small">Two-Factor Authentication (2FA) via SMS code protects your account even if your password is stolen. We recommend keeping this <span class="fw-semibold text-success">ENABLED</span> unless you are actively testing.</div>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_otp_pref">

                    <div class="mt-3">
                        <label class="mc-profile-switch-row" for="otpSwitch">
                            <input class="form-check-input" type="checkbox" role="switch" id="otpSwitch" name="otp_enabled" data-autosave="1"
                                <?= $otpEnabled ? 'checked' : '' ?>>
                            <span class="mc-profile-switch-text">
                                <strong>Require SMS OTP on login (2FA)</strong>
                                <span id="otpStateLine" role="status" aria-live="polite">
                                    <?php if ($otpEnabled && $currentMobileOk) : ?>
                                        <span class="badge bg-success">Protected &mdash; code sent to <?= e($currentMobile) ?></span>
                                    <?php elseif ($otpEnabled) : ?>
                                        <span class="badge bg-danger">Enabled, but no valid mobile number on file</span>
                                    <?php else : ?>
                                        <span class="badge bg-warning text-dark">Less secure &mdash; email + password only</span>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="d-grid mt-3">
                        <button class="btn btn-outline-primary" type="submit" id="otpBtn">Save OTP Preference</button>
                    </div>
                    <div class="mt-2" id="otpAlert" role="status" aria-live="polite"></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="mc-profile-card">
            <div class="mc-profile-card-head">
                <h2><i data-lucide="bell-ring" class="lucide"></i>BasuraAlert Notification Preferences</h2>
            </div>
            <div class="mc-profile-card-body">
                <form id="notifPrefsForm" method="POST" onsubmit="event.preventDefault(); window.__prefsSubmit ? window.__prefsSubmit() : null;">
                    <div class="text-muted small">Configure how you'd like to be notified. All channels deliver live: In-app (immediate), Email (Brevo SMTP), and SMS (TextBee gateway).</div>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_notification_prefs">

                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="mc-profile-switch-row" for="emailSwitch">
                                <input class="form-check-input" type="checkbox" role="switch" id="emailSwitch" name="enable_email_reminders"
                                    <?= !empty($prefs['enable_email_reminders']) ? 'checked' : '' ?>>
                                <span class="mc-profile-switch-text"><strong>Email reminders</strong></span>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="mc-profile-switch-row" for="smsSwitch">
                                <input class="form-check-input" type="checkbox" role="switch" id="smsSwitch" name="enable_sms_reminders"
                                    <?= !empty($prefs['enable_sms_reminders']) ? 'checked' : '' ?>>
                                <span class="mc-profile-switch-text"><strong>SMS reminders</strong></span>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <label class="mc-profile-switch-row" for="inAppSwitch">
                                <input class="form-check-input" type="checkbox" role="switch" id="inAppSwitch" name="enable_in_app_reminders"
                                    <?= !empty($prefs['enable_in_app_reminders']) ? 'checked' : '' ?>>
                                <span class="mc-profile-switch-text"><strong>In-app reminders</strong></span>
                            </label>
                        </div>
                        <div class="col-md-12">
                            <label for="hoursBefore" class="form-label">Remind me this many hours before scheduled collection</label>
                            <select id="hoursBefore" name="reminder_hours_before" class="form-select">
                                <?php foreach ([1, 3, 6, 12, 24, 48] as $h) : ?>
                                    <option value="<?= $h ?>" <?= (int) ($prefs['reminder_hours_before'] ?? 12) === $h ? 'selected' : '' ?>>
                                        <?= $h ?> hour<?= $h === 1 ? '' : 's' ?> before
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="d-grid mt-3">
                        <button class="btn btn-outline-primary" type="submit" id="prefsBtn">Save Notification Preferences</button>
                    </div>
                    <div class="mt-2" id="prefsAlert" role="status" aria-live="polite"></div>
                </form>
            </div>
        </div>

        <div class="mc-profile-card">
            <div class="mc-profile-card-head">
                <h2><i data-lucide="key-round" class="lucide"></i>Change Password</h2>
            </div>
            <div class="mc-profile-card-body">
                <div class="text-muted small">Password must contain uppercase, lowercase, number, symbol, and at least 8 characters.</div>

                <form id="pwForm" class="mt-3" method="POST" onsubmit="event.preventDefault(); window.__pwSubmitHandler ? window.__pwSubmitHandler.call(this) : document.getElementById('pwAlert').innerHTML='<div class=\'alert alert-danger\'>Please wait for the page to finish loading and try again.</div>';" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="currentPassword" class="form-label">Current Password</label>
                        <input type="password" id="currentPassword" class="form-control" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="newPassword" class="form-label">New Password</label>
                        <input type="password" id="newPassword" class="form-control" name="new_password" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirmPassword" class="form-label">Confirm New Password</label>
                        <input type="password" id="confirmPassword" class="form-control" name="confirm_password" autocomplete="new-password" required>
                    </div>

                    <div class="mc-profile-pw-hint" data-live-password-rules="#newPassword" role="status" aria-live="polite">
                        <div class="mc-profile-pw-hint-title">Live validation</div>
                        <div class="mc-pwrule mc-profile-pw-hint-item" data-rule="len"><i data-lucide="check" class="lucide"></i>At least 8 characters</div>
                        <div class="mc-pwrule mc-profile-pw-hint-item" data-rule="low"><i data-lucide="check" class="lucide"></i>Lowercase letter</div>
                        <div class="mc-pwrule mc-profile-pw-hint-item" data-rule="up"><i data-lucide="check" class="lucide"></i>Uppercase letter</div>
                        <div class="mc-pwrule mc-profile-pw-hint-item" data-rule="num"><i data-lucide="check" class="lucide"></i>Number</div>
                        <div class="mc-pwrule mc-profile-pw-hint-item" data-rule="sym"><i data-lucide="check" class="lucide"></i>Symbol</div>
                    </div>

                    <div class="d-grid">
                        <button class="btn btn-primary" type="submit" id="pwBtn">Update Password</button>
                    </div>
                </form>

                <div class="mt-3" id="pwAlert" role="status" aria-live="polite"></div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.mc-profile-page -->

<?php
$changeEndpoint   = e(app_url('/api/change_password.php'));
$baProfileEndpoint = e(app_url('/api/profile_basuraalert.php'));
$logoutUrl        = e(app_url('/public/logout.php'));
$resendUrl        = e(app_url('/public/resend_verification.php'));
$avatarInitials   = (string) json_encode(mc_avatar_initials($avatarName));

/* This block is a nowdoc/heredoc, so PHP does NOT interpolate inside it: a
   "<?= ... ?>" written here is emitted to the browser as literal text and
   breaks the whole script with a syntax error. Every PHP value therefore has to
   travel in as a __PLACEHOLDER__ and be substituted below. */
$pageScripts = <<<'HTML'
<script>
(function () {
  const changeEndpoint = "__CHG_ENDPOINT__";
  const baProfileEndpoint = "__BA_PROFILE_ENDPOINT__";
  const logoutUrl = "__LOGOUT_URL__";
  const resendUrl = "__RESEND_URL__";

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c];
    });
  }

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.content || "") : "";
  }

  function icons() { if (typeof window.__renderLucide === 'function') window.__renderLucide(); }

  function setAlert(sel, kind, html) {
    $(sel).html('<div class="alert alert-' + kind + '">' + html + "</div>");
    icons();
  }

  /* Shared with admin/profile.php. The [data-live-password-rules] /
     [data-rule] markup used to ship with no JS consumer on either page, so the
     six items rendered permanently neutral. */
  if (typeof window.__initLivePasswordRules === "function") window.__initLivePasswordRules();

  /* ------------------------------------------------------------------
     Change password
     ------------------------------------------------------------------ */
  function showPwError(xhr) {
    let msg = "Update failed. Please try again.";
    let hint = "";
    try {
      const p = (xhr && xhr.responseJSON) ? xhr.responseJSON : null;
      if (p && p.error) {
        msg = String(p.error);
        if (p.hint) hint = String(p.hint);
      } else if (xhr && typeof xhr.responseText === "string" && xhr.responseText) {
        try {
          const q = JSON.parse(xhr.responseText);
          if (q && q.error) msg = String(q.error);
          if (q && q.hint) hint = String(q.hint);
        } catch (_) {}
      }
    } catch (_) {}
    if (xhr && xhr.status === 401) msg = "Session expired. Please log in again.";
    else if (xhr && xhr.status === 0) msg = "Network error. Please check your connection and try again.";
    const hintRow = hint ? '<div class="small text-muted mt-1">' + escapeHtml(hint) + "</div>" : "";
    setAlert("#pwAlert", "danger", '<div class="fw-semibold">' + escapeHtml(msg) + "</div>" + hintRow);
  }

  window.__pwSubmitHandler = function () {
    const btn = document.getElementById("pwBtn");
    if (!btn || btn.dataset.submitting === "1") return;

    const cur = document.getElementById("currentPassword").value;
    const np = document.getElementById("newPassword").value;
    const cp = document.getElementById("confirmPassword").value;

    if (!cur || !np || !cp) {
      setAlert("#pwAlert", "danger", "Please fill in all three password fields.");
      return;
    }
    if (np !== cp) {
      setAlert("#pwAlert", "danger", '<div class="fw-semibold">New passwords do not match.</div><div class="small text-muted mt-1">Make sure the new password and confirmation are identical.</div>');
      return;
    }
    if (cur === np) {
      setAlert("#pwAlert", "danger", "New password must be different from your current password.");
      return;
    }

    btn.dataset.submitting = "1";
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = "Updating…";
    setAlert("#pwAlert", "info", "Updating your password… please wait.");

    $.ajax({
      url: changeEndpoint,
      method: "POST",
      data: $("#pwForm").serialize(),
      dataType: "json",
      timeout: 30000
    }).done(function (res) {
      if (res && res.ok) {
        const serverMsg = (res && res.message) ? String(res.message) : "Password updated.";
        setAlert("#pwAlert", "success",
          '<div class="fw-semibold">' + escapeHtml(serverMsg) + '</div><div class="small mt-1">For your security, please <a href="' + logoutUrl + '" class="alert-link">log out now</a> and sign back in using your new password.</div>');
        $("#pwForm")[0].reset();
        if (typeof window.__initLivePasswordRules === "function") window.__initLivePasswordRules();
      } else {
        const msg = (res && res.error) ? String(res.error) : "Update failed.";
        const hint = (res && res.hint) ? '<div class="small text-muted mt-1">' + escapeHtml(String(res.hint)) + "</div>" : "";
        setAlert("#pwAlert", "danger", '<div class="fw-semibold">' + escapeHtml(msg) + "</div>" + hint);
        btn.dataset.submitting = "0";
        btn.disabled = false;
        btn.textContent = originalText;
      }
    }).fail(function (xhr) {
      showPwError(xhr);
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.textContent = originalText;
    });
  };

  $("#pwForm").off("submit.profile").on("submit.profile", function (e) {
    e.preventDefault();
    window.__pwSubmitHandler();
  });

  /* ------------------------------------------------------------------
     Barangay
     ------------------------------------------------------------------ */
  window.__barangaySubmit = function () {
    const btn = document.getElementById("barangayBtn");
    if (!btn || btn.dataset.submitting === "1") return;
    btn.dataset.submitting = "1";
    btn.disabled = true;
    const ot = btn.textContent;
    btn.textContent = "Saving…";
    setAlert("#barangayAlert", "info", "Saving barangay…");
    $.ajax({
      url: baProfileEndpoint,
      method: "POST",
      data: $("#barangayForm").serialize(),
      dataType: "json",
      timeout: 30000,
      headers: { "X-CSRF-Token": csrfToken() }
    }).done(function (res) {
      if (res && res.ok) {
        setAlert("#barangayAlert", "success", '<div class="fw-semibold">Saved.</div><div>' + escapeHtml(String(res.message || "")) + "</div>");
        setTimeout(function () { location.reload(); }, 900);
      } else {
        setAlert("#barangayAlert", "danger", escapeHtml((res && res.error) ? res.error : "Save failed."));
        btn.dataset.submitting = "0";
        btn.disabled = false;
        btn.textContent = ot;
      }
    }).fail(function () {
      setAlert("#barangayAlert", "danger", "Network error. Please try again.");
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.textContent = ot;
    });
  };

  /* ------------------------------------------------------------------
     Notification preferences
     ------------------------------------------------------------------ */
  window.__prefsSubmit = function () {
    const btn = document.getElementById("prefsBtn");
    if (!btn || btn.dataset.submitting === "1") return;
    btn.dataset.submitting = "1";
    btn.disabled = true;
    const ot = btn.textContent;
    btn.textContent = "Saving…";
    setAlert("#prefsAlert", "info", "Saving preferences…");
    $.ajax({
      url: baProfileEndpoint,
      method: "POST",
      data: $("#notifPrefsForm").serialize(),
      dataType: "json",
      timeout: 30000,
      headers: { "X-CSRF-Token": csrfToken() }
    }).done(function (res) {
      if (res && res.ok) {
        setAlert("#prefsAlert", "success", "Preferences saved.");
      } else {
        setAlert("#prefsAlert", "danger", escapeHtml((res && res.error) ? res.error : "Save failed."));
      }
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.textContent = ot;
    }).fail(function () {
      setAlert("#prefsAlert", "danger", "Network error. Please try again.");
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.textContent = ot;
    });
  };

  /* ------------------------------------------------------------------
     Identity: first_name + last_name + email + mobile.
     The password field is revealed lazily, only when the email value
     actually changes, so the common case stays a single-column form.
     ------------------------------------------------------------------ */
  var ORIGINAL_EMAIL = document.getElementById("cEmail").value;
  function syncCitizenPasswordRequirement() {
    const changed = document.getElementById("cEmail").value !== ORIGINAL_EMAIL;
    document.getElementById("cPwWrap").hidden = !changed;
    if (!changed) document.getElementById("cPw").value = "";
  }
  document.getElementById("cEmail").addEventListener("input", syncCitizenPasswordRequirement);
  document.getElementById("cEmail").addEventListener("change", syncCitizenPasswordRequirement);
  syncCitizenPasswordRequirement();

  const cIdBtn = document.getElementById("cIdBtn");
  document.getElementById("identityForm").addEventListener("submit", function (e) {
    e.preventDefault();
    if (cIdBtn.disabled) return;
    cIdBtn.disabled = true;
    setAlert("#identityAlert", "info", "Saving…");
    $.ajax({
      url: baProfileEndpoint,
      method: "POST",
      dataType: "json",
      timeout: 60000,
      headers: { "X-CSRF-Token": csrfToken() },
      data: {
        action: "update_identity",
        csrf_token: csrfToken(),
        first_name: document.getElementById("cFirst").value,
        last_name: document.getElementById("cLast").value,
        email: document.getElementById("cEmail").value,
        mobile: document.getElementById("cMobile").value,
        current_password: document.getElementById("cPw").value
      }
    }).done(function (res) {
      if (res && res.ok) {
        if (res.email) { ORIGINAL_EMAIL = res.email; document.getElementById("cEmail").value = res.email; }
        // `mobile` is the canonical E.164 the server stored. Writing the MASK
        // back here put a "+6391****4567" into an editable field, and the very
        // next save then failed normalize_ph_mobile().
        if (typeof res.mobile === "string") document.getElementById("cMobile").value = res.mobile;
        let msg = escapeHtml((res && res.message) ? res.message : "Profile updated.");
        if (res.needs_verification) {
          // Prefer the link the API actually issued; fall back to resend.
          const link = res.verify_url ? res.verify_url : resendUrl;
          msg += ' <a href="' + escapeHtml(link) + '" class="alert-link">Send the link again</a>';
        }
        setAlert("#identityAlert", "success", msg);
        setTimeout(function () { if (!res.needs_verification) location.reload(); }, 1400);
      } else {
        setAlert("#identityAlert", "danger", escapeHtml((res && res.error) ? res.error : "Update failed."));
      }
    }).fail(function (xhr) {
      const m = (xhr && xhr.status === 401) ? "Your session expired. Please sign in again." : "Network error. Please try again.";
      setAlert("#identityAlert", "danger", escapeHtml(m));
    }).always(function () { cIdBtn.disabled = false; });
  });

  /* ------------------------------------------------------------------
     Avatar. avatar.js owns the request and the in-place swap.
     ------------------------------------------------------------------ */
  if (typeof window.__initAvatarUpload === "function") {
    window.__initAvatarUpload({
      endpoint: baProfileEndpoint,
      boxId: "cAvatarBox",
      inputId: "cAvatarFile",
      pickId: "cAvatarPickBtn",
      removeId: "cAvatarRemoveBtn",
      alertId: "cAvatarAlert",
      initials: __AVATAR_INITIALS__,
      fallbackClass: ""
    });

    /* The topnav greeting tile. avatar.js is only loaded on this page, so
       elsewhere the tile stays a plain link to here - which is where the photo
       is changed anyway. tileTriggers makes the photo itself open the picker:
       there is no camera badge at 40px in a nav bar, and a <button> inside the
       greeting's <a> would be invalid HTML. */
    [
      { box: "userTopAvatarBox", input: "userTopAvatarFile" },
      { box: "userTopAvatarBoxMobile", input: "userTopAvatarFileMobile" }
    ].forEach(function (t) {
      if (!document.getElementById(t.box)) return;
      window.__initAvatarUpload({
        endpoint: baProfileEndpoint,
        boxId: t.box,
        inputId: t.input,
        alertId: "cAvatarAlert",
        initials: __AVATAR_INITIALS__,
        fallbackClass: "",
        tileTriggers: true
      });
    });
  }

  /* ------------------------------------------------------------------
     OTP preference: auto-saves the moment the switch is flipped, and the
     reload happens in the response callback rather than on a blind timer so a
     fast refresh can no longer abort the POST.
     ------------------------------------------------------------------ */
  function renderOtpState(res) {
    const line = document.getElementById("otpStateLine");
    if (!line) return;
    if (res && res.otp_enabled) {
      line.innerHTML = '<span class="badge bg-success">' +
        (res.masked_mobile ? "Protected — code sent to " + escapeHtml(res.masked_mobile) : "Enabled for your next login") +
        "</span>";
    } else {
      line.innerHTML = '<span class="badge bg-warning text-dark">Less secure — email + password only</span>';
    }
    icons();
  }

  window.__otpSubmit = function () {
    const btn = document.getElementById("otpBtn");
    const sw = document.getElementById("otpSwitch");
    if (!btn || btn.dataset.submitting === "1") return;
    btn.dataset.submitting = "1";
    btn.disabled = true;
    if (sw) sw.disabled = true;
    const ot = btn.textContent;
    btn.textContent = "Saving…";
    setAlert("#otpAlert", "info", "Saving OTP preference…");
    const enable = sw ? (sw.checked ? 1 : 0) : 0;
    $.ajax({
      url: baProfileEndpoint,
      method: "POST",
      data: {
        action: "save_otp_pref",
        otp_enabled: enable,
        csrf_token: csrfToken(),
      },
      dataType: "json",
      timeout: 30000,
      headers: { "X-CSRF-Token": csrfToken() }
    }).done(function (res) {
      if (res && res.ok) {
        renderOtpState(res);
        setAlert("#otpAlert", "success", escapeHtml((res && res.message) ? res.message : "OTP preference saved."));
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        // Server rejected: snap the switch back to the persisted value.
        if (sw) sw.checked = !enable;
        setAlert("#otpAlert", "danger", escapeHtml((res && res.error) ? res.error : "Save failed."));
        btn.dataset.submitting = "0";
        btn.disabled = false;
        if (sw) sw.disabled = false;
        btn.textContent = ot;
      }
    }).fail(function () {
      if (sw) sw.checked = !enable;
      setAlert("#otpAlert", "danger", "Network error. Please try again.");
      btn.dataset.submitting = "0";
      btn.disabled = false;
      if (sw) sw.disabled = false;
      btn.textContent = ot;
    });
  };

  // Auto-save on flip. data-autosave is the opt-in marker so this stays inert
  // if the markup is ever reused on a page with a different persistence model.
  const otpSwitch = document.getElementById("otpSwitch");
  if (otpSwitch && otpSwitch.dataset.autosave === "1") {
    otpSwitch.addEventListener("change", function () { window.__otpSubmit(); });
  }
})();
</script>
HTML;

$pageScripts = str_replace(
    ["__CHG_ENDPOINT__", "__BA_PROFILE_ENDPOINT__", "__LOGOUT_URL__", "__RESEND_URL__", "__AVATAR_INITIALS__"],
    [$changeEndpoint, $baProfileEndpoint, $logoutUrl, $resendUrl, $avatarInitials],
    $pageScripts
);

/* foot.php echoes $pageScripts after app.js, so jQuery and the CSRF header
   both exist by the time this runs. avatar.js and password-rules.js are plain
   (NOT deferred) and emitted first: this script runs during parsing, and a
   deferred copy has not executed yet, so the upload controls and the rule
   checklist would silently never bind. */
$pageScripts = '<script src="' . e(app_url('/assets/js/avatar.js')) . '"></script>' . "\n"
             . '<script src="' . e(app_url('/assets/js/password-rules.js')) . '"></script>' . "\n"
             . $pageScripts;

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
