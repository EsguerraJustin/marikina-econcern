<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Report an Issue';
$activeNav = 'ba_report';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$userId = (int) $user['id'];
$barangays = ba_list_barangays($mysqli);
$userBarangayId = ba_user_barangay_id($mysqli, $user);
$userBarangayName = !empty($user['barangay']) ? (string) $user['barangay'] : '';

$actionsEndpoint = app_url('/api/basuraalert_actions.php');
$maxPhotos = 5;
$maxMbPerPhoto = 5;

$categoryOptions = ba_report_category_options();

?>
<?= csrf_header_meta() ?>

<div class="mc-ba-report-page">

    <div class="mc-report-header-card mb-4">
        <div class="mc-report-header-inner">
            <div class="mc-report-header-left">
                <div class="mc-report-header-icon">
                    <i data-lucide="alert-triangle" class="lucide lucide-24"></i>
                </div>
                <div class="mc-report-header-text">
                    <h1 class="mc-report-title">Report an Issue</h1>
                    <p class="mc-report-subtitle">Document a collection or service concern for administrative review. Reports do not book on-demand pickup (see <a href="<?= e(app_url('/public/ba_faq.php')) ?>" class="mc-report-faq-link">FAQ</a>).</p>
                </div>
            </div>
            <div class="mc-report-header-brand">
                <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" class="mc-report-brand-img">
            </div>
        </div>
    </div>

    <form id="reportForm" method="POST" enctype="multipart/form-data" onsubmit="event.preventDefault(); window.__baReportSubmit ? window.__baReportSubmit() : null;" novalidate>
        <input type="hidden" name="action" value="submit_report">
        <?= csrf_field() ?>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="mc-report-form-card">
                    <div class="mc-report-card-head mb-4">
                        <div class="mc-report-card-title">Issue Details</div>
                        <div class="mc-report-card-sub">Fill in the specific details of the issue below.</div>
                    </div>

                    <div class="mc-report-field mb-4">
                        <label class="mc-report-label" for="rCategory">Report category <span class="mc-report-required">*</span></label>
                        <select id="rCategory" name="category" required class="mc-report-select">
                            <option value="">— Select category —</option>
                            <?php foreach ($categoryOptions as $val => $label) : ?>
                                <option value="<?= e($val) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mc-report-field mb-4">
                        <label class="mc-report-label" for="rBarangay">Barangay <span class="mc-report-required">*</span></label>
                        <select id="rBarangay" name="barangay_id" required class="mc-report-select">
                            <option value="">— Select barangay —</option>
                            <?php foreach ($barangays as $b) : ?>
                                <option value="<?= (int) $b['id'] ?>"
                                    <?= $userBarangayId === (int) $b['id'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($userBarangayName !== '') : ?>
                            <div class="mc-report-hint">Your profile barangay is <strong><?= e($userBarangayName) ?></strong> — selected by default.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mc-report-field mb-4">
                        <label class="mc-report-label" for="rDate">Date of concern <span class="mc-report-required">*</span></label>
                        <input type="date" id="rDate" name="date_of_concern" required class="mc-report-input" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-sm-6">
                            <div class="mc-report-field mb-0">
                                <label class="mc-report-label" for="rStreet">Street <span class="mc-report-required">*</span></label>
                                <input type="text" id="rStreet" name="street" required maxlength="190" class="mc-report-input" placeholder="e.g. Gil Fernando Ave">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="mc-report-field mb-0">
                                <label class="mc-report-label" for="rLandmark">Landmark <span class="mc-report-required">*</span></label>
                                <input type="text" id="rLandmark" name="landmark" required maxlength="190" class="mc-report-input" placeholder="Near…">
                            </div>
                        </div>
                    </div>

                    <div class="mc-report-field mb-0">
                        <label class="mc-report-label" for="rDesc">Description <span class="mc-report-required">*</span></label>
                        <textarea id="rDesc" name="description" rows="6" required class="mc-report-textarea" placeholder="Describe what you observed, where, and when…"></textarea>
                        <div class="mc-report-hint">Be specific: include time of day, waste type, vehicle numbers, or other relevant details.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="mc-report-form-card mb-4">
                    <div class="mc-report-card-head mb-4">
                        <div class="mc-report-card-title">Photo Evidence <span class="mc-report-required">*</span></div>
                        <div class="mc-report-card-sub">Upload clear photos that show the issue.</div>
                    </div>

                    <div class="mc-report-field mb-3">
                        <label for="rPhotos" class="mc-report-label">Upload photos</label>
                        <input class="mc-report-file" type="file" id="rPhotos" name="photos[]" accept="image/*" multiple required>
                        <div class="mc-report-hint mt-2">At least 1 photo required · max <?= $maxPhotos ?> photos · each up to <?= $maxMbPerPhoto ?>MB · JPG/PNG/GIF/WEBP only</div>
                        <div id="photoCounter" class="mc-report-counter mt-2">0 / <?= $maxPhotos ?> photos</div>
                    </div>

                    <div id="photoPreview" class="row g-2"></div>
                </div>

                <div class="mc-report-scope-card mb-4">
                    <div class="mc-report-scope-head mb-2">
                        <i data-lucide="info" class="lucide lucide-16 mc-report-scope-ico"></i>
                        <span class="mc-report-scope-title">Scope Reminder</span>
                    </div>
                    <div class="mc-report-scope-body">
                        A BasuraAlert report documents a possible service issue for review. It <strong>does not</strong>:
                        <ul class="mc-report-scope-list mt-2 mb-2">
                            <li>Book on-demand / special pickup</li>
                            <li>Assign or dispatch a truck</li>
                            <li>Choose a replacement collection date</li>
                        </ul>
                        An administrator will review and respond. Official schedule changes are always confirmed by an authorized admin.
                    </div>
                </div>

                <button type="submit" id="reportBtn" class="mc-report-submit">
                    <i data-lucide="send" class="lucide lucide-16"></i>
                    <span>Submit Report</span>
                </button>
                <div id="reportAlert" class="mt-3"></div>
            </div>
        </div>
    </form>

</div>

<?php
$pageScripts = <<<'HTML'
<style>
#photoCounter.counter-ok { color: #1B4F8C; }
#photoCounter.counter-warn { color: #E8622C; }
#photoCounter.counter-full { color: #dc3545; font-weight: 700; }
.photo-card-wrap { position: relative; }
.photo-remove {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: none;
  background: rgba(220, 53, 69, 0.95);
  color: #fff;
  font-size: 14px;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 2;
  padding: 0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.25);
}
.photo-remove:hover { background: #bb2d3b; }
</style>
<script>
(function () {
  const endpoint = "__ENDPOINT__";
  const MAX = __MAX__;
  const MAX_MB = __MAX_MB__;

  function csrfToken() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? String(m.content || "") : "";
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c];
    });
  }
  function resetAlert() { $("#reportAlert").html(""); if (typeof window.__renderLucide === 'function') window.__renderLucide(); }

  const fileInput = document.getElementById("rPhotos");
  const preview = document.getElementById("photoPreview");
  const counter = document.getElementById("photoCounter");
  let selectedFiles = [];

  function createDataTransfer() {
    if (window.DataTransfer) return new DataTransfer();
    return null;
  }

  function syncFileInput() {
    const dt = createDataTransfer();
    if (dt) {
      selectedFiles.forEach(function (f) {
        try { dt.items.add(f); } catch (_) {}
      });
      try { fileInput.files = dt.files; } catch (_) {}
    }
  }

  function updateCounter() {
    if (!counter) return;
    const n = selectedFiles.length;
    counter.textContent = n + " / " + MAX + " photos";
    counter.classList.remove("counter-ok", "counter-warn", "counter-full");
    if (n >= MAX) {
      counter.classList.add("counter-full");
      counter.innerHTML = n + " / " + MAX + " photos · max reached (use <i data-lucide=&quot;x&quot; class=&quot;lucide-14&quot;></i> to remove)";
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      if (fileInput) fileInput.disabled = true;
    } else if (n === MAX - 1) {
      counter.classList.add("counter-warn");
      counter.textContent = n + " / " + MAX + " photos · 1 slot remaining";
      if (fileInput) fileInput.disabled = false;
    } else {
      counter.classList.add("counter-ok");
      if (fileInput) fileInput.disabled = false;
    }
  }

  function fileKey(f) {
    return (f.name || "") + "|" + (f.size || 0) + "|" + (f.lastModified || 0);
  }

  function renderPreview() {
    preview.innerHTML = "";
    if (typeof window.__renderLucide === 'function') window.__renderLucide();
    selectedFiles.forEach(function (file, idx) {
      if (!file || !file.type || !file.type.startsWith("image/")) return;
      const reader = new FileReader();
      reader.onload = function (e) {
        const col = document.createElement("div");
        col.className = "col-4 col-md-3";
        const wrap = document.createElement("div");
        wrap.className = "photo-card-wrap";
        wrap.innerHTML =
          '<div class="border rounded overflow-hidden bg-light" style="position:relative;aspect-ratio:1/1">' +
            '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover" alt="photo preview">' +
            '<button type="button" class="photo-remove" data-idx="' + idx + '" aria-label="Remove photo" title="Remove"><i data-lucide="x" class="lucide-14"></i></button>' +
          '</div>' +
          '<div class="small text-muted mt-1 text-truncate">' + escapeHtml(file.name) + '</div>';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        col.appendChild(wrap);
        preview.appendChild(col);
        const btn = wrap.querySelector(".photo-remove");
        if (btn) btn.addEventListener("click", function () {
          const i = parseInt(String(btn.getAttribute("data-idx") || "-1"), 10);
          if (i >= 0 && i < selectedFiles.length) {
            selectedFiles.splice(i, 1);
            syncFileInput();
            renderPreview();
            updateCounter();
          }
        });
      };
      reader.readAsDataURL(file);
    });
  }

  if (fileInput) {
    fileInput.addEventListener("change", function () {
      const added = Array.prototype.slice.call(fileInput.files || []);
      const seen = {};
      selectedFiles.forEach(function (f) { seen[fileKey(f)] = true; });
      added.forEach(function (f) {
        if (!f || !f.type || !f.type.startsWith("image/")) return;
        const k = fileKey(f);
        if (seen[k]) return;
        seen[k] = true;
        selectedFiles.push(f);
      });
      syncFileInput();
      renderPreview();
      updateCounter();
    });
  }

  window.__baReportSubmit = function () {
    const btn = document.getElementById("reportBtn");
    if (!btn || btn.dataset.submitting === "1") return;
    resetAlert();

    const category = document.getElementById("rCategory").value;
    const barangayId = document.getElementById("rBarangay").value;
    const dateField = document.getElementById("rDate").value;
    const street = document.getElementById("rStreet").value.trim();
    const landmark = document.getElementById("rLandmark").value.trim();
    const desc = document.getElementById("rDesc").value.trim();
    const errs = [];
    if (!category) errs.push("Please select a category.");
    if (!barangayId) errs.push("Please select a barangay.");
    if (!dateField) errs.push("Please select a date.");
    if (!street) errs.push("Please enter the street address.");
    if (!landmark) errs.push("Please enter a nearby landmark.");
    if (!desc) errs.push("Please enter a description.");

    const files = selectedFiles.slice();
    if (files.length === 0) errs.push("Please upload at least 1 photo as evidence.");
    if (files.length > MAX) errs.push("Maximum " + MAX + " photos allowed.");
    let tooBig = false;
    let badType = false;
    let totalBytes = 0;
    files.forEach(function (f) {
      totalBytes += f.size || 0;
      if (f.size > MAX_MB * 1024 * 1024) tooBig = true;
      if (!f.type || !f.type.startsWith("image/")) badType = true;
    });
    if (tooBig) errs.push("Each photo must be less than " + MAX_MB + " MB.");
    if (badType) errs.push("Only image files (JPG/PNG/GIF/WEBP) are allowed as photo evidence.");

    if (errs.length) {
      $("#reportAlert").html('<div class="alert alert-danger"><ul class="mb-0"><li>' + errs.map(escapeHtml).join("</li><li>") + '</li></ul></div>');
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      return;
    }

    const totalMB = (totalBytes / (1024 * 1024)).toFixed(1);
    btn.dataset.submitting = "1";
    btn.disabled = true;
    const originalBtnHtml = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader-2" class="lucide lucide-16" style="animation:spin 1s linear infinite"></i><span>Iniupload… ' + escapeHtml(totalMB) + ' MB</span>';
    const sizeWarn = parseFloat(totalMB) >= 12
      ? '<div class="small mt-1 opacity-90"><i data-lucide="wifi" class="lucide-14" style="vertical-align:-2px"></i> Malaki ang sukat ng mga larawan (' + escapeHtml(totalMB) + ' MB). Sa mabagal na internet, maaaring tumagal ito ng ilang minuto — huwag isara ang tab.</div>'
      : '';
    $("#reportAlert").html('<div class="alert alert-info"><div class="fw-semibold">Iniupload ang mga larawan… <span id="uploadPct">0%</span></div><div class="progress mt-2" style="height:8px;border-radius:6px"><div id="uploadBar" class="progress-bar" role="progressbar" style="width:0%;background:#3368A0;border-radius:6px" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div><div id="uploadMsg" class="small mt-2 opacity-85">Nagpapadala ng data patungong server… huwag isara ang tab.</div>' + sizeWarn + '</div>');
    if (typeof window.__renderLucide === 'function') window.__renderLucide();

    syncFileInput();
    const fd = new FormData(document.getElementById("reportForm"));
    fd.set("csrf_token", csrfToken());

    function setPct(p) {
      const pct = Math.max(0, Math.min(100, Math.round(p)));
      const $bar = document.getElementById("uploadBar");
      const $pct = document.getElementById("uploadPct");
      const $msg = document.getElementById("uploadMsg");
      if ($bar) $bar.style.width = pct + "%";
      if ($pct) $pct.textContent = pct + "%";
      if ($msg && pct >= 98) $msg.textContent = "Tinatanggap na ng server ang report… halos tapos na.";
    }

    $.ajax({
      url: endpoint,
      method: "POST",
      data: fd,
      processData: false,
      contentType: false,
      dataType: "json",
      timeout: 300000,
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json, text/javascript, */*; q=0.01"
      },
      xhr: function () {
        const xhr = $.ajaxSettings.xhr();
        if (xhr && xhr.upload && xhr.upload.addEventListener) {
          xhr.upload.addEventListener("progress", function (ev) {
            if (ev.lengthComputable) setPct((ev.loaded / ev.total) * 95);
          }, false);
          xhr.upload.addEventListener("loadstart", function () { setPct(1); }, false);
          xhr.upload.addEventListener("load", function () { setPct(96); }, false);
        }
        return xhr;
      },
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-CSRF-Token", csrfToken());
      }
    }).done(function (res) {
      setPct(100);
      if (res && res.ok) {
        const rn = res.report_number ? String(res.report_number) : "";
        $("#reportAlert").html('<div class="alert alert-success"><div class="fw-semibold"><i data-lucide="check-circle" class="lucide-16" style="vertical-align:-2px"></i> Report submitted successfully' + (rn ? ' · reference ' + escapeHtml(rn) : '') + '</div><div class="small mt-1">Your submission has been received. You can track its status on the <a href="' + escapeHtml("__MYREPORTS_URL__") + '">My Reports</a> page. SMS/email updates (if naka-enable) ay darating na lamang pagkatapos ng ilang sandali.</div></div>');
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        document.getElementById("reportForm").reset();
        selectedFiles = [];
        if (fileInput) {
          try {
            const dt = createDataTransfer();
            if (dt) fileInput.files = dt.files;
          } catch (_) {}
          fileInput.disabled = false;
        }
        preview.innerHTML = "";
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        updateCounter();
        btn.dataset.submitting = "0";
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      } else {
        const msg = (res && res.error) ? String(res.error) : "Submit failed.";
        const inner = (res && res.errors && Array.isArray(res.errors) && res.errors.length)
          ? '<ul class="mb-0"><li>' + res.errors.map(String).map(escapeHtml).join('</li><li>') + '</li></ul>'
          : escapeHtml(msg);
        $("#reportAlert").html('<div class="alert alert-danger">' + inner + '</div>');
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
        btn.dataset.submitting = "0";
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      }
    }).fail(function (xhr, textStatus, errorThrown) {
      let msg = "Network or server error. Please try again.";
      let hint = "";
      let debug = "";
      if (endpoint) debug = " · target: " + endpoint;
      const timedOut = textStatus === "timeout";
      if (xhr) {
        debug += " · status=" + (xhr.status || "?") + (errorThrown ? " (" + String(errorThrown) + ")" : "");
        if (timedOut) {
          msg = "Na-timeout ang pag-upload dahil mabagal ang koneksyon. Bawasan ang sukat ng mga larawan o kumonekta sa mas mabilis na Wi-Fi, pagkatapos ay i-retry.";
        } else if (typeof xhr.status === "number" && xhr.status === 403) {
          /* 403 with hint=csrf_mismatch is what require_csrf_token() now sends.
             It used to send 419, but 419 is an unassigned code that this Apache
             rewrites to 500, so this branch never fired. A bare 403 that is NOT a
             csrf_mismatch is a real permission failure and is left to the
             generic message below. */
          let hint = "";
          try { hint = (xhr.responseJSON && xhr.responseJSON.hint) || ""; } catch (_) { hint = ""; }
          if (hint === "csrf_mismatch") {
            msg = "Your session expired or security token changed. Please refresh the page and try again.";
          }
        } else if (typeof xhr.status === "number" && xhr.status === 404) {
          msg = "The submit endpoint was not found on the server (404). Please check the site base URL.";
        } else if (typeof xhr.status === "number" && xhr.status >= 500) {
          msg = "Server error while saving your report. Please try again in a moment.";
        }
        if (typeof xhr.responseText === "string" && xhr.responseText) {
          try {
            const p = JSON.parse(xhr.responseText);
            if (p && p.error) msg = String(p.error);
            if (p && p.hint) hint = String(p.hint);
          } catch (_) {
            const stripped = xhr.responseText.replace(/<[^>]*>/g, "").replace(/\s+/g, " ").trim();
            if (stripped) hint = stripped.slice(0, 240);
          }
        }
      }
      const hintRow = hint ? '<div class="small mt-1">' + escapeHtml(hint) + '</div>' : '';
      const debugRow = (debug && window.console) ? '<div class="small opacity-75 mt-1">Debug:' + escapeHtml(debug) + '</div>' : '';
      $("#reportAlert").html('<div class="alert alert-danger"><div class="fw-semibold"><i data-lucide="alert-circle" class="lucide-16" style="vertical-align:-2px"></i> ' + escapeHtml(msg) + '</div>' + hintRow + debugRow + '</div>');
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
      btn.dataset.submitting = "0";
      btn.disabled = false;
      btn.innerHTML = originalBtnHtml;
      if (typeof window.__renderLucide === 'function') window.__renderLucide();
    });
  };
})();
</script>
<script>
(function(){
  var __lp = 0;
  function __renderSafe(){
    if (typeof window.__renderLucide === 'function') {
      try { window.__renderLucide(); } catch(_) {}
      return true;
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      try {
        window.lucide.createIcons({
          attrs: { width: 16, height: 16, 'stroke-width': 2, 'fill': 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' },
          nameAttr: 'data-lucide'
        });
      } catch(_) {}
      return true;
    }
    return false;
  }
  function __poll(){ if (__renderSafe()) return; if (__lp++ < 30) setTimeout(__poll, 100); }
  __poll();
  window.addEventListener('load', function(){ setTimeout(__renderSafe, 120); }, { once: true });
  window.addEventListener('pageshow', function(){ setTimeout(__renderSafe, 150); });
})();
</script>
HTML;
$pageScripts = str_replace(
    ["__ENDPOINT__", "__MAX__", "__MAX_MB__", "__MYREPORTS_URL__"],
    [$actionsEndpoint, $maxPhotos, $maxMbPerPhoto, app_url('/public/ba_my_reports.php')],
    $pageScripts
);

require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
