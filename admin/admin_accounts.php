<?php

declare(strict_types=1);

$pageTitle = 'Admin Accounts';
$activeNav = 'admins';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';
require_once __DIR__ . '/../includes/sms.php';

if (!$isSuper) {
    http_response_code(403);
}

?>
<div class="mc-admin-admin_accounts-page">

<div class="mc-admin-concerns-hero">
    <div class="mc-admin-concerns-hero-inner">
        <div class="mc-admin-concerns-title-row">
            <div class="mc-admin-concerns-icon-wrap">
                <i data-lucide="user-cog" class="lucide"></i>
            </div>
            <div class="mc-admin-concerns-title">
                <h1>Admin Accounts</h1>
                <p>Create and manage admin logins.</p>
            </div>
        </div>
        <div class="mc-admin-concerns-hero-actions">
            <?php if ($isSuper) : ?>
                <button class="btn btn-primary mc-admin-aa-add-btn" type="button" id="addAdminBtn">
                    <i data-lucide="plus" class="lucide lucide-16" aria-hidden="true"></i><span>Add Admin</span>
                </button>
            <?php endif; ?>
            <img src="<?= e(app_url('/assets/img/MarikinaLogo.jpg')) ?>"
                 alt="Official Seal of the City of Marikina"
                 class="mc-admin-concerns-hero-seal" loading="eager" decoding="async">
        </div>
    </div>
</div>

<?php if (!$isSuper) : ?>
    <div class="alert alert-danger">Forbidden.</div>
<?php else : ?>
    <div class="mc-admin-kpi-grid" id="adminsKpis"></div>

    <div class="mc-admin-section-card mb-3">
        <div class="mc-admin-section-body">
            <div class="mc-admin-aa-toolbar">
                <div class="mc-admin-aa-field">
                    <label class="form-label" for="adminSearchBox">Search</label>
                    <div class="mc-admin-aa-search-wrap">
                        <i data-lucide="search" class="lucide mc-admin-aa-search-icon" aria-hidden="true"></i>
                        <input class="form-control mc-admin-aa-search-input" id="adminSearchBox" type="search"
                               placeholder="Search name or email... (press Esc to clear)"
                               autocomplete="off" spellcheck="false">
                    </div>
                </div>
                <div class="mc-admin-aa-field">
                    <button type="button" class="btn btn-outline-secondary mc-admin-aa-refresh w-100" id="adminRefreshBtn">
                        <i data-lucide="refresh-cw" class="lucide lucide-16" aria-hidden="true"></i>
                        <span>Refresh</span>
                    </button>
                </div>
            </div>
            <div class="mc-admin-concerns-info">
                <i data-lucide="info" class="lucide" aria-hidden="true"></i>
                <span>Search by name or email.</span>
            </div>
        </div>
    </div>
    <div class="mc-admin-section-card">
        <div class="mc-admin-section-head">
            <h3><i data-lucide="users" class="lucide" aria-hidden="true"></i> All Admins</h3>
            <span class="text-muted small" id="adminsCount"></span>
        </div>
        <div class="mc-admin-table-wrap">
            <table class="table table-hover align-middle mb-0" id="adminsTable">
                <thead>
                    <tr>
                        <th><span class="mc-th-wrap"><i data-lucide="user" class="lucide mc-th-icon" aria-hidden="true"></i>Name</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="mail" class="lucide mc-th-icon" aria-hidden="true"></i>Email</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="smartphone" class="lucide mc-th-icon" aria-hidden="true"></i>Mobile (SMS OTP)</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="shield" class="lucide mc-th-icon" aria-hidden="true"></i>Role</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="building-2" class="lucide mc-th-icon" aria-hidden="true"></i>Department</span></th>
                        <th><span class="mc-th-wrap"><i data-lucide="log-in" class="lucide mc-th-icon" aria-hidden="true"></i>Last login</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--center"><i data-lucide="check-circle-2" class="lucide mc-th-icon" aria-hidden="true"></i>Active</span></th>
                        <th><span class="mc-th-wrap mc-th-wrap--end"><i data-lucide="settings" class="lucide mc-th-icon" aria-hidden="true"></i>Actions</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="mc-admin-aa-status" id="adminsHint" role="status" aria-live="polite"></div>
    </div>

    <div class="modal fade" id="adminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="adminModalTitle">Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="adminModalError"></div>
                    <input type="hidden" id="adminId">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input class="form-control" id="adminName">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input class="form-control" id="adminEmail" type="email">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mobile Number <span class="text-muted small">(for SMS OTP — 09XXXXXXXXX or +639XXXXXXXXX)</span></label>
                        <input class="form-control" id="adminMobile" type="tel" inputmode="numeric" placeholder="e.g. 09171234567">
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select class="form-select" id="adminRole">
                                <option value="super_admin">Super Admin</option>
                                <option value="department_admin">Department Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select class="form-select" id="adminDepartment">
                                <option value="">Select department</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3" id="createPasswordRow">
                        <label class="form-label">Initial Password</label>
                        <input class="form-control" id="adminPassword" type="password" placeholder="Min 8 characters">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveAdminBtn">Save</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="passwordModalError"></div>
                    <input type="hidden" id="passwordAdminId">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input class="form-control" id="newPassword" type="password" placeholder="Min 8 characters">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="setPasswordBtn">Update Password</button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.mc-admin-admin_accounts-page -->
