<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
  /* =========================================================
     LUCIDE ICON RENDER (2 passes to avoid race conditions)
     1. DOMContentLoaded — render existing static <i data-lucide>
     2. jQuery $(document).ready() — AFTER Bootstrap / jQuery
     3. Expose window.__renderLucide() helper for AJAX / JS templates
     (my_concern.php / any dynamic page can call this after injecting rows)
     ========================================================= */
  (function () {
    function renderAll() {
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        try {
          window.lucide.createIcons({
            attrs: { width: 16, height: 16, 'stroke-width': 2, 'fill': 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' },
            nameAttr: 'data-lucide'
          });
        } catch (e) { /* swallow lucide errors silently */ }
      }
    }
    /* Pass 1: ASAP */
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', renderAll, { once: true });
    } else {
      renderAll();
    }
    /* Pass 2: after jQuery ready (ensures any PHP-rendered late icons also render) */
    if (window.jQuery) {
      jQuery(document).ready(renderAll);
    } else {
      window.addEventListener('load', renderAll, { once: true });
    }
    /* Public helper for AJAX templates (safe to call repeatedly) */
    window.__renderLucide = renderAll;
  })();
</script>
<script src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
<script src="<?= e(app_url('/assets/js/pwa-register.js')) ?>" defer></script>
<?php if (!empty($pageScripts)) : ?>
<?= $pageScripts ?>
<?php endif; ?>
</body>
</html>
