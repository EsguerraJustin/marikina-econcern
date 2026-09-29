/* =========================================================================
   Shared profile-avatar upload behaviour.
   Loaded on any page that renders includes/partials/avatar.php with an
   editable avatar. Expects window.AVATAR_UPLOAD_ENDPOINT to be set by the
   page before this runs.

   Talks to the SAME endpoint as everything else on the page (jQuery + the
   global CSRF header from assets/js/app.js), so there is no second HTTP
   convention to learn.

   Contract:
     POST <endpoint>  { action: 'upload_avatar' | 'delete_avatar', avatar: <File> }
     -> 200 { ok: true, display_url: '...' }   or { ok: false, error: '...' }

   __initAvatarUpload({ endpoint, boxId, inputId, removeId, alertId, initials,
                        fallbackClass, pickId, tileTriggers })
     pickId is OPTIONAL: the id of a button outside the avatar box that also
     opens the file picker. The camera badge inside the box always works.
     tileTriggers is OPTIONAL: also let a click on the tile itself open the
     picker. The topnav greeting needs this - it renders a file input but no
     camera badge, because a 35px badge on a 40px tile in a nav bar has nowhere
     to go, and a <button> inside the greeting's <a> is invalid HTML.

   On success the <img> src is swapped in place and the initials fallback is
   removed, so there is no full page reload and no layout jump. The new src
   always carries a unique fragment (see uniqueUrl): the public_id is
   deterministic and the asset is overwritten in place, so the server can return
   a byte-identical URL for a genuinely new photo, and assigning an unchanged
   src to an <img> that already loaded that URL is a no-op.

   LOAD ORDER: this file MUST be a plain (non-deferred) <script> emitted
   immediately before the page script that calls __initAvatarUpload. A
   `defer`red copy has not executed yet when a page script runs during
   parsing, so the upload controls silently never bind.
   ========================================================================= */
