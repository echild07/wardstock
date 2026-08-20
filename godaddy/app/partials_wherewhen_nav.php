<?php
// Sub-nav for everything living under the top-level "Where When" tab
// (Fulgrim, PLAN.md §18) — Analysis / Status / Export / Therapy.
// $subActive should be set by the including page to one of:
// 'analysis' | 'status' | 'export' | 'therapy'
// The including page is also expected to have already set $active =
// 'wherewhen' and included partials_nav.php (the top nav), before this.
$subActive = $subActive ?? '';
function wherewhen_nav_class($key, $subActive) { return $key === $subActive ? 'nav-link active' : 'nav-link'; }
?>
<nav class="mainnav subnav">
  <a class="<?= wherewhen_nav_class('analysis', $subActive) ?>" href="analysis.php">Analysis</a>
  <a class="<?= wherewhen_nav_class('status', $subActive) ?>" href="status.php">Status</a>
  <a class="<?= wherewhen_nav_class('export', $subActive) ?>" href="export.php">Export</a>
  <a class="<?= wherewhen_nav_class('therapy', $subActive) ?>" href="therapy.php">Therapy</a>
</nav>
