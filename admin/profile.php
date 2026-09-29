<?php

declare(strict_types=1);

$pageTitle = 'Admin Profile';
$activeNav = 'profile';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/Avatar.php';

$currentMobile = (string) ($admin['mobile'] ?? '');
$mobileValid = normalize_ph_mobile($currentMobile) !== false;
$lastLogin = $admin['last_login_at'] ?? null;
$otpEnabled = (int) ($admin['otp_enabled'] ?? 1) === 1;
$adminName = (string) ($admin['name'] ?? '');
$adminEmail = (string) ($admin['email'] ?? '');
$adminRole = (string) ($admin['role'] ?? '');
$adminDepartment = (string) (($admin['department_name'] ?? '') ?: '');
$isSuperAdmin = $adminRole === 'super_admin';

// Avatar partial inputs (see includes/partials/avatar.php). The partial emits
// markup at include position, so it is included INSIDE the photo card below
// rather than here.
$avatarPublicId = (string) ($admin['avatar_public_id'] ?? '');
/* The asset version. avatar_public_id is deterministic (admin_<id>) and the
   upload overwrites in place, so the delivery URL would be identical before and
   after a replacement and the browser would keep showing the cached old photo.
   mc_avatar_url() folds this into the path so the URL actually changes. */
$avatarVersion = mc_avatar_version_from_url($admin['avatar_url'] ?? '');
$avatarName = $adminName;
$avatarSize = 'xl';
$avatarRole = 'admin';
$avatarEditable = true;
$avatarInputId = 'avatarFile';
$avatarImgId = 'avatarImg';
$avatarActionsId = 'avatarBox';
?>
<div class="mc-admin-profile-page">

    <div class="mc-admin-up-header">
        <div class="mc-admin-up-title-block">
            <div class="mc-admin-up-icon-wrap">
                <i data-lucide="user-circle-2" class="lucide"></i>
            </div>
            <div class="mc-admin-up-title-text">
                <h1>Admin Profile</h1>
                <p>Your account details, profile photo and sign-in security.</p>
            </div>
        </div>
        <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
             alt="Official Seal of the City of Marikina"
             class="mc-admin-up-seal" width="56" height="56" loading="eager" decoding="async">
    </div>

    <div class="mc-admin-up-grid">

        <!-- ============ SIDEBAR: photo + read-only account facts ============ -->
        <aside class="mc-admin-up-sidebar">

            <div class="mc-admin-up-avatar-card">
                <?php require __DIR__ . '/../includes/partials/avatar.php'; ?>
                <h2 class="mc-admin-up-avatar-name"><?= e($adminName) ?></h2>
                <div class="mc-admin-up-avatar-email"><?= e($adminEmail) ?></div>
                <span class="mc-admin-up-avatar-role">
                    <i data-lucide="shield-check" class="lucide"></i>
                    <?= $isSuperAdmin ? 'Super Admin' : 'Administrator' ?>
                </span>
                <div class="mc-admin-up-avatar-actions">
                    <button type="button" class="mc-admin-up-upload-btn" id="avatarPickBtn">
                        <i data-lucide="camera" class="lucide"></i><span>Change photo</span>
                    </button>
                    <button type="button" class="mc-admin-up-remove-btn" id="avatarRemoveBtn">
                        <i data-lucide="trash-2" class="lucide"></i><span>Remove photo</span>
                    </button>
                </div>
                <p class="mc-admin-up-hint">JPG, PNG or WebP &middot; up to 2&nbsp;MB. Until you add one, your initials are shown.</p>
                <div id="avatarAlert" role="status" aria-live="polite"></div>
            </div>

            <div class="mc-admin-up-section-card">
                <div class="mc-admin-up-section-title">
                    <span class="mc-admin-up-section-title-icon"><i data-lucide="info" class="lucide"></i></span>
                    <h2>Account</h2>
                </div>
                <dl class="mc-admin-up-facts">
                    <div class="mc-admin-up-fact">
                        <dt>Mobile (SMS OTP)</dt>
                        <dd>
                            <span><?= $currentMobile !== '' ? e(mask_mobile($currentMobile)) : '&mdash;' ?></span>
                            <?php if ($currentMobile !== '' && !$mobileValid) : ?>
                                <span class="badge bg-warning text-dark">Invalid PH Number</span>
                            <?php endif; ?>
                            <?php /* The badge must track otp_enabled, NOT the mere presence of a
                                     mobile number. It previously keyed off $mobileValid alone, so
                                     after successfully disabling 2FA this card still showed a green
                                     "2FA SMS Enabled" badge while the OTP card said "Less secure" —
                                     which is exactly what made the toggle look like it had reverted. */
                            if ($otpEnabled && $currentMobile !== '' && $mobileValid) : ?>
                                <span class="badge bg-success" id="mobile2faBadge">2FA SMS Enabled</span>
                            <?php elseif ($otpEnabled) : ?>
                                <span class="badge bg-danger" id="mobile2faBadge">2FA On &middot; No Mobile</span>
                            <?php else : ?>
                                <span class="badge bg-secondary" id="mobile2faBadge">2FA SMS Disabled</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="mc-admin-up-fact">
                        <dt>Role</dt>
                        <dd>
                            <span><?= $isSuperAdmin ? 'Super Admin' : 'Administrator' ?></span>
                            <span class="mc-admin-up-tag">managed by a Super Admin</span>
                        </dd>
                    </div>
                    <div class="mc-admin-up-fact">
                        <dt>Department</dt>
                        <dd>
                            <span><?= $adminDepartment !== '' ? e($adminDepartment) : '&mdash;' ?></span>
                            <span class="mc-admin-up-tag">managed by a Super Admin</span>
                        </dd>
                    </div>
                    <div class="mc-admin-up-fact">
                        <dt>Last Login</dt>
                        <dd><?= $lastLogin ? e(date('M j, Y g:i A', strtotime((string) $lastLogin))) : '<span class="text-muted">Never</span>' ?></dd>
                    </div>
                </dl>
            </div>

        </aside>

        <!-- ============ MAIN: the three editable forms ============ -->
        <div class="mc-admin-up-main">

            <section class="mc-admin-up-section-card">
                <div class="mc-admin-up-section-title">
                    <span class="mc-admin-up-section-title-icon"><i data-lucide="id-card" class="lucide"></i></span>
                    <h2>Account Information</h2>
                </div>

                <form id="identityForm" autocomplete="off" novalidate>
                    <div class="mc-admin-up-form-grid">
                        <div class="mc-admin-up-form-group">
                            <label class="mc-admin-up-label" for="idName">Full Name</label>
                            <input type="text" id="idName" name="name" class="form-control mc-admin-up-input"
                                   maxlength="190" value="<?= e($adminName) ?>" required autocomplete="name">
                        </div>
                        <div class="mc-admin-up-form-group">
                            <label class="mc-admin-up-label" for="idMobile">Mobile Number</label>
                            <input type="tel" id="idMobile" name="mobile" class="form-control mc-admin-up-input"
                                   maxlength="20" value="<?= e($currentMobile) ?>" autocomplete="tel"
                                   inputmode="numeric" placeholder="09XXXXXXXXX" aria-describedby="idMobileHelp">
                            <div class="form-text" id="idMobileHelp">Used for SMS OTP. Leave blank to disable 2FA by SMS.</div>
                        </div>
                        <div class="mc-admin-up-form-group full">
                            <label class="mc-admin-up-label" for="idEmail">Email Address</label>
                            <input type="email" id="idEmail" name="email" class="form-control mc-admin-up-input"
                                   maxlength="190" value="<?= e($adminEmail) ?>" required autocomplete="email"
                                   aria-describedby="idEmailHelp">
                            <div class="form-text" id="idEmailHelp">
                                This is your sign-in address and where password-reset links are sent.
                                Changing it requires your current password.
                            </div>
                        </div>
                        <div class="mc-admin-up-form-group full" id="idPwWrap" hidden>
                            <label class="mc-admin-up-label" for="idPw">
                                Current Password <span class="text-danger">(required to change email)</span>
                            </label>
                            <input type="password" id="idPw" name="current_password"
                                   class="form-control mc-admin-up-input" autocomplete="current-password">
                        </div>
                    </div>

                    <div class="mc-admin-up-form-footer">
                        <button class="btn btn-primary mc-admin-up-save-btn" type="submit" id="idBtn">
                            <i data-lucide="save" class="lucide"></i><span>Save Profile</span>
                        </button>
                    </div>
                </form>

                <div class="mt-3" id="identityAlert" role="status" aria-live="polite"></div>
            </section>

            <section class="mc-admin-up-section-card">
                <div class="mc-admin-up-section-title">
                    <span class="mc-admin-up-section-title-icon"><i data-lucide="shield-check" class="lucide"></i></span>
                    <h2>Login Security</h2>
                </div>

                <div class="mc-admin-up-info-note">
                    <i data-lucide="shield-alert" class="lucide"></i>
                    <p>Two-Factor Authentication (2FA) via SMS code protects your account even if your password is
                        stolen. We recommend keeping this <strong>ENABLED</strong> unless you are actively testing.</p>
                </div>

                <form id="otpForm">
                    <label class="mc-admin-up-switch-row" for="otpSwitch">
                        <input class="form-check-input" type="checkbox" role="switch" id="otpSwitch" name="otp_enabled"
                            <?= $otpEnabled ? 'checked' : '' ?> data-autosave="1">
                        <span class="mc-admin-up-switch-text">
                            <strong>Require SMS OTP on login (2FA)</strong>
                            <span class="mc-admin-up-switch-state" id="otpStateLine" role="status" aria-live="polite">
                                <?php if ($otpEnabled && $currentMobile !== '' && $mobileValid) : ?>
                                    <span class="badge bg-success">Protected &mdash; code sent to <?= e(mask_mobile($currentMobile)) ?></span>
                                <?php elseif ($otpEnabled) : ?>
                                    <span class="badge bg-danger">Enabled, but no valid mobile number on file</span>
                                <?php else : ?>
                                    <span class="badge bg-warning text-dark">Less secure &mdash; email + password only</span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>

                    <div class="mc-admin-up-form-footer">
                        <button class="btn btn-outline-primary mc-admin-up-save-btn" type="submit" id="otpBtn">
                            <i data-lucide="save" class="lucide"></i><span>Save OTP Preference</span>
                        </button>
                    </div>
                </form>

                <div class="mt-3" id="otpAlert" role="status" aria-live="polite"></div>
            </section>

            <section class="mc-admin-up-section-card">
                <div class="mc-admin-up-section-title">
                    <span class="mc-admin-up-section-title-icon"><i data-lucide="key-round" class="lucide"></i></span>
                    <h2>Change Password</h2>
                </div>

                <form id="pwForm" novalidate>
                    <div class="mc-admin-up-form-grid">
                        <div class="mc-admin-up-form-group full">
                            <label class="mc-admin-up-label" for="currentPassword">Current Password</label>
                            <input type="password" id="currentPassword" class="form-control mc-admin-up-input"
                                   name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="mc-admin-up-form-group">
                            <label class="mc-admin-up-label" for="newPassword">New Password</label>
                            <input type="password" id="newPassword" class="form-control mc-admin-up-input"
                                   name="new_password" autocomplete="new-password" required>
                        </div>
                        <div class="mc-admin-up-form-group">
                            <label class="mc-admin-up-label" for="confirmPassword">Confirm New Password</label>
                            <input type="password" id="confirmPassword" class="form-control mc-admin-up-input"
                                   name="confirm_password" autocomplete="new-password" required>
                        </div>
                    </div>

                    <div class="mc-admin-up-password-hint" data-live-password-rules="#newPassword"
                         role="status" aria-live="polite">
                        <div class="mc-admin-up-password-hint-title">Live validation</div>
                        <div class="mc-pwrule mc-admin-up-password-hint-item" data-rule="len"><i data-lucide="check" class="lucide"></i>At least 8 characters</div>
                        <div class="mc-pwrule mc-admin-up-password-hint-item" data-rule="low"><i data-lucide="check" class="lucide"></i>Lowercase letter</div>
                        <div class="mc-pwrule mc-admin-up-password-hint-item" data-rule="up"><i data-lucide="check" class="lucide"></i>Uppercase letter</div>
                        <div class="mc-pwrule mc-admin-up-password-hint-item" data-rule="num"><i data-lucide="check" class="lucide"></i>Number</div>
                        <div class="mc-pwrule mc-admin-up-password-hint-item" data-rule="sym"><i data-lucide="check" class="lucide"></i>Symbol</div>
                    </div>

                    <div class="mc-admin-up-form-footer">
                        <button class="btn btn-primary mc-admin-up-save-btn" type="submit" id="pwBtn">
                            <i data-lucide="key-round" class="lucide"></i><span>Update Password</span>
                        </button>
                    </div>
                </form>

                <div class="mt-3" id="pwAlert" role="status" aria-live="polite"></div>
            </section>

        </div>
    </div>