(function () {
  "use strict";

  function csrf() {
    if (typeof window.__csrfToken === "string" && window.__csrfToken) return window.__csrfToken;
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute("content") || "") : "";
  }

  /* The pick trigger can live outside the box (cfg.pickId), so dimming the box
     alone would leave a button that looks live but is a no-op mid-upload. */
  function busy(on, box, cfg) {
    if (box) {
      if (on) {
        box.dataset.busy = "1";
        box.style.opacity = ".55";
        box.style.pointerEvents = "none";
      } else {
        delete box.dataset.busy;
        box.style.opacity = "";
        box.style.pointerEvents = "";
      }
    }
    if (cfg && cfg.pickId) {
      var pick = document.getElementById(cfg.pickId);
      if (pick) pick.disabled = !!on;
    }
  }

  /* The box is sized by --mc-avatar-size (common.css), so a 96px list tile
     and a 128px profile hero share one class. Read it back off the box rather
     than hardcoding 96, or a swapped-in <img> overflows the larger tile. */
  function boxSize(box) {
    var raw = window.getComputedStyle(box).getPropertyValue("--mc-avatar-size");
    var px = parseInt(String(raw), 10);
    return px > 0 ? px : 96;
  }

  /* Every painted URL gets a unique fragment so the browser cannot satisfy the
     assignment from its own cache.

     The public_id is deterministic (admin_<id> / citizen_<id>) and the upload
     overwrites the asset in place, so the server CAN return a byte-identical URL
     for a genuinely new photo. Assigning an unchanged src to an <img> is a no-op:
     the element keeps the pixels it already has and fires no request, so the
     page reported "Profile photo updated." while still showing the old photo
     (measured in Chrome: 0 Cloudinary requests across two different uploads).
     The server now sends a versioned path segment, which handles the normal case;
     this nonce handles two uploads inside one page view, where the version can
     land inside the same second and therefore be identical. */
  var paintNonce = 0;
  function uniqueUrl(url) {
    if (!url) return "";
    paintNonce += 1;
    return url + (url.indexOf("#") === -1 ? "#" : "&") + "mc=" + paintNonce;
  }

  /* Tiles initialised on this page, so one upload can update all of them. */
  var registry = [];

  /* Repaint every other tile showing the SAME account. Matching is an exact
     public_id comparison against the box's data-avatar-id, which the partial
     emits from the row value - far safer than trying to parse a public_id back
     out of a delivery URL, since it is a multi-segment path
     (marikina_concern/uploads/admin_1). A page that also renders OTHER people's
     avatars is unaffected: those tiles are built by the page's own JS and never
     registered here. */
  function repaintSiblings(publicId, displayUrl, fallbackInitials) {
    if (!publicId || !displayUrl) return;
    registry.forEach(function (e) {
      if (e.id !== publicId) return;
      paint(e.box, displayUrl, e.initials || fallbackInitials, e.fallbackClass);
    });
  }

  /* Replace the current avatar contents with either an <img> or the initials
     tile. Mirrors includes/partials/avatar.php so both stay in step. */
  function paint(box, displayUrl, initials, fallbackClass) {
    if (!box) return;
    var existingImg = box.querySelector("[data-avatar-img]");
    var fb = box.querySelector("[data-avatar-fallback]");
    if (displayUrl) {
      var fresh = uniqueUrl(displayUrl);
      if (existingImg) {
        existingImg.src = fresh;
      } else {
        var px = boxSize(box);
        var img = document.createElement("img");
        img.setAttribute("data-avatar-img", "");
        img.alt = "";
        img.width = px; img.height = px;
        img.loading = "lazy"; img.decoding = "async";
        img.src = fresh;
        if (fb && fb.parentNode) fb.parentNode.removeChild(fb);
        // insert before the edit button so the overlay stays on top
        if (box.firstChild) box.insertBefore(img, box.firstChild);
        else box.appendChild(img);
      }
    } else {
      if (existingImg && existingImg.parentNode) existingImg.parentNode.removeChild(existingImg);
      if (!fb) {
        var span = document.createElement("span");
        // Size is inherited from the box, so only the role tint is passed in.
        span.className = "mc-avatar--fallback" + (fallbackClass ? " " + fallbackClass : "");
        span.setAttribute("data-avatar-fallback", "");
        span.setAttribute("aria-hidden", "true");
        span.textContent = initials || "?";
        box.insertBefore(span, box.firstChild);
      }
    }
  }

  /* "Remove photo" is a destructive no-op when there is no photo, so it is
     disabled until one exists and re-armed the moment an upload lands. */
  function syncRemoveState(cfg) {
    var btn = document.getElementById(cfg.removeId);
    var box = document.getElementById(cfg.boxId);
    if (!btn || !box) return;
    var hasPhoto = !!box.querySelector("[data-avatar-img]");
    btn.disabled = !hasPhoto;
    btn.setAttribute("aria-disabled", hasPhoto ? "false" : "true");
  }

  function alertBox(id, kind, msg) {
    var el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = '<div class="alert alert-' + kind + ' small">' +
      String(msg).replace(/[&<>"']/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
      }) + "</div>";
    if (typeof window.__renderLucide === "function") window.__renderLucide();
  }

  function upload(box, file, cfg) {
    if (box.dataset.busy === "1") return;
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      alertBox(cfg.alertId, "danger", "That photo is larger than 2 MB. Please choose a smaller one.");
      return;
    }
    busy(true, box, cfg);
    var fd = new FormData();
    fd.append("action", "upload_avatar");
    fd.append("csrf_token", csrf());
    fd.append("avatar", file);

    $.ajax({
      url: cfg.endpoint,
      method: "POST",
      data: fd,
      processData: false,
      contentType: false,
      dataType: "json",
      timeout: 90000,
      headers: { "X-CSRF-Token": csrf() }
    }).done(function (res) {
      if (res && res.ok) {
        paint(box, res.display_url, cfg.initials, cfg.fallbackClass);
        repaintSiblings(res.public_id, res.display_url, cfg.initials);
        syncRemoveState(cfg);
        alertBox(cfg.alertId, "success", "Profile photo updated.");
        if (typeof window.__onAvatarChanged === "function") window.__onAvatarChanged(res);
      } else {
        alertBox(cfg.alertId, "danger", (res && res.error) ? res.error : "Photo upload failed.");
      }
    }).fail(function (xhr) {
      var msg = "Network error. Please try again.";
      if (xhr && xhr.status === 401) {
        msg = "Your session expired. Please sign in again.";
      } else if (xhr && xhr.status === 403) {
        /* require_csrf_token() answers 403 with hint=csrf_mismatch. It used to
           answer 419, but this Apache rewrites 419 to 500, so the check for it
           could never match. A 403 without that hint is a permission failure,
           which is worth naming separately. */
        var hint = "";
        try { hint = (xhr.responseJSON && xhr.responseJSON.hint) || ""; } catch (e) { hint = ""; }
        msg = (hint === "csrf_mismatch")
          ? "Security token expired. Please reload the page and try again."
          : "You do not have permission to change this photo.";
      } else if (xhr && xhr.status === 413) {
        msg = "That photo is too large for the server. Please choose a smaller one.";
      } else if (xhr && xhr.status === 422) {
        try { msg = (xhr.responseJSON && xhr.responseJSON.error) || "That photo could not be accepted."; }
        catch (e) { msg = "That photo could not be accepted."; }
      }
      alertBox(cfg.alertId, "danger", msg);
    }).always(function () {
      busy(false, box, cfg);
    });
  }

  function remove(box, cfg) {
    if (box.dataset.busy === "1") return;
    if (!window.confirm("Remove your profile photo?")) return;
    busy(true, box, cfg);
    $.ajax({
      url: cfg.endpoint,
      method: "POST",
      data: { action: "delete_avatar", csrf_token: csrf() },
      dataType: "json",
      timeout: 30000,
      headers: { "X-CSRF-Token": csrf() }
    }).done(function (res) {
      if (res && res.ok) {
        paint(box, "", cfg.initials, cfg.fallbackClass);
        // Clearing the photo must clear every tile for that account too, or the
        // topnav keeps showing a picture the user just deleted.
        registry.forEach(function (e) {
          if (e.id && e.id === (res.public_id || "")) {
            paint(e.box, "", e.initials || cfg.initials, e.fallbackClass);
          }
        });
        syncRemoveState(cfg);
        alertBox(cfg.alertId, "success", "Profile photo removed.");
        if (typeof window.__onAvatarChanged === "function") window.__onAvatarChanged(res);
      } else {
        alertBox(cfg.alertId, "danger", (res && res.error) ? res.error : "Could not remove the photo.");
      }
    }).fail(function () {
      alertBox(cfg.alertId, "danger", "Network error. Please try again.");
    }).always(function () {
      busy(false, box, cfg);
    });
  }

  window.__initAvatarUpload = function (cfg) {
    var box = document.getElementById(cfg.boxId);
    if (!box) return;
    var input = document.getElementById(cfg.inputId);

    /* Every tile this page initialised, so a successful upload can repaint the
       others. The profile hero and the topnav greeting are two tiles for the SAME
       account; uploading through one used to leave the other showing the previous
       photo until a reload, which is the same class of bug one level down. */
    var id = box.getAttribute("data-avatar-id") || "";
    var entry = { box: box, id: id, initials: cfg.initials, fallbackClass: cfg.fallbackClass };
    var known = registry.some(function (e) { return e.box === box; });
    if (!known) registry.push(entry);

    /* Triggers: the camera badge rendered inside the box by the partial, plus
       an optional button elsewhere on the page (cfg.pickId) so the layout can
       put the upload action in a toolbar instead of on the tile itself.

       cfg.tileTriggers adds the TILE ITSELF. The topnav greeting renders the
       avatar with a file input but no camera badge - a 35px badge on a 40px tile
       inside a nav bar has nowhere to go, and a <button> inside the greeting's
       <a> is invalid HTML - so without this the header photo looked clickable
       (cursor:pointer, wrapped in a profile link) and silently did nothing. */
    var triggers = Array.prototype.slice.call(box.querySelectorAll("[data-avatar-pick]"));
    if (cfg.pickId) {
      var external = document.getElementById(cfg.pickId);
      if (external && triggers.indexOf(external) === -1) triggers.push(external);
    }
    if (cfg.tileTriggers && triggers.indexOf(box) === -1) triggers.push(box);

    triggers.forEach(function (el) {
      el.addEventListener("click", function (ev) {
        if (el === box && ev.target.closest && ev.target.closest("[data-avatar-pick]")) return;
        /* The topnav tile lives inside the greeting's <a> to the profile page.
           Opening the picker must not also navigate away, so the default action
           is cancelled for the tile only - clicking the name beside it still
           follows the link. */
        if (el === box && ev && typeof ev.preventDefault === "function") {
          ev.preventDefault();
          ev.stopPropagation();
        }
        if (input) input.click();
      });
    });

    if (input) {
      input.addEventListener("change", function () {
        if (input.files && input.files[0]) upload(box, input.files[0], cfg);
        input.value = "";   // allow re-picking the same file
      });
    }
    var rm = document.getElementById(cfg.removeId);
    if (rm) rm.addEventListener("click", function () { remove(box, cfg); });

    syncRemoveState(cfg);
    window.__syncAvatarRemoveState = function () { syncRemoveState(cfg); };
  };
})();
