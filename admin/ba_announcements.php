<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/basuraalert.php';

$pageTitle = 'Announcements (Admin)';
$activeNav = 'ba_announcements';

require_once __DIR__ . '/../includes/partials/head.php';
require_once __DIR__ . '/../includes/partials/admin_shell_start.php';

$endpoint = e(app_url('/admin/api/basuraalert_admin.php'));
echo csrf_header_meta();
$barangays = ba_list_barangays($mysqli);
$items = [];
try {
    $items = ba_list_announcements($mysqli, null, 'admin');
} catch (Throwable $e) {}

$kindClass = ['Holiday'=>'bg-info','Disruption'=>'bg-danger','Delay'=>'bg-warning text-dark','Cancellation'=>'bg-danger','Resumption'=>'bg-success','Schedule_Change'=>'bg-primary','General'=>'bg-secondary'];
$statusClass = ['Published'=>'bg-success','Archived'=>'bg-secondary','Draft'=>'bg-warning text-dark'];

/* Card presentation maps, derived entirely from the existing `kind` / `status`
   ENUM columns — no new data required. */
$kindBanner = [
    'General'         => ['navy',   'megaphone'],
    'Holiday'         => ['amber',  'calendar-days'],
    'Delay'           => ['amber',  'clock'],
    'Schedule_Change' => ['mint',   'calendar-cog'],
    'Resumption'      => ['mint',   'circle-check'],
    'Disruption'      => ['danger', 'triangle-alert'],
    'Cancellation'    => ['danger', 'ban'],
];
$statusBadge = [
    'Published' => 'mc-admin-badge--success',
    'Draft'     => 'mc-admin-badge--warning',
    'Archived'  => 'mc-admin-badge--muted',
];

$publishFlash = null;
if (session_status() === PHP_SESSION_NONE) @session_start();
if (isset($_SESSION['ba_announce_publish_stats']) && is_array($_SESSION['ba_announce_publish_stats'])) {
    $publishFlash = $_SESSION['ba_announce_publish_stats'];
    unset($_SESSION['ba_announce_publish_stats']);
}

function ba_render_publish_stats_html(array $s): string {
    $total = (int)($s['users_total'] ?? 0);
    $ia = (int)($s['sent_in_app'] ?? 0);
    $sms = (int)($s['sent_sms'] ?? 0);
    $em = (int)($s['sent_email'] ?? 0);
    $skipEmail = (int)($s['skip_no_email'] ?? 0);
    $skipSms = (int)($s['skip_no_mobile'] ?? 0);
    $skipPrefs = (int)($s['skip_prefs_off'] ?? 0);
    $smsFail = (int)($s['sms_fail'] ?? 0);
    $emFail = (int)($s['email_fail'] ?? 0);
    $parts = [];
    $parts[] = '<div class="fw-semibold mb-2"><i data-lucide="megaphone" class="lucide-14"></i> Publish Notification Delivery — Report</div>';
    $parts[] = '<div class="mb-2 small">Scope: <strong>' . $total . '</strong> citizen' . ($total === 1 ? '' : 's') . ' with valid barangay set.</div>';
    $parts[] = '<div class="row g-2 mb-3 small">';
    $parts[] = '<div class="col-auto"><span class="badge bg-primary"><i data-lucide="bell" class="lucide-14"></i> In-App: ' . $ia . '</span></div>';
    $parts[] = '<div class="col-auto"><span class="badge bg-success"><i data-lucide="smartphone" class="lucide-14"></i> SMS: ' . $sms . '</span></div>';
    $parts[] = '<div class="col-auto"><span class="badge bg-info text-dark"><i data-lucide="mail" class="lucide-14"></i> Email: ' . $em . '</span></div>';
    $parts[] = '</div>';
    $skips = [];
    if ($skipSms > 0) $skips[] = '<i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> SMS skipped for ' . $skipSms . ' user' . ($skipSms === 1 ? '' : 's') . ' (no valid PH mobile on file — ask them to update profile)';
    if ($skipEmail > 0) $skips[] = '<i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> Email skipped for ' . $skipEmail . ' user' . ($skipEmail === 1 ? '' : 's') . ' (no valid email on file — ask them to update profile)';
    if ($skipPrefs > 0) $skips[] = '<i data-lucide="info" class="lucide-14 text-info"></i> ' . $skipPrefs . ' user' . ($skipPrefs === 1 ? '' : 's') . ' opted out of all channels — in-app fallback sent';
    if ($smsFail > 0) $skips[] = '<i data-lucide="circle-alert" class="lucide-14 text-danger"></i> SMS send failed for ' . $smsFail . ' user' . ($smsFail === 1 ? '' : 's') . ' (check TextBee API key / account balance)';
    if ($emFail > 0) $skips[] = '<i data-lucide="circle-alert" class="lucide-14 text-danger"></i> Email send failed for ' . $emFail . ' user' . ($emFail === 1 ? '' : 's') . ' (check Brevo API key / MAIL_FROM_ADDRESS in config.php)';
    if ($skips !== []) {
        $parts[] = '<ul class="list-unstyled mb-0 small">';
        foreach ($skips as $sk) $parts[] = '<li class="mb-1">' . $sk . '</li>';
        $parts[] = '</ul>';
    } else {
        $parts[] = '<div class="small text-muted"><i data-lucide="check" class="lucide-14 text-success"></i> No skips — every reachable citizen got all their opted-in channels.</div>';
    }
    return implode('', $parts);
}
?>
<div id="listAlert"></div>

