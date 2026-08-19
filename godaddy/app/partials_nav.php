<?php
// $active should be set by the including page to one of:
// 'home' | 'incidents' | 'daily' | 'therapy' | 'medications' | 'status' | 'export'
$active = $active ?? '';
function nav_class($key, $active) { return $key === $active ? 'nav-link active' : 'nav-link'; }
?>
<nav class="mainnav">
  <a class="<?= nav_class('home', $active) ?>" href="index.php">Home</a>
  <a class="<?= nav_class('incidents', $active) ?>" href="incidents.php">Incidents</a>
  <a class="<?= nav_class('daily', $active) ?>" href="daily.php">Daily Log</a>
  <a class="<?= nav_class('therapy', $active) ?>" href="therapy.php">Therapy</a>
  <a class="<?= nav_class('medications', $active) ?>" href="medications.php">Medications</a>
  <a class="<?= nav_class('status', $active) ?>" href="status.php">Status</a>
  <a class="<?= nav_class('export', $active) ?>" href="export.php">Export</a>
  <a class="nav-link logout" href="logout.php">Log out</a>
</nav>
