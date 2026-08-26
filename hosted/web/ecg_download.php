<?php
// Authenticated artifact download — EKG_DESIGN.md's requirement that
// PDFs "require authenticated access" and never be directly URL-
// reachable. Storing the file as a MySQL BLOB (see sql/schema.sql's
// ecg_artifacts comment) already makes that true by construction; this
// is just the one gate a logged-in Ward goes through to read it back out.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$artifactId = isset($_GET['artifact_id']) ? (int)$_GET['artifact_id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM wardstock_ecg_artifacts WHERE id = ?');
$stmt->execute([$artifactId]);
$artifact = $stmt->fetch();
if (!$artifact) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: ' . $artifact['mime_type']);
header('Content-Length: ' . $artifact['byte_size']);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $artifact['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
echo $artifact['file_blob'];