<div class="mc-admin-hero">
    <div class="mc-admin-hero-inner">
        <div class="mc-admin-hero-title-row">
            <div class="mc-admin-hero-icon">
                <i data-lucide="megaphone" class="lucide lucide-24"></i>
            </div>
            <div class="mc-admin-hero-title">
                <h1>Announcements &amp; Alerts</h1>
                <p>Publish holiday notices, schedule changes, and service disruptions. Publishing automatically pushes in-app notifications to affected residents.</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($isSuper) : ?>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="new"><i data-lucide="plus" class="lucide-14"></i> New Announcement</button>
            <?php else : ?>
                <span class="small text-white-50 fst-italic"><i data-lucide="triangle-alert" class="lucide-14"></i> View-only — only Super Admins can publish announcements.</span>
            <?php endif; ?>
            <img src="<?= e(app_url('/assets/img/Basura Module Logo.jpg')) ?>" alt="Basura Module Logo" class="mc-admin-hero-seal">
        </div>
    </div>
</div>

<?php if ($publishFlash !== null) : ?>
    <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        <?= ba_render_publish_stats_html($publishFlash) ?>
    </div>
<?php endif; ?>
<?php if (count($items) === 0) : ?>
    <div class="mc-admin-empty">
        <div class="mc-admin-empty-icon"><i data-lucide="megaphone" class="lucide-24"></i></div>
        <h4>No announcements yet</h4>
        <p>Use <strong>+ New Announcement</strong> to publish your first holiday notice, schedule change, or service disruption.</p>
    </div>
