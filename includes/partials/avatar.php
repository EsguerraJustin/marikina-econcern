<?php

declare(strict_types=1);

/* Shared profile-avatar renderer.
 *
 * Included by admin/profile.php, public/profile.php, admin/citizens.php and
 * admin/citizen_view.php so the avatar markup exists in exactly one place.
 *
 * Expects these to be set by the caller BEFORE including:
 *   $avatarPublicId  string  the row's avatar_public_id ('' when none)
 *   $avatarName      string  display name, used for the initials fallback
 * Optional:
 *   $avatarSize      string  'sm' | 'md' | 'lg' | 'xl' | '' (default md)
 *   $avatarRole      string  'admin' | '' — tints the fallback tile
 *   $avatarEditable  bool    render the camera badge (the file input is rendered
 *                            whenever $avatarInputId is set, editable or not)
 *   $avatarInputId   string  id for the hidden file input
 *   $avatarImgId     string  id for the <img> (so JS can swap src)
 *   $avatarActionsId string  id for the container the JS attaches to
 *   $avatarVersion   string  Cloudinary asset version, e.g. 'v1790501024'. From
 *                            mc_avatar_version_from_url($row['avatar_url']).
 *                            Without it the delivery URL does not change when the
 *                            photo is replaced, and the browser serves the cached
 *                            old asset - see mc_avatar_url().
 *
 * Rule #14: flat solid fills, 1px borders, no gradients.
 */

$__avId    = (string) ($avatarPublicId ?? '');
$__avName  = (string) ($avatarName ?? '');
$__avSize  = (string) ($avatarSize ?? 'md');
$__avRole  = (string) ($avatarRole ?? '');
$__avEdit  = !empty($avatarEditable);
$__avInput = (string) ($avatarInputId ?? '');
$__avImg   = (string) ($avatarImgId ?? '');
$__avBox   = (string) ($avatarActionsId ?? '');
$__avVer   = (string) ($avatarVersion ?? '');

$__avUrl   = function_exists('mc_avatar_url') ? mc_avatar_url($__avId, '', $__avVer) : '';
$__avInit  = function_exists('mc_avatar_initials') ? mc_avatar_initials($__avName) : '?';
$__avSizeC = $__avSize !== '' ? ' mc-avatar--' . $__avSize : '';
$__avRoleC = $__avRole === 'admin' ? ' mc-avatar--admin' : '';
$__avImgA  = $__avImg !== '' ? ' id="' . e($__avImg) . '"' : '';
$__avBoxA  = $__avBox !== '' ? ' id="' . e($__avBox) . '"' : '';

/* Intrinsic width/height for the <img>, so the browser reserves the right box
   before the CDN responds. These used to be hardcoded to 96x96 whatever the tile
   was, which reserved the wrong ratio on a 40px header tile and a 128px hero.
   The values must track --mc-avatar-size in assets/css/common.css:
   sm 40 / md 64 / lg 96 / xl 128, and '' (default) renders at the 48px base. */
$__avPx = ['sm' => 40, 'md' => 64, 'lg' => 96, 'xl' => 128][$__avSize] ?? 48;
?>
<span class="mc-avatar<?= e($__avSizeC . $__avRoleC) ?>"<?= $__avBoxA ?> data-avatar-box<?= $__avId !== '' ? ' data-avatar-id="' . e($__avId) . '"' : '' ?>>
<?php if ($__avUrl !== '') : ?>
    <img<?= $__avImgA ?> src="<?= e($__avUrl) ?>" alt="" width="<?= $__avPx ?>" height="<?= $__avPx ?>" loading="lazy" decoding="async" data-avatar-img>
<?php else : ?>
    <span class="mc-avatar--fallback<?= e($__avSizeC . $__avRoleC) ?>" data-avatar-fallback aria-hidden="true"><?= e($__avInit) ?></span>
<?php endif; ?>
<?php /* The file input is tied to $avatarInputId alone, NOT to $avatarEditable.
   The header greeting passes an input id but no camera badge: there is nowhere
   sensible to put a 35px badge on a 40px tile inside a nav bar, and a <button>
   nested in the greeting's <a> is invalid HTML. The input is display:none and is
   driven by a click handler, so the tile itself can open the picker without any
   interactive element inside the link. */ ?>
<?php if ($__avInput !== '') : ?>
    <input type="file" class="mc-avatar-input" id="<?= e($__avInput) ?>" accept="image/jpeg,image/png,image/webp" aria-label="Choose a profile photo">
<?php endif; ?>
<?php if ($__avEdit && $__avInput !== '') : ?>
    <button type="button" class="mc-avatar-edit" data-avatar-pick="<?= e($__avInput) ?>" aria-label="Change profile photo" title="Change profile photo">
        <i data-lucide="camera" class="lucide"></i>
    </button>
<?php endif; ?>
</span>
