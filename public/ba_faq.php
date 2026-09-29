<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

csrf_check();

$pageTitle = 'FAQ, Feedback & Contact Us';
$activeNav = 'ba_faq';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/app_shell_start.php';

$userId = (int) $user['id'];
$actionsEndpoint = e(app_url('/api/basuraalert_actions.php'));

$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['faq','feedback','contact'], true) ? (string) $_GET['tab'] : 'faq';
$faqs = ba_list_faqs($mysqli, '');

$feedbackAlert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['submit_feedback'], true)) {
    $kind = isset($_POST['kind']) ? (string) $_POST['kind'] : 'Feedback';
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $ok = true;
    if (!in_array($kind, ['Feedback','FAQ_Suggestion','Contact_Us'], true)) { $kind = 'Contact_Us'; }
    if ($subject === '' || strlen($subject) > 190) { $feedbackAlert = '<div class="alert alert-danger">Subject required (max 190 characters).</div>'; $ok = false; }
    if ($ok && ($message === '' || strlen($message) > 4000)) { $feedbackAlert = '<div class="alert alert-danger">Message required (max 4000 characters).</div>'; $ok = false; }

    $photos = [];
    $errors = [];
    $hasPhotos = isset($_FILES['photos']) && is_array($_FILES['photos']) && isset($_FILES['photos']['name']);
    if ($hasPhotos) {
        $count = is_array($_FILES['photos']['name']) ? count($_FILES['photos']['name']) : 0;
        if ($count > 5) { $feedbackAlert = '<div class="alert alert-danger">Maximum 5 photos allowed.</div>'; $ok = false; $count = 0; }
        $mimeExtMap = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/webp' => '.webp'];
        for ($i = 0; $i < $count; $i++) {
            $errCode = isset($_FILES['photos']['error'][$i]) ? (int) $_FILES['photos']['error'][$i] : UPLOAD_ERR_NO_FILE;
            if ($errCode === UPLOAD_ERR_NO_FILE) continue;
            if ($errCode !== UPLOAD_ERR_OK) {
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    $errors[] = 'Photo at position ' . ($i + 1) . ' exceeds the maximum allowed size (5 MB).';
                } else {
                    $errors[] = 'Photo at position ' . ($i + 1) . ' failed to upload. Please try again or select a different file.';
                }
                continue;
            }
            $tmpName = $_FILES['photos']['tmp_name'][$i] ?? '';
            if (!is_uploaded_file($tmpName)) { $errors[] = 'Photo at position ' . ($i + 1) . ' is not a valid uploaded file.'; continue; }
            $size = (int) ($_FILES['photos']['size'][$i] ?? 0);
            if ($size <= 0) { $errors[] = 'Photo at position ' . ($i + 1) . ' is empty.'; continue; }
            if ($size > 5 * 1024 * 1024) { $errors[] = 'Photo at position ' . ($i + 1) . ' must be 5MB or smaller.'; continue; }
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? (string) @finfo_file($finfo, $tmpName) : '';
            if (!isset($mimeExtMap[$mime])) { $errors[] = 'Photo at position ' . ($i + 1) . ' is an invalid type. Allowed: JPG, PNG, GIF, WEBP.'; continue; }
            $ext = $mimeExtMap[$mime];
            $fileEntry = [
                'tmp_name' => $tmpName,
                'name' => (string) ($_FILES['photos']['name'][$i] ?? ('photo_' . ($i + 1) . '.' . ltrim($ext, '.'))),
                'size' => $size,
                'error' => UPLOAD_ERR_OK,
                'type' => (string) ($_FILES['photos']['type'][$i] ?? $mime),
            ];
            $stored = ba_store_image_mock($fileEntry);
            if (empty($stored['ok']) || empty($stored['secure_url'])) {
                $errors[] = (isset($stored['error']) ? (string) $stored['error'] : ('Failed to save photo at position ' . ($i + 1) . '. Please try again.'));
                continue;
            }
            $photos[] = (string) $stored['secure_url'];
        }
    }
    if ($ok && count($errors) > 0) {
        $feedbackAlert = '<div class="alert alert-danger">' . implode(' ', $errors) . '</div>';
        $ok = false;
    }

    if ($ok) {
        $payload = ['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'message' => $message];
        if (count($photos) > 0) $payload['photos_json'] = json_encode($photos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = ba_create_feedback($mysqli, $payload);
        if ($id > 0) {
            $feedbackAlert = '<div class="alert alert-success">Message sent! An administrator will review and respond.' . (count($photos) > 0 ? ' (' . count($photos) . ' photo(s) attached.)' : '') . '</div>';
        } else {
            $feedbackAlert = '<div class="alert alert-danger">Failed to send your message. Please try again.</div>';
        }
    }
}
?>
<?= csrf_header_meta() ?>

<div class="mc-ba-faq-page">

<div class="mc-ba-faq-hero">
    <div class="mc-ba-faq-hero-inner">
        <div class="mc-ba-faq-hero-title-row">
            <div class="mc-ba-faq-hero-icon-wrap">
                <i data-lucide="circle-help" class="lucide"></i>
            </div>
            <div class="mc-ba-faq-hero-title">
                <h1>Help, Feedback &amp; Contact Us</h1>
                <p>Read the FAQ, send feedback, or contact the BasuraAlert administrator</p>
            </div>
        </div>
        <div class="mc-ba-faq-hero-media">
            <img src="<?= e(app_url('/assets/img/cityhall.png')) ?>" alt="Marikina City Hall" width="180" height="96" loading="lazy" decoding="async">
        </div>
    </div>
</div>

<nav class="mc-ba-faq-tabs" aria-label="Help sections">
    <a class="mc-ba-faq-chip<?= $tab === 'faq' ? ' active' : '' ?>" href="<?= e(app_url('/public/ba_faq.php?tab=faq')) ?>"<?= $tab === 'faq' ? ' aria-current="page"' : '' ?>><i data-lucide="circle-help" class="lucide"></i><span>FAQ</span></a>
    <a class="mc-ba-faq-chip<?= $tab === 'feedback' ? ' active' : '' ?>" href="<?= e(app_url('/public/ba_faq.php?tab=feedback')) ?>"<?= $tab === 'feedback' ? ' aria-current="page"' : '' ?>><i data-lucide="message-circle" class="lucide"></i><span>Feedback</span></a>
    <a class="mc-ba-faq-chip<?= $tab === 'contact' ? ' active' : '' ?>" href="<?= e(app_url('/public/ba_faq.php?tab=contact')) ?>"<?= $tab === 'contact' ? ' aria-current="page"' : '' ?>><i data-lucide="phone" class="lucide"></i><span>Contact Us</span></a>
</nav>

<?php if ($tab === 'faq') : ?>

    <div class="mc-neo-card mb-3">
        <div class="card-body" style="padding: var(--ba-space-4);">
            <div class="mc-ba-faq-search-row">
                <div class="mc-ba-faq-search-wrap">
                    <i data-lucide="search" class="lucide mc-ba-faq-search-icon"></i>
                    <input type="search" id="faqSearch" class="form-control mc-ba-faq-search-input" placeholder="FAQ # number only… (e.g. 3 or 12) (press Esc to clear)" autocomplete="off" spellcheck="false" inputmode="numeric" aria-label="Search FAQ by number">
                </div>
            </div>
            <div class="mc-ba-faq-search-info" id="faqInfoBar" data-total="<?= count($faqs) ?>">
                <i data-lucide="info" class="lucide"></i>
                <span>Showing <strong id="faqShowingCount"><?= count($faqs) ?></strong> of <strong><?= count($faqs) ?></strong> FAQs
                <span id="faqInfoExtra" class="ms-1"></span></span>
            </div>
        </div>
    </div>

    <div class="mc-ba-faq-card">
        <div class="mc-ba-faq-card-head">
            <h2><i data-lucide="circle-help" class="lucide"></i>Frequently Asked Questions</h2>
        </div>
        <div class="mc-ba-faq-card-body">
            <?php if (count($faqs) === 0) : ?>
                <div class="mc-ba-faq-zero">No FAQ entries yet. Try <a class="text-decoration-none" href="<?= e(app_url('/public/ba_faq.php?tab=contact')) ?>">Contact Us</a>.</div>
            <?php else : ?>
                <div id="faqZeroState" class="mc-ba-faq-zero" style="display:none;"><i data-lucide="triangle-alert" class="lucide"></i> No FAQs match that FAQ # number.</div>
                <div class="accordion mc-ba-faq-acc" id="faqAcc">
                    <?php $i = 0; foreach ($faqs as $f) : $i++; $id = 'faq-' . $i; ?>
                        <div class="accordion-item"
                             data-num="<?= (int) $i ?>"
                             id="faq-item-<?= (int) $i ?>">
                            <h3 class="accordion-header" id="h-<?= $id ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c-<?= $id ?>" aria-expanded="false" aria-controls="c-<?= $id ?>">
                                    <span class="badge bg-info text-white">FAQ #<?= $i ?></span>
                                    <?php if (!empty($f['category'])) : ?><span class="badge bg-light text-dark border"><?= e((string) $f['category']) ?></span><?php endif; ?>
                                    <span><?= e((string) $f['question']) ?></span>
                                </button>
                            </h3>
                            <div id="c-<?= $id ?>" class="accordion-collapse collapse" data-bs-parent="#faqAcc" aria-labelledby="h-<?= $id ?>">
                                <div class="accordion-body"><?= nl2br(e((string) $f['answer'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function(){
      const $s = document.getElementById('faqSearch');
      const $list = document.getElementById('faqAcc');
      const $items = $list ? $list.querySelectorAll('.accordion-item[data-num]') : [];
      const $showing = document.getElementById('faqShowingCount');
      const $zero = document.getElementById('faqZeroState');
      const $extra = document.getElementById('faqInfoExtra');
      if (!$s || $items.length === 0) return;
      const TOTAL = Number($items.length);
      let t=null;
      function runFilter(){
        const raw = $s.value.trim();
        let shown = 0;
        if (raw.length === 0) {
          $items.forEach(function(el){ el.classList.remove('d-none'); shown++; });
        } else {
          const digitsOnly = raw.replace(/\D/g,'');
          const targetInt = parseInt(digitsOnly,10) || NaN;
          $items.forEach(function(el){
            const n = parseInt(el.getAttribute('data-num')||'0',10)||0;
            let match=false;
            if (!Number.isNaN(targetInt)) {
              if (n === targetInt) match=true;
              else {
                const ns = String(n);
                if (digitsOnly.length>0 && ns.indexOf(digitsOnly)!==-1) match=true;
              }
            }
            if (match) { el.classList.remove('d-none'); shown++; } else { el.classList.add('d-none'); }
          });
        }
        if ($showing) $showing.textContent = String(shown);
        if ($zero) $zero.style.display = (shown===0 && raw.length>0) ? '' : 'none';
        if ($extra) $extra.innerHTML = (raw.length>0) ? '· <span class="text-info">(searching FAQ # only)</span>' : '';
        if (typeof window.__renderLucide === 'function') window.__renderLucide();
      }
      $s.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(runFilter,80); });
      $s.addEventListener('keydown', function(e){ if(e.key==='Escape'){ $s.value=''; $s.blur(); runFilter(); } });
    })();
    </script>

<?php else :

$formKind = $tab === 'feedback' ? 'Feedback' : 'Contact_Us';
$formHeader = $tab === 'feedback' ? 'Send Feedback' : 'Contact Us';
$formSub = $tab === 'feedback' ? 'Share your experience, suggestions, or issues you noticed with BasuraAlert.' : 'Send a question or clarification request to the BasuraAlert administrator.';
$subjectPlaceholder = $tab === 'feedback' ? 'E.g. "Notification timing suggestion" or "Segregation guide typo"' : 'Brief subject for your inquiry';

?>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" enctype="multipart/form-data" class="mc-ba-faq-card h-100">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_feedback">
                <input type="hidden" name="kind" value="<?= e($formKind) ?>">
                <div class="mc-ba-faq-card-body">
                    <div class="fw-bold fs-5"><?= e($formHeader) ?></div>
                    <div class="text-muted small mb-3"><?= e($formSub) ?></div>
                    <?php if ($feedbackAlert !== '') echo $feedbackAlert; ?>
                    <div class="mb-3">
                        <label class="form-label" for="subj">Subject *</label>
                        <input type="text" id="subj" name="subject" required maxlength="190" class="form-control" placeholder="<?= e($subjectPlaceholder) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="msg">Message *</label>
                        <textarea id="msg" name="message" rows="6" required class="form-control" placeholder="Type your message…"></textarea>
                        <div class="form-text">Maximum 4000 characters. Include relevant dates, report numbers, or barangay names so we can respond faster.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="photos"><i data-lucide="paperclip" class="lucide-14"></i> Attach photos / screenshots (optional, max 5)</label>
                        <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="form-control">
                        <div class="form-text">JPG / PNG / GIF / WEBP only. Max 5 MB per photo.</div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="mc-ba-faq-card h-100">
                <div class="mc-ba-faq-card-body">
                    <div class="fw-bold mb-3">What happens next?</div>
                    <ol class="small mb-0">
                        <li class="mb-2">Your message is stored with your BasuraAlert user account.</li>
                        <li class="mb-2">An authorized administrator reviews it, referencing any schedules or reports needed.</li>
                        <li class="mb-2">If a response is required, the administrator replies through the message system (Phase 2: via Email).</li>
                        <li>For urgent collection emergencies, please contact your barangay office directly.</li>
                    </ol>
                    <hr>
                    <div class="fw-bold mb-2">Other ways to get help</div>
                    <ul class="small mb-0">
                        <li><a class="text-decoration-none" href="<?= e(app_url('/public/ba_waste.php')) ?>">Waste Segregation Guide</a> — look up items first</li>
                        <li><a class="text-decoration-none" href="<?= e(app_url('/public/ba_announcements.php')) ?>">Announcements & Alerts</a> — holiday notices and schedule changes</li>
                        <li><a class="text-decoration-none" href="<?= e(app_url('/public/ba_my_reports.php')) ?>">My Reports</a> — track an existing issue</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

</div><!-- /.mc-ba-faq-page -->

<?php
require_once __DIR__ . '/../includes/partials/app_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