<?php else : ?>
    <div class="mc-admin-announce-grid">
        <?php foreach ($items as $a) :
            $kindMeta = $kindBanner[(string) $a['kind']] ?? ['navy', 'megaphone'];
            $isDraft  = ((string) $a['status'] === 'Draft');
        ?>
            <article class="mc-admin-announce-card<?= $isDraft ? ' mc-admin-announce-card--draft' : '' ?>"
                data-id="<?= (int) $a['id'] ?>"
                data-title="<?= e((string) $a['title']) ?>"
                data-kind="<?= e((string) $a['kind']) ?>"
                data-scope="<?= e((string) $a['scope']) ?>"
                data-target_barangay_id="<?= (int) ($a['target_barangay_id'] ?? 0) ?>"
                data-content="<?= e((string) $a['content']) ?>"
                data-effective_date="<?= e((string) ($a['effective_date'] ?? '')) ?>"
                data-revised_schedule_info="<?= e((string) ($a['revised_schedule_info'] ?? '')) ?>"
                data-status="<?= e((string) $a['status']) ?>">

                <div class="mc-admin-announce-banner <?= e($kindMeta[0]) ?>">
                    <span class="mc-admin-announce-banner-icon" aria-hidden="true"><i data-lucide="<?= e($kindMeta[1]) ?>" class="lucide"></i></span>
                    <span class="mc-admin-announce-banner-label"><?= e(ucfirst(str_replace('_', ' ', (string) $a['kind']))) ?></span>
                </div>

                <div class="mc-admin-announce-body">
                    <h3 class="mc-admin-announce-title"><?= e((string) $a['title']) ?></h3>

                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="mc-admin-badge mc-admin-badge--muted"><?= e(ucfirst(str_replace('_', ' ', (string) $a['kind']))) ?></span>
                        <span class="mc-admin-badge <?= e($statusBadge[(string) $a['status']] ?? 'mc-admin-badge--muted') ?>"><?= e((string) $a['status']) ?></span>
                    </div>

                    <p class="mc-admin-announce-excerpt"><?= e(mb_substr((string) $a['content'], 0, 220)) ?><?= strlen((string) $a['content']) > 220 ? '…' : '' ?></p>

                    <?php if (!empty($a['revised_schedule_info'])) : ?>
                        <div class="mc-admin-announce-revised">
                            <small class="text-muted d-block">Revised schedule</small>
                            <div><?= e((string) $a['revised_schedule_info']) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="mc-admin-announce-meta">
                        <div class="mc-admin-announce-meta-left">
                            <span class="mc-admin-announce-date"><i data-lucide="calendar-days" class="lucide"></i><?= !empty($a['effective_date']) ? 'Effective ' . date('M j, Y', strtotime($a['effective_date'])) : 'No effective date' ?></span>
                            <span class="mc-admin-announce-scope"><i data-lucide="map-pin" class="lucide"></i><?= !empty($a['target_barangay_name']) ? e((string) $a['target_barangay_name']) : 'All barangays' ?></span>
                        </div>
                        <div>
                            <?php if ($isSuper) : ?>
                                <div class="mc-admin-announce-actions">
                                    <button class="btn btn-sm btn-outline-primary ba-edit-btn" type="button" data-bs-toggle="modal" data-bs-target="#editor" data-mode="edit"><i data-lucide="pencil" class="lucide-14"></i> Edit</button>
                                    <button class="btn btn-sm btn-outline-danger ba-del-btn" type="button"><i data-lucide="trash-2" class="lucide-14"></i> Delete</button>
                                </div>
                            <?php else : ?>
                                <span class="mc-admin-badge mc-admin-badge--muted">View-only</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</div><!-- /.mc-admin-ba_announcements-page -->

