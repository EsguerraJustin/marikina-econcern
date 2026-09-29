<?php

declare(strict_types=1);

/* Super-admin "Edit citizen identity" modal.
 *
 * Shared by admin/citizens.php (per-row Edit) and admin/citizen_view.php
 * (sidebar Edit). Both were going to need the same six fields, the same
 * verification warning and the same save handler, so the markup lives here and
 * assets/js/admin-citizen-edit.js owns the behaviour. The page only supplies
 * the endpoint and calls window.__openCitizenEdit({...}).
 *
 * SCOPE: name, email, mobile, barangay. Deliberately not here:
 *   - active / deleted_at  -> the archive flow with its confirmation modal
 *   - password_hash        -> password reset, never an inline edit
 *   - moderator_notes      -> private admin-only field
 *   - otp_enabled          -> the citizen's own security setting
 *
 * The "email is already verified" line and the mark-verified checkbox are the
 * important part. By default an admin-set address is marked UNVERIFIED and a
 * link is mailed to it, so an admin cannot quietly park an account on an
 * address the citizen does not control. The checkbox is the explicit,
 * audited override for fixing a typo on an account you can already reach.
 *
 * ID CONTRACT WITH assets/js/admin-citizen-edit.js. Every id the script looks
 * up must exist in this file, and nothing in the repo checks that. It shipped
 * broken for exactly that reason: this paragraph was id="citizenEditTarget"
 * while the script asked for el("citEdTarget"), so the lookup returned null, the
 * assignment threw, and the exception landed between the field writes and
 * modal.show(). The visible result was a completely silent dead button on BOTH
 * admin/citizens.php and admin/citizen_view.php - no dialog, no error, and the
 * six fields left populated inside a modal that never opened. The id is now
 * citEdTarget to match the other eleven citEd* elements the script drives, and
 * the script now asserts the whole list up front, so the next mismatch names
 * itself in the console instead of failing silently.
 */

$editModalId = (string) ($citizenEditModalId ?? 'citizenEditModal');
$editTitleId = $editModalId . 'Label';

// ba_list_barangays() lives in includes/basuraalert.php, which the admin shell
// does not load. Pulled in only for the suggestion list, and only if the caller
// has not already got it.
if (!function_exists('ba_list_barangays')) {
    @require_once __DIR__ . '/../basuraalert.php';
}
?>
<div class="modal fade" id="<?= e($editModalId) ?>" tabindex="-1" aria-hidden="true" aria-labelledby="<?= e($editTitleId) ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="citizenEditForm" autocomplete="off" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="<?= e($editTitleId) ?>">Edit citizen information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-muted small mb-3" id="citEdTarget">&mdash;</p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="citEdFirst">First Name</label>
                            <input type="text" class="form-control" id="citEdFirst" name="first_name"
                                   maxlength="100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="citEdLast">Last Name</label>
                            <input type="text" class="form-control" id="citEdLast" name="last_name"
                                   maxlength="100" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="citEdEmail">Email Address</label>
                            <input type="email" class="form-control" id="citEdEmail" name="email"
                                   maxlength="190" required autocomplete="off">
                            <div class="form-text" id="citEdVerifyNote">
                                This is the address the citizen signs in with and where password
                                resets are sent.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="citEdMobile">Mobile Number</label>
                            <input type="tel" class="form-control" id="citEdMobile" name="mobile"
                                   maxlength="30" required inputmode="numeric" autocomplete="off"
                                   placeholder="09XXXXXXXXX">
                            <div class="form-text">Required. Used for SMS OTP and collection alerts.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="citEdBrgy">Barangay</label>
                            <input type="text" class="form-control" id="citEdBrgy" name="barangay"
                                   maxlength="120" autocomplete="off" list="citizenBarangayOptions">
                            <datalist id="citizenBarangayOptions">
<?php
/* Suggestions come from the barangays table via the same helper the citizen's
   own profile uses (public/profile.php -> ba_list_barangays), so an admin
   typing here lands on the canonical spelling that the BasuraAlert schedule and
   announcement filters match on. The field stays a free-text input rather than
   a <select> because a few accounts carry a barangay that is no longer active,
   and a hard <select> would silently blank those on save. */
$__db = function_exists('db') ? db() : null;
$__barangays = [];
if ($__db !== null && function_exists('ba_list_barangays')) {
    try {
        $__barangays = ba_list_barangays($__db, false);
    } catch (Throwable $e) {
        $__barangays = [];   // suggestions are a convenience, never a blocker
    }
}
foreach ($__barangays as $__b) {
    $__name = (string) ($__b['name'] ?? '');
    if ($__name !== '') {
        echo '                                <option value="' . e($__name) . "\"></option>\n";
    }
}
?>
                            </datalist>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0 d-none" id="citEdVerifyWarn" role="alert">
                        <div class="fw-semibold">This will require the citizen to verify the new address.</div>
                        <div class="small">
                            Their account keeps working, but on next sign-in they will be asked to
                            confirm the new email. Use this when you are not certain the address is theirs.
                        </div>
                    </div>

                    <div class="form-check mt-3 d-none" id="citEdVerifiedWrap">
                        <input class="form-check-input" type="checkbox" value="1" id="citEdMarkVerified"
                               name="mark_verified">
                        <label class="form-check-label" for="citEdMarkVerified">
                            I have confirmed this address with the citizen &mdash; mark it verified now
                            <span class="text-muted">(no verification email will be sent)</span>
                        </label>
                        <div class="form-text">This is recorded in the admin activity log against your name.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" id="citEdSaveBtn">
                        <i data-lucide="check" class="lucide lucide-16" aria-hidden="true"></i> Save changes
                    </button>
                </div>
            </form>
            <div class="px-3 pb-3" id="citEdAlert" role="status" aria-live="polite"></div>
        </div>
    </div>
</div>
