<?php
// Site-wide footer — version + copyright, bottom-right, on desktop; copyright
// only, centered at the bottom, on mobile (LeeWard standard footer, Aug 2026 —
// see leeward/tools/php/components.php's lw_footer()). Links to LeeWard Terms
// of Service at the site root (aileeward.com/terms.php), copied from
// WardStock's terms. require_once (not include) so this partial works
// standalone regardless of whether the including page already loaded
// app_version.php itself.
require_once __DIR__ . '/app_version.php';
?>
<footer class="site-foot">
  <p class="copy foot-version">v<?= htmlspecialchars(APP_VERSION) ?> &ldquo;<?= htmlspecialchars(APP_VERSION_NAME) ?>&rdquo;</p>
  <p class="copy"><a href="../terms.php">Copyright 2026 LeeWard</a></p>
</footer>