<div class="modal fade mc-editor-dialog" tabindex="-1" id="editor" aria-hidden="true" aria-labelledby="annLbl">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="editorForm" onsubmit="event.preventDefault(); window.__baAnnSave ? window.__baAnnSave() : null;">
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" id="fId" name="id" value="0">
                <?= csrf_field() ?>
                <div class="modal-header"><h5 class="modal-title" id="annLbl">New Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body mc-ann-modal-body">
                    <div class="mc-admin-form-group"><label class="form-label" for="fTitle">Title <span class="text-danger">*</span></label><input type="text" class="form-control mc-admin-form-control" id="fTitle" name="title" required maxlength="190"></div>
                    <div class="row g-3">
                        <div class="col-6"><div class="mc-admin-form-group"><label class="form-label" for="fKind">Kind <span class="text-danger">*</span></label>
                            <select class="form-select mc-admin-form-select" id="fKind" name="kind" required>
                                <?php foreach (['General','Holiday','Disruption','Delay','Cancellation','Resumption','Schedule_Change'] as $k) : ?>
                                    <option value="<?= $k ?>"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div></div>
                        <div class="col-6"><div class="mc-admin-form-group"><label class="form-label" for="fStatus">Status</label>
                            <select class="form-select mc-admin-form-select" id="fStatus" name="status">
                                <option value="Published">Published (will notify)</option>
                                <option value="Draft">Draft</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6"><div class="mc-admin-form-group"><label class="form-label" for="fScope">Scope <span class="text-danger">*</span></label>
                            <select class="form-select mc-admin-form-select" id="fScope" name="scope">
                                <option value="all">All barangays</option>
                                <option value="barangay">Specific barangay</option>
                            </select>
                        </div></div>
                        <div class="col-6" id="tbWrap"><div class="mc-admin-form-group"><label class="form-label" for="fTB">Target barangay <span class="text-danger">*</span></label>
                            <select class="form-select mc-admin-form-select" id="fTB" name="target_barangay_id">
                                <option value="">— N/A —</option>
                                <?php foreach ($barangays as $b) : ?>
                                    <option value="<?= (int) $b['id'] ?>"><?= e((string) $b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div></div>
                    </div>
                    <div class="mc-admin-form-group"><label class="form-label" for="fEf">Effective date</label><input type="date" class="form-control mc-admin-form-control" id="fEf" name="effective_date"></div>
                    <div class="mc-admin-form-group"><label class="form-label" for="fContent">Content <span class="text-danger">*</span></label><textarea id="fContent" name="content" rows="6" required class="form-control mc-admin-form-textarea" placeholder="Describe the notice clearly: reason, affected barangays, and what residents should do."></textarea></div>
                    <div class="mc-admin-form-group"><label class="form-label" for="fR">Revised schedule info <small class="text-muted">(if applicable)</small></label><textarea class="form-control mc-admin-form-textarea" id="fR" name="revised_schedule_info" rows="2" maxlength="255" placeholder="Short summary of new schedule"></textarea></div>
                </div>
                <div class="modal-footer border-top p-3 mc-ann-modal-footer">
                    <div id="editorAlert" role="status" aria-live="polite"></div>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" id="saveBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
(function(){
  const ENDPOINT="__ENDPOINT__";
  function csrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||""):"";}
  function eH(s){return String(s).replace(/[&<>"']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c];});}
  function setA(t,type,msg){const el=document.querySelector(t);if(!el)return;const c=type==="success"?"alert-success":type==="error"?"alert-danger":"alert-info";el.innerHTML='<div class="alert '+c+' small mb-0">'+eH(String(msg||""))+'</div>';}
  function setAHtml(t,type,html,extra){const el=document.querySelector(t);if(!el)return;const c=type==="success"?"alert-success":type==="error"?"alert-danger":"alert-info";const x=extra?(" "+String(extra)):"";el.innerHTML='<div class="alert '+c+' small mb-0'+x+'">'+String(html||"")+'</div>';if(typeof window.__renderLucide==="function"){try{window.__renderLucide();}catch(e){}}else if(window.lucide&&typeof window.lucide.createIcons==="function"){try{window.lucide.createIcons();}catch(e){}}}
  function num(v){v=parseInt(v,10);return (isFinite(v)&&v>0)?v:0;}
  function __annTodayIso(){const d=new Date();const y=d.getFullYear();const m=String(d.getMonth()+1).padStart(2,"0");const da=String(d.getDate()).padStart(2,"0");return y+"-"+m+"-"+da;}
  function __normalizeDate(raw){
    const s=String(raw||"").trim();if(s.length===0)return "";
    if(/^\d{4}-\d{2}-\d{2}$/.test(s))return s;
    if(/^\d{4}-\d{2}-\d{2}[ T].+$/.test(s))return s.substr(0,10);
    const m1=s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2}|\d{4})$/);
    if(m1){let y=parseInt(m1[3],10);if(y<100)y+=2000;return String(y).padStart(4,"0")+"-"+String(parseInt(m1[1],10)).padStart(2,"0")+"-"+String(parseInt(m1[2],10)).padStart(2,"0");}
    const d=new Date(s);if(!isNaN(d.getTime())){const y=d.getFullYear();const mm=String(d.getMonth()+1).padStart(2,"0");const dd=String(d.getDate()).padStart(2,"0");return y+"-"+mm+"-"+dd;}
    return "";
  }
  const scope=document.getElementById("fScope");const tb=document.getElementById("fTB");function syncScope(){if(scope.value==="barangay")tb.removeAttribute("disabled");else{tb.value="";tb.setAttribute("disabled","true");}}
  if(scope)scope.addEventListener("change",syncScope);syncScope();
  const editor=document.getElementById("editor");
  if(editor)editor.addEventListener("show.bs.modal",function(ev){
    const b=ev.relatedTarget;const mode=b&&b.dataset?(b.dataset.mode||"edit"):"edit";
    const row=b&&b.closest&&b.closest('[data-id]')?b.closest('[data-id]'):null;
    const id=(mode==="new"||!row)?0:parseInt(row.getAttribute("data-id")||"0",10);
    const todayIso=__annTodayIso();
    const fEf=document.getElementById("fEf");
    document.getElementById("annLbl").textContent=id>0?"Edit Announcement":"New Announcement";
    const f=(n,d="")=>row?String(row.getAttribute("data-"+n)||""):d;
    document.getElementById("fId").value=id;
    document.getElementById("fTitle").value=f("title");
    document.getElementById("fKind").value=f("kind")||"General";
    document.getElementById("fStatus").value=f("status")||"Draft";
    document.getElementById("fScope").value=f("scope")||"all";syncScope();
    document.getElementById("fTB").value=parseInt(f("target_barangay_id","0"),10)||"";
    const rawEf=f("effective_date","");
    const normEf=id===0?__normalizeDate(rawEf||todayIso):__normalizeDate(rawEf);
    fEf.min=todayIso;
    fEf.value=normEf;
    if(id===0&&(normEf===""||normEf<todayIso))fEf.value=todayIso;
    document.getElementById("fR").value=f("revised_schedule_info");
    document.getElementById("fContent").value=f("content");
    document.getElementById("editorAlert").innerHTML="";
  });
  window.__baAnnSave=function(){
    const fEf=document.getElementById("fEf");
    const idVal=parseInt(String(document.getElementById("fId").value||"0"),10)||0;
    const todayIso=__annTodayIso();
    let efRaw=__normalizeDate(String(fEf.value||""));
    if(efRaw===""&&idVal===0)efRaw=todayIso;
    if(idVal===0&&efRaw<todayIso){setA("#editorAlert","error","New announcement effective date cannot be earlier than today ("+todayIso+").");return;}
    fEf.value=efRaw;
    const b=document.getElementById("saveBtn");if(!b||b.dataset.submitting==="1")return;
    b.dataset.submitting="1";b.disabled=true;const ot=b.textContent;b.textContent="Saving…";
    $.ajax({url:ENDPOINT,method:"POST",data:$("#editorForm").serialize()+"&csrf_token="+encodeURIComponent(csrf()),dataType:"json",timeout:120000})
      .done(function(r){if(r&&r.ok){
        var safeMsg=eH(r.message||"Saved");
        if(r.publish_stats&&typeof r.publish_stats==="object"){
          var s=r.publish_stats;
          var total=num(s.users_total),ia=num(s.sent_in_app),sms=num(s.sent_sms),em=num(s.sent_email);
          var skipSms=num(s.skip_no_mobile),skipEm=num(s.skip_no_email),skipPrefs=num(s.skip_prefs_off);
          var smsFail=num(s.sms_fail),emFail=num(s.email_fail);
          var html=safeMsg+'<hr class="my-2"><div class="fw-semibold mb-1"><i data-lucide="megaphone" class="lucide-14"></i> Publish Notification — Delivery Report</div>';
          html+='<div class="mb-2">Scope: <strong>'+total+'</strong> citizen'+(total===1?'':'s')+' with valid barangay</div>';
          html+='<div class="d-flex flex-wrap gap-1 mb-2"><span class="badge bg-primary"><i data-lucide="bell" class="lucide-14"></i> In-App: '+ia+'</span> <span class="badge bg-success"><i data-lucide="smartphone" class="lucide-14"></i> SMS: '+sms+'</span> <span class="badge bg-info text-dark"><i data-lucide="mail" class="lucide-14"></i> Email: '+em+'</span></div>';
          var warns=[];
          if(skipSms>0) warns.push('<li><i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> SMS skipped: '+skipSms+'</li>');
          if(skipEm>0) warns.push('<li><i data-lucide="triangle-alert" class="lucide-14 text-warning"></i> Email skipped: '+skipEm+'</li>');
          if(skipPrefs>0) warns.push('<li><i data-lucide="info" class="lucide-14 text-info"></i> Opt-out: '+skipPrefs+' (in-app fallback sent)</li>');
          if(smsFail>0) warns.push('<li><i data-lucide="circle-alert" class="lucide-14 text-danger"></i> SMS FAILED: '+smsFail+' — check TextBee creds/balance</li>');
          if(emFail>0) warns.push('<li><i data-lucide="circle-alert" class="lucide-14 text-danger"></i> EMAIL FAILED: '+emFail+' — check Brevo API key / MAIL_FROM_ADDRESS</li>');
          if(warns.length) html+='<ul class="list-unstyled mb-0">'+warns.join("")+'</ul>';
          else html+='<div class="text-muted"><i data-lucide="check" class="lucide-14 text-success"></i> No skips — every reachable citizen got all opted-in channels.</div>';
          setAHtml("#editorAlert","success",html+"<div class=\"mt-2\">Reloading…</div>","mc-ann-report");
          b.textContent="Published! Reloading…";
        }
        else{setA("#editorAlert","success",(r.message||"Saved")+" Reloading…");}
        setTimeout(function(){location.reload();},1600);}
        else{setA("#editorAlert","error",r&&r.error?r.error:"Save failed.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;}})
      .fail(function(){setA("#editorAlert","error","Network error.");b.dataset.submitting="0";b.disabled=false;b.textContent=ot;});
  };
  document.addEventListener("click",function(ev){
    // Use closest(), not ev.target.classList: the Delete button now contains a
    // Lucide <i> that createIcons() replaces with an <svg>, so a click on the
    // icon itself would land on the SVG and never match the button's class.
    var t=ev.target;
    var delBtn=(t&&t.closest)?t.closest(".ba-del-btn"):null;
    if(delBtn){
      const row=delBtn.closest('[data-id]');if(!row)return;const id=parseInt(row.getAttribute("data-id")||"0",10);if(!id)return;
      if(!confirm("Delete this announcement? It cannot be undone."))return;
      $.ajax({url:ENDPOINT,method:"POST",data:{action:"delete_announcement",id,csrf_token:csrf()},dataType:"json",timeout:30000})
        .done(function(r){if(r&&r.ok){setA("#listAlert","success",r.message||"Deleted.");setTimeout(function(){location.reload();},400);}
          else setA("#listAlert","error",r&&r.error?r.error:"Delete failed.");})
        .fail(function(){setA("#listAlert","error","Network error.");});
    }});
})();
</script>
HTML;
$pageScripts = str_replace("__ENDPOINT__", $endpoint, $pageScripts);

require_once __DIR__ . '/../includes/partials/admin_shell_end.php';
require_once __DIR__ . '/../includes/partials/foot.php';