<?php endif; ?>

<?php
$adminsEndpoint = e(app_url('/admin/api/admins.php'));
$deptsEndpoint = e(app_url('/admin/api/departments.php'));
$currentAdminIdJs = (int) ($admin['id'] ?? 0);
$pageScripts = <<<'HTML'
<script>
$(function () {
  const adminsEndpoint = "__ADMINS_ENDPOINT__";
  const deptsEndpoint = "__DEPTS_ENDPOINT__";
  const CURRENT_ADMIN_ID = __CURRENT_ADMIN_ID__;

  function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

  /* Presentational only: two-letter initials for the row avatar. Letters/digits
     are stripped so the tile can never carry markup from a display name. */
  function initials(name) {
    const parts = String(name == null ? "" : name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    const first = parts[0].charAt(0);
    const second = parts.length > 1 ? parts[parts.length - 1].charAt(0) : "";
    return (first + second).replace(/[^a-z0-9]/gi, "").toUpperCase() || "?";
  }

  function emptyRow(title, body) {
    return '<tr class="mc-empty-row"><td colspan="8">' +
             '<div class="mc-admin-empty">' +
               '<div class="mc-admin-empty-icon"><i data-lucide="user-x" class="lucide" aria-hidden="true"></i></div>' +
               '<h4>' + title + '</h4><p>' + body + '</p>' +
             '</div>' +
           '</td></tr>';
  }

  function hasTwoFa(mobile) {
    if (!mobile) return false;
    return String(mobile).replace(/\D/g, "").length >= 10;
  }

  /* Presentational only: counts already in the payload. */
  function renderKpis(rows) {
    const total = rows.length;
    const supers = rows.filter(function (r) { return r.role === "super_admin"; }).length;
    const dept = rows.filter(function (r) { return r.role === "department_admin"; }).length;
    const twofa = rows.filter(function (r) { return hasTwoFa(r.mobile); }).length;

    const tiles = [
      { label: "Total Admins",     value: total,  icon: "users",           chip: "navy"  },
      { label: "Super Admins",    value: supers, icon: "shield-check",    chip: "sky"   },
      { label: "Department Admin", value: dept,   icon: "building-2",      chip: "mint"  },
      { label: "2FA enabled",     value: twofa,  icon: "smartphone",       chip: "amber" }
    ];

    $("#adminsKpis").html(tiles.map(function (t) {
      return '<div class="mc-admin-kpi-card' + (t.value === 0 ? ' is-zero' : '') + '">' +
               '<div class="mc-admin-kpi-head">' +
                 '<div class="mc-admin-kpi-icon mc-admin-kpi-icon--' + t.chip + '">' +
                   '<i data-lucide="' + t.icon + '" class="lucide" aria-hidden="true"></i>' +
                 '</div>' +
               '</div>' +
               '<p class="mc-admin-kpi-label">' + esc(t.label) + '</p>' +
               '<div class="mc-admin-kpi-value">' + t.value + '</div>' +
             '</div>';
    }).join(""));
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  const adminModal = new bootstrap.Modal(document.getElementById("adminModal"));
  const passwordModal = new bootstrap.Modal(document.getElementById("passwordModal"));

  let departments = [];

  function fillDepartmentSelect($sel) {
    $sel.empty().append(`<option value="">Select department</option>`);
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    departments.forEach(d => {
      const inactive = Number(d.active) === 1 ? "" : " (Inactive)";
      $sel.append(`<option value="${d.id}">${esc(d.name)}${inactive}</option>`);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  }

  function syncDeptVisibility() {
    const role = $("#adminRole").val();
    const needsDept = role === "department_admin";
    $("#adminDepartment").prop("disabled", !needsDept);
    if (!needsDept) $("#adminDepartment").val("");
  }

  function showAdminError(msg) {
    $("#adminModalError").removeClass("d-none").text(msg || "Something went wrong.");
  }
  function clearAdminError() {
    $("#adminModalError").addClass("d-none").text("");
  }
  function showPasswordError(msg) {
    $("#passwordModalError").removeClass("d-none").text(msg || "Something went wrong.");
  }
  function clearPasswordError() {
    $("#passwordModalError").addClass("d-none").text("");
  }

  function loadDepartments() {
    return $.getJSON(deptsEndpoint, { include_inactive: 1 }).done(function (res) {
      if (!res || !res.ok) return;
      departments = res.departments || [];
      fillDepartmentSelect($("#adminDepartment"));
    });
  }

  function badge(active) {
    return Number(active) === 1
      ? `<span class="mc-admin-aa-pill mc-admin-aa-pill--mint">Active</span>`
      : `<span class="mc-admin-aa-pill mc-admin-aa-pill--muted">Inactive</span>`;
  }

  /* Flat pills. These replace `badge bg-success` / `bg-warning text-dark` /
     `bg-danger`, which common.css remaps to three different visual languages, so
     this one cell could show solid green, pale amber and solid red at once. */
  function twoFaBadge(mobile) {
    if (!mobile) return `<span class="mc-admin-aa-pill mc-admin-aa-pill--muted"><i data-lucide="shield-off" class="lucide"></i>2FA off</span>`;
    const digits = mobile.replace(/\D/g, "");
    if (digits.length < 10) return `<span class="mc-admin-aa-pill mc-admin-aa-pill--amber"><i data-lucide="triangle-alert" class="lucide"></i>Bad number</span>`;
    return `<span class="mc-admin-aa-pill mc-admin-aa-pill--mint"><i data-lucide="shield-check" class="lucide"></i>2FA SMS</span>`;
  }

  function rolePill(role) {
    return role === "super_admin"
      ? `<span class="mc-admin-aa-pill mc-admin-aa-pill--primary"><i data-lucide="shield-check" class="lucide"></i>Super Admin</span>`
      : `<span class="mc-admin-aa-pill mc-admin-aa-pill--sky"><i data-lucide="building-2" class="lucide"></i>Dept Admin</span>`;
  }

  /* Returns { date, time } so the cell can stack them on two lines. A single
     toLocaleString() string did not fit the column and wrapped, orphaning the
     "PM" onto a line of its own. */
  function fmtLastLogin(v) {
    if (!v) return null;
    const s = String(v);
    const d = new Date(s.replace(" ", "T"));
    if (isNaN(d.getTime())) return { date: s, time: "" };
    return {
      date: d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "2-digit" }),
      time: d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" })
    };
  }

  let allAdmins = [];

  function loadAdmins() {
    $("#adminsHint").html("<div class='text-muted'>Loading...</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    $.getJSON(adminsEndpoint)
      .done(function (res) {
        if (!res || !res.ok) {
          $("#adminsHint").html("<div class='text-danger'>Failed to load admin accounts.</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        allAdmins = res.admins || [];
        renderKpis(allAdmins);
        renderAdmins();
      })
      .fail(function () {
        $("#adminsHint").html("<div class='text-danger'>Failed to load admin accounts.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      });
  }

  function renderAdmins() {
        const q = (($("#adminSearchBox").val() || "").trim().toLowerCase());
        const rows = !q ? allAdmins : allAdmins.filter(r => String(r.name || "").toLowerCase().includes(q) || String(r.email || "").toLowerCase().includes(q));
        const $tb = $("#adminsTable tbody").empty();
        $("#adminsCount").text(rows.length + (rows.length === 1 ? " record" : " records"));
        if (rows.length === 0) {
          $tb.append(emptyRow("No admins found",
            q ? "No administrator matches that search. Try a different name or email."
              : "No administrator accounts exist yet."));
          $("#adminsHint").html("");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
          return;
        }
        $("#adminsHint").html("<div class='text-muted small'>Changes save automatically.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        rows.forEach(r => {
          const checked = Number(r.active) === 1 ? "checked" : "";
          const isSelf = Number(r.id) === CURRENT_ADMIN_ID;
          const mobileRaw = r.mobile || "";
          const mobileDisplay = mobileRaw
            ? (mobileRaw.replace(/\D/g, "").length >= 10
                ? (mobileRaw.startsWith("+") ? mobileRaw.substring(0,5) + "****" + mobileRaw.slice(-4) : mobileRaw.substring(0,4) + "****" + mobileRaw.slice(-4))
                : mobileRaw)
            : "—";
          const lastLogin = fmtLastLogin(r.last_login_at);
          const deptName = String(r.department_name || "");
          $tb.append(`
            <tr data-id="${r.id}"
                data-name="${esc(r.name)}"
                data-email="${esc(r.email)}"
                data-mobile="${esc(r.mobile || "")}"
                data-role="${esc(r.role)}"
                data-department-id="${r.department_id || ""}">
              <td data-label="Name">
                <span class="mc-cell-wrap">
                  <span class="mc-admin-aa-avatar" aria-hidden="true">${esc(initials(r.name))}</span>
                  <span class="fw-semibold mc-truncate" title="${esc(r.name)}">${esc(r.name)}</span>
                  ${isSelf ? '<span class="mc-admin-aa-pill mc-admin-aa-pill--self">You</span>' : ""}
                </span>
              </td>
              <td data-label="Email"><span class="mc-truncate" title="${esc(r.email)}">${esc(r.email)}</span></td>
              <td data-label="Mobile (SMS OTP)">
                <span class="mc-cell-stack">
                  <span class="mc-cell-nowrap">${esc(mobileDisplay)}</span>
                  <span class="mc-cell-sub">${twoFaBadge(r.mobile || "")}</span>
                </span>
              </td>
              <td data-label="Role">${rolePill(r.role)}</td>
              <td data-label="Department"><span class="mc-truncate" title="${esc(deptName)}">${esc(deptName) || "—"}</span></td>
              <td data-label="Last login">
                <span class="mc-cell-stack">
                  <span class="mc-cell-nowrap${lastLogin ? "" : " text-muted"}">${lastLogin ? esc(lastLogin.date) : "Never"}</span>
                  ${lastLogin && lastLogin.time ? `<span class="mc-cell-sub">${esc(lastLogin.time)}</span>` : ""}
                </span>
              </td>
              <td data-label="Active">
                <span class="mc-admin-aa-active">
                  <span class="mc-admin-switch">
                    <input type="checkbox" id="admin-active-${r.id}" class="admin-active" ${checked}
                           ${isSelf ? "disabled" : ""}
                           aria-label="${isSelf ? "Your own account cannot be disabled" : "Enable account for " + esc(r.name)}">
                    <label class="mc-admin-aa-state" for="admin-active-${r.id}" aria-hidden="true"></label>
                  </span>
                </span>
              </td>
              <td data-label="Actions" class="text-end">
                <span class="mc-admin-aa-actions">
                  <button type="button" class="mc-admin-aa-action-btn edit-admin">
                    <i data-lucide="pencil" class="lucide" aria-hidden="true"></i> Edit
                  </button>
                  <button type="button" class="mc-admin-aa-action-btn mc-admin-aa-action-btn--quiet reset-pass">
                    <i data-lucide="key-round" class="lucide" aria-hidden="true"></i> Reset
                  </button>
                </span>
              </td>
            </tr>
          `);
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        });
  }

  function openCreateModal() {
    clearAdminError();
    $("#adminModalTitle").text("Add Admin");
    $("#adminId").val("");
    $("#adminName").val("");
    $("#adminEmail").val("");
    $("#adminMobile").val("");
    $("#adminRole").val("department_admin");
    $("#adminDepartment").val("");
    $("#adminPassword").val("");
    $("#createPasswordRow").show();
    syncDeptVisibility();
    adminModal.show();
  }

  function openEditModal($tr) {
    clearAdminError();
    $("#adminModalTitle").text("Edit Admin");
    $("#adminId").val($tr.data("id"));
    $("#adminName").val($tr.data("name"));
    $("#adminEmail").val($tr.data("email"));
    $("#adminMobile").val($tr.data("mobile") || "");
    $("#adminRole").val($tr.data("role"));
    $("#adminDepartment").val(String($tr.data("department-id") || ""));
    $("#adminPassword").val("");
    $("#createPasswordRow").hide();
    syncDeptVisibility();
    adminModal.show();
  }

  function openPasswordModal($tr) {
    clearPasswordError();
    $("#passwordAdminId").val($tr.data("id"));
    $("#newPassword").val("");
    passwordModal.show();
  }

  $("#addAdminBtn").on("click", openCreateModal);
  $("#adminRole").on("change", syncDeptVisibility);
  $("#adminSearchBox").on("input", function () {
    clearTimeout(window.__adminSearchTimer);
    window.__adminSearchTimer = setTimeout(renderAdmins, 80);
  });
  $("#adminSearchBox").on("keydown", function(e){ if(e.key==='Escape'){ $(this).val('').blur(); renderAdmins(); } });
  $("#adminRefreshBtn").on("click", function () {
    $("#adminSearchBox").val("");
    renderAdmins();
  });

  $("#saveAdminBtn").on("click", function () {
    clearAdminError();
    const id = $("#adminId").val();
    const name = ($("#adminName").val() || "").trim();
    const email = ($("#adminEmail").val() || "").trim();
    const mobile = ($("#adminMobile").val() || "").trim();
    const role = $("#adminRole").val();
    const department_id = $("#adminDepartment").val() || "";
    const password = $("#adminPassword").val() || "";
    const payload = { action: id ? "update" : "create", id, name, email, mobile, role, department_id };
    if (!id) payload.password = password;

    $("#saveAdminBtn").prop("disabled", true);
    $.post(adminsEndpoint, payload)
      .done(function (res) {
        if (!res || !res.ok) {
          showAdminError(res && res.error ? res.error : "Failed to save admin.");
          return;
        }
        adminModal.hide();
        loadAdmins();
      })
      .fail(function (xhr) {
        const msg = xhr && xhr.status === 409 ? "Email already exists." : "Failed to save admin.";
        showAdminError(msg);
      })
      .always(() => $("#saveAdminBtn").prop("disabled", false));
  });

  $("#adminsTable").on("click", ".edit-admin", function () {
    openEditModal($(this).closest("tr"));
  });

  $("#adminsTable").on("click", ".reset-pass", function () {
    openPasswordModal($(this).closest("tr"));
  });

  $("#setPasswordBtn").on("click", function () {
    clearPasswordError();
    const id = $("#passwordAdminId").val();
    const password = $("#newPassword").val() || "";
    $("#setPasswordBtn").prop("disabled", true);
    $.post(adminsEndpoint, { action: "set_password", id, password })
      .done(function (res) {
        if (!res || !res.ok) {
          showPasswordError(res && res.error ? res.error : "Failed to update password.");
          return;
        }
        passwordModal.hide();
        $("#adminsHint").html("<div class='text-success small'>Password updated.</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      })
      .fail(function () {
        showPasswordError("Failed to update password.");
      })
      .always(() => $("#setPasswordBtn").prop("disabled", false));
  });

  $("#adminsTable").on("change", ".admin-active", function () {
    const $tr = $(this).closest("tr");
    const id = $tr.data("id");
    const active = $(this).is(":checked") ? 1 : 0;
    const $box = $(this);
    $box.prop("disabled", true);
    $.post(adminsEndpoint, { action: "set_active", id, active })
      .done(function (res) {
        if (!res || !res.ok) {
          /* The server is the authority on self-disable / last-super-admin rules —
             show its message verbatim instead of a generic failure. */
          $("#adminsHint").html("<div class='text-danger'>" + esc(res && res.error ? res.error : "Failed to update admin.") + "</div>");
          if (typeof window.__renderLucide === 'function') window.__renderLucide();
        }
        loadAdmins();
      })
      .fail(function (xhr) {
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
          ? xhr.responseJSON.error
          : "Failed to update admin.";
        $("#adminsHint").html("<div class='text-danger'>" + esc(msg) + "</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        loadAdmins();
      })
      .always(() => $box.prop("disabled", false));
  });

  loadDepartments().always(loadAdmins);
});
</script>
HTML;
$pageScripts = str_replace(
    ["__ADMINS_ENDPOINT__", "__DEPTS_ENDPOINT__", "__CURRENT_ADMIN_ID__"],
    [$adminsEndpoint, $deptsEndpoint, (string) $currentAdminIdJs],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';