</div>

<?php
$profileEndpoint = e(app_url('/admin/api/update_profile.php'));
$changePasswordEndpoint = e(app_url('/admin/api/change_password.php'));
$avatarInitials = (string) json_encode(mc_avatar_initials($adminName));

/* Nowdoc: PHP does NOT interpolate inside <<<'HTML', so every value travels in
   as a __PLACEHOLDER__ and is substituted below. A "<?= ?>" written here is
   emitted to the browser as literal text and breaks the whole script — which
   is exactly how the resend-verification link broke public/profile.php. */
$pageScripts = <<<'HTML'
<script>
$(function () {
  const profileEndpoint = "__PROFILE_ENDPOINT__";
  const changePasswordEndpoint = "__CHANGE_PW_ENDPOINT__";

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }
  function icons() { if (typeof window.__renderLucide === 'function') window.__renderLucide(); }
  function alertBox(sel, kind, msg) {
    $(sel).html("<div class='alert alert-" + kind + "'>" + esc(msg) + "</div>");
    icons();
  }

  /* ---- Live password-rule checklist (shared with public/profile.php) ---- */
  if (typeof window.__initLivePasswordRules === "function") window.__initLivePasswordRules();

  /* ---- Identity: name + email + mobile ----
     The separate "SMS OTP Mobile Number" form this replaced duplicated the
     mobile field, so the two could drift and the user had to guess which one
     was authoritative. Email is only editable with the current password, so
     the password field is revealed lazily the moment the email value
     actually changes. */
  var ORIGINAL_EMAIL = $("#idEmail").val();
  function syncPasswordRequirement() {
    var changed = $("#idEmail").val() !== ORIGINAL_EMAIL;
    $("#idPwWrap").prop("hidden", !changed);
    if (!changed) $("#idPw").val("");
  }
  $("#idEmail").on("input change", syncPasswordRequirement);
  syncPasswordRequirement();

  $("#identityForm").on("submit", function (e) {
    e.preventDefault();
    var btn = $("#idBtn");
    if (btn.prop("disabled")) return;
    btn.prop("disabled", true);
    $("#identityAlert").html("<div class='text-muted small'>Saving…</div>");
    icons();
    $.post(profileEndpoint, {
      action: 'update_identity',
      name: $("#idName").val(),
      email: $("#idEmail").val(),
      mobile: $("#idMobile").val(),
      current_password: $("#idPw").val()
    })
      .done(function (res) {
        if (res && res.ok) {
          alertBox("#identityAlert", "success", res.message || "Profile updated.");
          // Reflect the canonical values the server stored. `mobile` is E.164,
          // never the mask: a masked value cannot be re-submitted.
          if (res.email) { ORIGINAL_EMAIL = res.email; $("#idEmail").val(res.email); }
          if (typeof res.name === "string") $("#idName").val(res.name);
          if (typeof res.mobile === "string") $("#idMobile").val(res.mobile);
          if (res.initials) $(".mc-avatar--fallback").text(res.initials);
          $(".mc-user-name").text("Hi, " + (res.name || ""));
          $(".mc-admin-up-avatar-name").text(res.name || "");
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          alertBox("#identityAlert", "danger", (res && res.error) ? res.error : "Update failed.");
        }
      })
      .fail(function () { alertBox("#identityAlert", "danger", "Network error. Please try again."); })
      .always(function () { btn.prop("disabled", false); });
  });

  /* ---- Avatar: upload + remove go to the same endpoint as everything else.
     assets/js/avatar.js owns the request and the in-place swap. ---- */
  if (typeof window.__initAvatarUpload === "function") {
    window.__initAvatarUpload({
      endpoint: profileEndpoint,
      boxId: "avatarBox",
      inputId: "avatarFile",
      pickId: "avatarPickBtn",
      removeId: "avatarRemoveBtn",
      alertId: "avatarAlert",
      initials: __AVATAR_INITIALS__,
      fallbackClass: "mc-avatar--admin"
    });

    /* The topnav greeting tile, on this page only. avatar.js is loaded here
       (it is not on every page), so on the rest of the admin the tile stays a
       plain link to this page - which is where the photo gets changed anyway.
       tileTriggers makes the photo itself open the picker; there is no camera
       badge at 40px in a nav bar, and a <button> inside the greeting's <a>
       would be invalid HTML. */
    [
      { box: "adminTopAvatarBox", input: "adminTopAvatarFile" },
      { box: "adminTopAvatarBoxMobile", input: "adminTopAvatarFileMobile" }
    ].forEach(function (t) {
      if (!document.getElementById(t.box)) return;
      window.__initAvatarUpload({
        endpoint: profileEndpoint,
        boxId: t.box,
        inputId: t.input,
        alertId: "avatarAlert",
        initials: __AVATAR_INITIALS__,
        fallbackClass: "mc-avatar--admin",
        tileTriggers: true
      });
    });
  }

  /* ---- OTP preference: auto-saves the moment the switch is flipped.
     The switch was a plain form checkbox with no change listener, so flipping
     it and refreshing silently discarded the change. The button is kept as an
     explicit re-save. The reload happens in the response callback rather than
     on a blind timer, so a fast refresh can no longer abort the POST. ---- */
  var otpSaving = false;
  function renderOtpState(res) {
    var on = !!(res && res.otp_enabled);
    if (on) {
      $("#otpStateLine").html("<span class='badge bg-success'>" +
        (res.masked_mobile ? "Protected — code sent to " + esc(res.masked_mobile) : "Enabled for your next login") +
        "</span>");
      $("#mobile2faBadge").removeClass("bg-secondary bg-danger").addClass("bg-success").text("2FA SMS Enabled");
    } else {
      $("#otpStateLine").html("<span class='badge bg-warning text-dark'>Less secure — email + password only</span>");
      $("#mobile2faBadge").removeClass("bg-success bg-danger").addClass("bg-secondary").text("2FA SMS Disabled");
    }
    icons();
  }
  function saveOtpPreference() {
    if (otpSaving) return;
    otpSaving = true;
    var enable = $("#otpSwitch").is(":checked") ? 1 : 0;
    $("#otpSwitch").prop("disabled", true);
    $("#otpBtn").prop("disabled", true);
    $("#otpAlert").html("<div class='text-muted small'>Saving…</div>");
    icons();
    $.post(profileEndpoint, { action: 'toggle_otp', otp_enabled: enable })
      .done(function (res) {
        if (res && res.ok) {
          renderOtpState(res);
          alertBox("#otpAlert", "success", res.message || "OTP preference saved.");
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          // Server rejected: snap the switch back to the persisted value.
          $("#otpSwitch").prop("checked", !enable);
          alertBox("#otpAlert", "danger", (res && res.error) ? res.error : "Save failed.");
        }
      })
      .fail(function () {
        $("#otpSwitch").prop("checked", !enable);
        alertBox("#otpAlert", "danger", "Network error. Try again.");
      })
      .always(function () {
        otpSaving = false;
        $("#otpSwitch").prop("disabled", false);
        $("#otpBtn").prop("disabled", false);
      });
  }
  $("#otpForm").on("submit", function (e) { e.preventDefault(); saveOtpPreference(); });
  $("#otpSwitch").on("change", saveOtpPreference);

  /* ---- Change password ---- */
  $("#pwForm").on("submit", function (e) {
    e.preventDefault();
    var btn = $("#pwBtn");
    if (btn.prop("disabled")) return;
    btn.prop("disabled", true);
    $("#pwAlert").html("<div class='text-muted small'>Updating…</div>");
    icons();
    $.post(changePasswordEndpoint, $("#pwForm").serialize())
      .done(function (res) {
        if (res && res.ok) {
          alertBox("#pwAlert", "success", res.message || "Password updated.");
          $("#pwForm")[0].reset();
          if (typeof window.__initLivePasswordRules === "function") window.__initLivePasswordRules();
        } else {
          alertBox("#pwAlert", "danger", (res && res.error) ? res.error : "Update failed.");
        }
      })
      .fail(function () { alertBox("#pwAlert", "danger", "Update failed."); })
      .always(function () { btn.prop("disabled", false); });
  });
});
</script>
HTML;

$pageScripts = str_replace(
    ["__PROFILE_ENDPOINT__", "__CHANGE_PW_ENDPOINT__", "__AVATAR_INITIALS__"],
    [$profileEndpoint, $changePasswordEndpoint, $avatarInitials],
    $pageScripts
);

/* foot.php echoes $pageScripts AFTER app.js, so jQuery, the CSRF header and
   window.__csrfToken all exist by the time this runs. avatar.js and
   password-rules.js are plain (NOT deferred) and emitted first: a deferred
   copy has not executed yet when this script runs during parsing, which is how
   the upload controls and the rule checklist silently never bound. */
$helpers = '<script src="' . e(app_url('/assets/js/avatar.js')) . '"></script>' . "\n"
         . '<script src="' . e(app_url('/assets/js/password-rules.js')) . '"></script>' . "\n";
$pageScripts = $helpers . $pageScripts;

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
