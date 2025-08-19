<?php
declare(strict_types=1);
header('Content-Type: application/json');

function fail(int $code, string $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
  exit;
}

require __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

$raw = file_get_contents('php://input');
if ($raw === false) fail(400, 'No payload');
$payload = json_decode($raw, true);
if (!is_array($payload)) fail(400, 'Invalid JSON');

$pdfUrl = $payload['pdfUrl'] ?? '';
$edits  = $payload['edits']  ?? null;
if (!$pdfUrl || !is_array($edits)) fail(400, 'Bad payload (missing pdfUrl or edits)');

// Resolve source path (allow serving via symlink in /public/uploads)
$srcPath = realpath(__DIR__ . '/' . ltrim((string)$pdfUrl, '/'));
if (!$srcPath || !is_readable($srcPath)) {
  fail(400, 'Source PDF not found or unreadable: ' . (string)$pdfUrl);
}

// Check output dir
$outDir = realpath(__DIR__ . '/../storage/output');
if (!$outDir) {
  $mk = @mkdir(__DIR__ . '/../storage/output', 0775, true);
  if (!$mk) fail(500, 'Cannot create output directory');
  $outDir = realpath(__DIR__ . '/../storage/output');
}
if (!is_writable($outDir)) fail(500, 'Output directory not writable');

// Helper: RGBA sanitizer
$rgba = function(array $c): array {
  $r = isset($c['r']) ? (int)$c['r'] : 255;
  $g = isset($c['g']) ? (int)$c['g'] : 255;
  $b = isset($c['b']) ? (int)$c['b'] : 255;
  $a = isset($c['a']) ? (float)$c['a'] : 1.0;
  return [
    'r' => max(0, min(255, $r)),
    'g' => max(0, min(255, $g)),
    'b' => max(0, min(255, $b)),
    'a' => max(0.0, min(1.0, $a)),
  ];
};

$pdf = new Fpdi();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false, 0);

// Safe alpha setter (older TCPDFs may not have SetAlpha)
$setAlpha = function(float $a) use ($pdf) {
  if (method_exists($pdf, 'SetAlpha')) {
    $pdf->SetAlpha($a);
  } else {
    // no-op fallback
  }
};

try {
  $pageCount = $pdf->setSourceFile($srcPath);
} catch (\Throwable $e) {
  fail(500, 'Failed to open source PDF: ' . $e->getMessage());
}

if ($pageCount <= 0) fail(400, 'PDF has no pages');
if (empty($edits)) {
  // Still allow pass-through (no edits) — just copy pages
  $edits = [];
}

for ($p = 1; $p <= $pageCount; $p++) {
  $tplId = $pdf->importPage($p);
  $ts = $pdf->getTemplateSize($tplId);

  // Add page using template's size (user units)
  $pdf->AddPage($ts['orientation'], [$ts['width'], $ts['height']]);
  $pdf->useTemplate($tplId, 0, 0, $ts['width'], $ts['height'], false);

  // Find client canvas spec (if provided)
  $pageSpec = null;
  foreach ($edits as $spec) {
    if (isset($spec['page']) && (int)$spec['page'] === $p) { $pageSpec = $spec; break; }
  }
  if (!$pageSpec) continue;

  $canvasW = max(1.0, (float)($pageSpec['canvasWidth'] ?? 1));
  $canvasH = max(1.0, (float)($pageSpec['canvasHeight'] ?? 1));
  $sx = $ts['width'] / $canvasW;
  $sy = $ts['height'] / $canvasH;

  if (!empty($pageSpec['ops']) && is_array($pageSpec['ops'])) {
    foreach ($pageSpec['ops'] as $op) {
      $type = (string)($op['type'] ?? '');
      try {
        switch ($type) {
          case 'text': {
            $color = $rgba($op['color'] ?? []);
            $setAlpha($color['a']);
            $pdf->SetTextColor((int)$color['r'], (int)$color['g'], (int)$color['b']);

            $fontSize = max(6.0, (float)($op['fontSize'] ?? 12) * $sy);
            $pdf->SetFont('helvetica', '', $fontSize, '', true);

            $x = (float)($op['left'] ?? 0) * $sx;
            $y = (float)($op['top']  ?? 0) * $sy;

            $pdf->SetXY($x, $y);
            $txt = (string)($op['text'] ?? '');
            // Write uses current text color/font; 0 line height draws at current Y
            $pdf->Write(0, $txt);
            $setAlpha(1.0);
            break;
          }

          case 'highlight': {
            $fill = $rgba($op['fill'] ?? ['r'=>255,'g'=>255,'b'=>0,'a'=>0.3]);
            $setAlpha($fill['a']);
            $pdf->SetFillColor((int)$fill['r'], (int)$fill['g'], (int)$fill['b']);

            $x = (float)($op['left'] ?? 0) * $sx;
            $y = (float)($op['top']  ?? 0) * $sy;
            $w = max(0.0, (float)($op['width']  ?? 0) * $sx);
            $h = max(0.0, (float)($op['height'] ?? 0) * $sy);

            $pdf->Rect($x, $y, $w, $h, 'F');
            $setAlpha(1.0);
            break;
          }

          case 'rect': {
            $stroke = $rgba($op['stroke'] ?? []);
            $fill   = $rgba($op['fill']   ?? ['a'=>0]);

            $lw = max(0.2, (float)($op['strokeWidth'] ?? 1) * (($sx + $sy) / 2));
            $pdf->SetLineWidth($lw);
            $pdf->SetDrawColor((int)$stroke['r'], (int)$stroke['g'], (int)$stroke['b']);

            $x = (float)($op['left'] ?? 0) * $sx;
            $y = (float)($op['top']  ?? 0) * $sy;
            $w = max(0.0, (float)($op['width']  ?? 0) * $sx);
            $h = max(0.0, (float)($op['height'] ?? 0) * $sy);

            if ($fill['a'] > 0) {
              $setAlpha($fill['a']);
              $pdf->SetFillColor((int)$fill['r'], (int)$fill['g'], (int)$fill['b']);
              $style = 'FD';
            } else {
              $style = 'D';
            }

            $pdf->Rect($x, $y, $w, $h, $style);
            $setAlpha(1.0);
            break;
          }

          default:
            // ignore unknown ops
            break;
        }
      } catch (\Throwable $e) {
        fail(500, 'Draw op failed on page ' . $p . ': ' . $e->getMessage());
      }
    }
  }
}

$outName = 'edited-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
$outPath = rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $outName;

try {
  $pdf->Output($outPath, 'F');
} catch (\Throwable $e) {
  fail(500, 'Failed to write PDF: ' . $e->getMessage());
}

// Path the browser can reach (symlink created earlier)
echo json_encode(['ok' => true, 'url' => 'output/' . $outName], JSON_UNESCAPED_SLASHES);
