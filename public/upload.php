<?php
declare(strict_types=1);
header('Content-Type: application/json');

$maxSize = 50 * 1024 * 1024; // 50MB
if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'No file or upload error']); exit;
}
if ($_FILES['pdf']['size'] > $maxSize) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'File too large']); exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['pdf']['tmp_name']);
if ($mime !== 'application/pdf') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid file type']); exit;
}
$base = bin2hex(random_bytes(8)) . '.pdf';
$destDir = __DIR__ . '/../storage/uploads';
$destPath = $destDir . '/' . $base;
if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Failed to store file']); exit;
}
$urlPath = 'uploads/' . $base;
echo json_encode(['ok'=>true,'url'=>$urlPath]);
