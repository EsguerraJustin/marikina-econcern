<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Submit Concern';
$activeNav = 'submit';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$barangays = [
    'Barangka',
    'Calumpang',
    'Concepcion Dos',
    'Concepcion Uno',
    'Fortune',
    'Industrial Valley',
    'Jesus Dela Peña',
    'Malanday',
    'Marikina Heights',
    'Nangka',
    'Parang',
    'San Roque',
    'Santa Elena',
    'Santo Niño',
    'Tañong',
    'Tumana',
];

$maxPerPhotoBytes = 5 * 1024 * 1024;
?>
<div class="mc-submit-concern-page">
    <div class="mc-neo-card mb-4">
        <div class="card-body p-4">
            <div class="fw-bold text-primary fs-5">Submit Concern</div>
            <div class="text-muted">Choose a department and concern type, then fill in the form.</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="mc-neo-card">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="deptSearch" class="form-label">Search concern</label>
                        <input class="form-control" id="deptSearch" placeholder="Type to search...">
                    </div>
                    <div id="deptList" class="accordion"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="mc-neo-card">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div>
                            <div class="fw-bold">Step 2: Details</div>
                            <div class="text-muted small" id="selectedTypeText">Select a concern type first</div>
                        </div>
                        <span class="badge badge-accent align-self-start" id="selectedTypeBadge" style="display:none;"></span>
                    </div>

                    <form id="concernForm" class="mt-3" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="concern_type_id" id="concernTypeId">
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$maxPerPhotoBytes ?>">

                        <div class="mb-3">
                            <label for="street" class="form-label">Street Name</label>
                            <input id="street" class="form-control" name="street" autocomplete="address-line1" required>
                        </div>
                        <div class="mb-3">
                            <label for="barangay" class="form-label">Barangay</label>
                            <select id="barangay" class="form-select" name="barangay" autocomplete="address-level2" required>
                                <option value="" selected disabled>Select barangay</option>
                                <?php foreach ($barangays as $b) : ?>
                                    <option value="<?= e($b) ?>"><?= e($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="landmark" class="form-label">Landmark</label>
                            <input id="landmark" class="form-control" name="landmark" autocomplete="off" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" class="form-control" name="description" rows="4" maxlength="500" autocomplete="off" required></textarea>
                            <div class="text-muted small mt-1"><span id="descCount">0</span>/500</div>
                        </div>
                        <div class="mb-3">
                            <label for="photos" class="form-label">Optional Photos (max 5 images, 5MB each)</label>
                            <input id="photos" class="form-control" type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                            <div id="photoHint" class="text-muted small mt-1">Allowed: JPG, PNG, GIF, WEBP</div>
                            <div id="photoPreviewGrid" class="mc-photo-preview-grid mt-3"></div>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary" type="button" id="openConfirmBtn">Submit</button>
                        </div>
                        <div class="mt-3" id="submitAlert"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade mc-submit-confirm-modal" id="confirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="confirmModalLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalLabel">
                    <i data-lucide="shield-alert" class="lucide"></i>
                    Confirm Submission
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                    <i data-lucide="x" class="lucide lucide-16"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="mc-submit-confirm-warning" role="alert">
                    <i data-lucide="alert-triangle" class="lucide"></i>
                    <span>False or prank reports are not allowed. Please confirm that your report is true and accurate.</span>
                </div>
                <label class="mc-submit-confirm-check" for="prankCheck">
                    <input class="form-check-input" type="checkbox" value="1" id="prankCheck">
                    <span class="form-check-label">I confirm that this is not a prank report.</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i data-lucide="x" class="lucide"></i>
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmSubmitBtn" disabled>
                    <i data-lucide="send-horizontal" class="lucide"></i>
                    Submit
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$departmentsEndpoint = e(app_url('/api/departments.php'));
$submitEndpoint = e(app_url('/api/submit_concern.php'));
$viewUrl = e(app_url('/public/concern_view.php'));
$pageScripts = <<<'HTML'
<script>
$(function () {
  const endpoint = "__DEPT_ENDPOINT__";
  const submitEndpoint = "__SUBMIT_ENDPOINT__";
  const MAX_PER_PHOTO = 5 * 1024 * 1024;
  const MAX_PHOTOS = 5;

  function renderDepartments(departments) {
    const $list = $("#deptList").empty();
    departments.forEach((d, idx) => {
      const itemId = "dept-" + d.id;
      const headId = "head-" + d.id;
      const typesHtml = (d.types || []).map(t => `
        <button type="button" class="btn btn-sm btn-outline-primary me-2 mb-2" data-type-id="${t.id}">${$("<div>").text(t.name).html()}</button>
      `).join("");
      $list.append(`
        <div class="accordion-item">
          <h2 class="accordion-header" id="${headId}">
            <button class="accordion-button ${idx === 0 ? "" : "collapsed"}" type="button" data-bs-toggle="collapse" data-bs-target="#${itemId}">
              ${$("<div>").text(d.name).html()}
            </button>
          </h2>
          <div id="${itemId}" class="accordion-collapse collapse ${idx === 0 ? "show" : ""}">
            <div class="accordion-body">
              ${typesHtml || "<div class='text-muted small'>No types available</div>"}
            </div>
          </div>
        </div>
      `);
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  }

  function showServerError(xhr) {
    let msg = "Submit failed. Please try again.";
    let hint = "";
    if (xhr && xhr.responseJSON) {
      if (xhr.responseJSON.error) msg = String(xhr.responseJSON.error);
      if (xhr.responseJSON.hint) hint = String(xhr.responseJSON.hint);
    } else if (xhr && typeof xhr.responseText === "string" && xhr.responseText.length > 0) {
      try {
        const p = JSON.parse(xhr.responseText);
        if (p && p.error) msg = String(p.error);
      } catch (_) {
        if (xhr.status === 413) msg = "Your submission is too large. Try fewer images or smaller photos.";
        else if (xhr.status === 401) msg = "Session expired. Please log in again.";
        else if (xhr.status >= 500) msg = "Server error. Please try again later.";
      }
    } else if (xhr && xhr.status === 413) {
      msg = "Your submission is too large. Try fewer images or smaller photos.";
    } else if (xhr && xhr.status === 0) {
      msg = "Network error. Please check your connection and try again.";
    }
    let html = "<div class='alert alert-danger'><div class='fw-semibold'>" + escapeHtml(msg) + "</div>";
    if (hint) html += "<div class='small mt-1'>" + escapeHtml(hint) + "</div>";
    html += "</div>";
    $("#submitAlert").html(html);
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function validatePhotos(fileList) {
    const errs = [];
    if (!fileList || fileList.length === 0) return errs;
    if (fileList.length > MAX_PHOTOS) errs.push("Please select at most " + MAX_PHOTOS + " images.");
    for (let i = 0; i < Math.min(fileList.length, MAX_PHOTOS + 1); i++) {
      const f = fileList[i];
      if (!f) continue;
      if (f.size > MAX_PER_PHOTO) errs.push("Image '" + f.name + "' exceeds 5MB limit (" + Math.round(f.size / 1024 / 1024 * 10) / 10 + "MB).");
      const okType = /^image\/(jpeg|png|gif|webp)$/i.test(f.type || "");
      if (!okType) {
        const ext = (f.name.split(".").pop() || "").toLowerCase();
        if (["jpg","jpeg","png","gif","webp"].indexOf(ext) === -1) {
          errs.push("Image '" + f.name + "' is not a supported format (JPG, PNG, GIF, WEBP).");
        }
      }
    }
    return errs;
  }

  let allDepartments = [];

  $("#deptList").html("<div class='text-muted small'>Loading...</div>");
  if (typeof window.__renderLucide === 'function') window.__renderLucide();

  $.getJSON(endpoint).done(function(res){
    if (!res || !res.ok) {
      $("#deptList").html("<div class='alert alert-danger'>Failed to load departments.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    allDepartments = res.departments || [];
    if (!allDepartments.length) {
      $("#deptList").html(
        "<div class='alert alert-warning mb-0'>" +
          "<div class='fw-semibold'>No departments found.</div>" +
          "<div class='small'>Import <code>database/schema.sql</code> or add rows to <code>departments</code> and <code>concern_types</code>.</div>" +
        "</div>"
      );
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    renderDepartments(allDepartments);
  }).fail(function(xhr, status){
    const extra = status === "parsererror"
      ? "<div class='small mt-2'>The server returned a non-JSON response (often caused by being redirected to the login page). Try logging out and logging in again.</div>"
      : "";
    $("#deptList").html(
      "<div class='alert alert-danger mb-0'>" +
        "<div class='fw-semibold'>Failed to load departments.</div>" +
        extra +
        "<div class='small mt-2'>Open endpoint: <a href='" + endpoint + "' target='_blank' rel='noopener'>" + endpoint + "</a></div>" +
      "</div>"
    );
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  });

  $("#deptSearch").on("input", function () {
    const q = ($(this).val() || "").toLowerCase();
    if (!q) {
      renderDepartments(allDepartments);
      return;
    }
    const filtered = allDepartments.map(d => {
      const types = (d.types || []).filter(t => (t.name || "").toLowerCase().includes(q) || (d.name || "").toLowerCase().includes(q));
      const matchDept = (d.name || "").toLowerCase().includes(q);
      return matchDept ? d : { ...d, types };
    }).filter(d => (d.name || "").toLowerCase().includes(q) || (d.types || []).length > 0);
    renderDepartments(filtered);
  });

  $("#deptList").on("click", "button[data-type-id]", function () {
    const id = $(this).data("type-id");
    const name = $(this).text();
    const deptName = $(this)
      .closest(".accordion-item")
      .find(".accordion-header .accordion-button")
      .first()
      .text()
      .trim();
    $("#concernTypeId").val(id);
    $("#selectedTypeText").text(deptName ? `${deptName} — ${name}` : name);
    $("#selectedTypeBadge").hide().text("");
    $("#submitAlert").empty();
  });

  const $desc = $("textarea[name='description']");
  const updateCount = () => $("#descCount").text(($desc.val() || "").length);
  $desc.on("input", updateCount);
  updateCount();

  let _selPhotos = [];
  const $photoHint = $("#photoHint");
  const $photoGrid = $("#photoPreviewGrid");
  const _fOk = f => /^image\/(jpeg|png|gif|webp)$/i.test(f.type || "") || ["jpg","jpeg","png","gif","webp"].indexOf(String(f.name.split(".").pop()||"").toLowerCase()) >= 0;
  function _syncPhotos() {
    try {
      const dt = new DataTransfer();
      _selPhotos.forEach(p => dt.items.add(p.file));
      document.getElementById("photos").files = dt.files;
    } catch(_) {}
  }
  function renderPhotos() {
    if (_selPhotos.length === 0) {
      $photoGrid.empty();
      _syncPhotos();
      if ($photoHint.hasClass("text-muted")) $photoHint.text("Allowed: JPG, PNG, GIF, WEBP");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    $photoGrid.html(_selPhotos.map((p,i)=>`<div class="mc-photo-preview-item" data-i="${i}" title="${escapeHtml(p.file.name)}"><img src="${p.url}" alt=""><button type="button" class="mc-photo-preview-del" data-d="${i}" aria-label="Remove image"><i data-lucide="x" class="lucide"></i></button></div>`).join(""));
    $photoGrid.find("[data-d]").off("click").on("click", e => {
      e.preventDefault(); e.stopPropagation();
      const i = parseInt($(e.currentTarget).attr("data-d"),10);
      if (isNaN(i)||i<0||i>=_selPhotos.length) return;
      try { URL.revokeObjectURL(_selPhotos[i].url); } catch(_) {}
      _selPhotos.splice(i,1);
      renderPhotos();
      if ($photoHint.hasClass("text-danger")) return;
      $photoHint.removeClass("text-danger").addClass("text-muted").text(_selPhotos.length>0?`Ready: ${_selPhotos.length} image(s) selected (Allowed: JPG, PNG, GIF, WEBP)`:"Allowed: JPG, PNG, GIF, WEBP");
    });
    _syncPhotos();
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  }
  $("#photos").on("change", function () {
    const files = this.files;
    const skip = [];
    if (files && files.length > 0) {
      for (let i = 0; i < files.length; i++) {
        if (_selPhotos.length >= MAX_PHOTOS) { skip.push(`Maximum ${MAX_PHOTOS} images allowed.`); break; }
        const f = files[i]; if (!f) continue;
        if (!_fOk(f)) { skip.push(`'${escapeHtml(f.name)}' is not a supported format (JPG, PNG, GIF, WEBP).`); continue; }
        if (f.size > MAX_PER_PHOTO) { skip.push(`'${escapeHtml(f.name)}' exceeds 5MB limit.`); continue; }
        _selPhotos.push({ file: f, url: URL.createObjectURL(f) });
      }
    }
    this.value = "";
    if (skip.length > 0) {
      $photoHint.removeClass("text-muted").addClass("text-danger").html("<ul class='mb-0 ps-3'><li>" + skip.join("</li><li>") + "</li></ul>");
    } else {
      const errs = validatePhotos(_selPhotos.map(p=>p.file));
      if (errs.length > 0) {
        $photoHint.removeClass("text-muted").addClass("text-danger").html("<ul class='mb-0 ps-3'><li>" + errs.map(escapeHtml).join("</li><li>") + "</li></ul>");
      } else {
        $photoHint.removeClass("text-danger").addClass("text-muted").text(_selPhotos.length>0?`Ready: ${_selPhotos.length} image(s) selected (Allowed: JPG, PNG, GIF, WEBP)`:"Allowed: JPG, PNG, GIF, WEBP");
      }
    }
    renderPhotos();
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
  });

  const modal = new bootstrap.Modal(document.getElementById("confirmModal"));
  const confirmBtnEl = document.getElementById("confirmSubmitBtn");
  const confirmBtnOriginalText = confirmBtnEl ? confirmBtnEl.textContent : "Submit";
  document.getElementById("confirmModal").addEventListener("hidden.bs.modal", function () {
    if (confirmBtnEl) {
      confirmBtnEl.dataset.submitting = "0";
      confirmBtnEl.disabled = true;
      confirmBtnEl.textContent = confirmBtnOriginalText;
    }
    const pc = document.getElementById("prankCheck");
    if (pc) pc.checked = false;
  });

  $("#openConfirmBtn").on("click", function () {
    const typeId = $("#concernTypeId").val();
    if (!typeId) {
      $("#submitAlert").html("<div class='alert alert-danger'>Please select a concern type first.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    const form = document.getElementById("concernForm");
    if (typeof form.reportValidity === "function" && !form.reportValidity()) {
      $("#submitAlert").html("<div class='alert alert-danger'>Please complete all required fields.</div>");
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    const photoErrs = validatePhotos(document.getElementById("photos").files);
    if (photoErrs.length > 0) {
      $("#submitAlert").html(
        "<div class='alert alert-danger'><div class='fw-semibold'>Please fix the selected photos:</div><ul class='mb-0 ps-3 mt-1'>" +
        photoErrs.map(escapeHtml).map(s => "<li>" + s + "</li>").join("") +
        "</ul></div>"
      );
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }
    $("#prankCheck").prop("checked", false);
    $("#confirmSubmitBtn").prop("disabled", true);
    modal.show();
  });

  $("#prankCheck").on("change", function () {
    $("#confirmSubmitBtn").prop("disabled", !this.checked);
  });

  $("#confirmSubmitBtn").on("click", function () {
    const btn = this;
    if (btn.dataset.submitting === "1") return;

    const fd = new FormData(document.getElementById("concernForm"));

    btn.dataset.submitting = "1";
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = "Submitting…";
    $("#submitAlert").html("<div class='alert alert-info'>Submitting your concern… please wait.</div>");
    if (typeof window.__renderLucide === 'function') window.__renderLucide();

    $.ajax({
      url: submitEndpoint,
      method: "POST",
      data: fd,
      processData: false,
      contentType: false,
      timeout: 120000
    }).done(function(res){
      if (!res || !res.ok) {
        $("#submitAlert").html("<div class='alert alert-danger'>" + (res && res.error ? escapeHtml(res.error) : "Submit failed") + "</div>");
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        btn.dataset.submitting = "0";
        btn.disabled = false;
        btn.textContent = originalText;
        modal.hide();
        return;
      }
      modal.hide();
      window.location.href = "__VIEW_URL__" + "?id=" + encodeURIComponent(res.concern_id);
    }).fail(function(xhr){
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.textContent = originalText;
      modal.hide();
      showServerError(xhr);
    });
  });
});
</script>
HTML;
$pageScripts = str_replace(
    ["__DEPT_ENDPOINT__", "__SUBMIT_ENDPOINT__", "__VIEW_URL__"],
    [$departmentsEndpoint, $submitEndpoint, $viewUrl],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
