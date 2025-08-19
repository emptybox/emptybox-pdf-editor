<?php
declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';
use setasign\Fpdi\Tcpdf\Fpdi;

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No payload']); exit; }
$payload = json_decode($raw, true);
if (!$payload || empty($payload['pdfUrl']) || empty($payload['edits'])) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad payload']); exit;
}
$srcRel = $payload['pdfUrl'];
$srcPath = realpath(__DIR__ . '/' . $srcRel);
if (!$srcPath || !is_readable($srcPath)) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Source PDF not found']); exit;
}

$pdf = new Fpdi();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false, 0);
$pageCount = $pdf->setSourceFile($srcPath);

function rgba(array $c): array {
  return [
    'r'=>max(0,min(255,(int)($c['r'] ?? 255))),
    'g'=>max(0,min(255,(int)($c['g'] ?? 255))),
    'b'=>max(0,min(255,(int)($c['b'] ?? 255))),
    'a'=>max(0,min(1.0,(float)($c['a'] ?? 1)))
  ];
}

foreach ($payload['edits'] as $pageSpec) {
  $p = (int)$pageSpec['page'];
  if ($p < 1 || $p > $pageCount) continue;

  $tplId = $pdf->importPage($p);
  $ts = $pdf->getTemplateSize($tplId);

  $pdf->AddPage($ts['orientation'], [$ts['width'], $ts['height']]);
  $pdf->useTemplate($tplId, 0, 0, $ts['width'], $ts['height'], false);

  $canvasW = max(1,(float)$pageSpec['canvasWidth']);
  $canvasH = max(1,(float)$pageSpec['canvasHeight']);
  $sx = $ts['width'] / $canvasW;
  $sy = $ts['height'] / $canvasH;

  if (!empty($pageSpec['ops']) && is_array($pageSpec['ops'])) {
    foreach ($pageSpec['ops'] as $op) {
      $type = $op['type'] ?? '';
      switch ($type) {
        case 'text': {
          $color = rgba($op['color'] ?? []);
          $pdf->SetAlpha($color['a'] ?: 1);
          $pdf->SetTextColor($color['r'], $color['g'], $color['b']);
          $fontSize = max(6, (float)$op['fontSize'] * $sy);
          $pdf->SetFont('helvetica','', $fontSize, '', true);
          $x = (float)$op['left'] * $sx;
          $y = (float)$op['top'] * $sy;
          $pdf->SetXY($x, $y);
          $txt = (string)($op['text'] ?? '');
          $pdf->Write(0, $txt);
          $pdf->SetAlpha(1);
          break;
        }
        case 'highlight': {
          $fill = rgba($op['fill'] ?? ['r'=>255,'g'=>255,'b'=>0,'a'=>0.3]);
          $pdf->SetAlpha($fill['a'] ?: 0.3);
          $pdf->SetFillColor($fill['r'], $fill['g'], $fill['b']);
          $x = (float)$op['left'] * $sx;
          $y = (float)$op['top'] * $sy;
          $w = max(0,(float)$op['width'] * $sx);
          $h = max(0,(float)$op['height'] * $sy);
          $pdf->Rect($x, $y, $w, $h, 'F');
          $pdf->SetAlpha(1);
          break;
        }
        case 'rect': {
          $stroke = rgba($op['stroke'] ?? []);
          $fill = rgba($op['fill'] ?? ['a'=>0]);
          $pdf->SetLineWidth(max(0.2, (float)($op['strokeWidth'] ?? 1) * (($sx+$sy)/2)));
          $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
          $style = 'D';
          if (($fill['a'] ?? 0) > 0) {
            $pdf->SetAlpha($fill['a']); $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']); $style = 'FD';
          }
          $x = (float)$op['left'] * $sx;
          $y = (float)$op['top'] * $sy;
          $w = max(0,(float)$op['width'] * $sx);
          $h = max(0,(float)$op['height'] * $sy);
          $pdf->Rect($x, $y, $w, $h, $style);
          $pdf->SetAlpha(1);
          break;
        }
      }
    }
  }
}

$outName = 'edited-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
$outPath = __DIR__ . '/../storage/output/' . $outName;
$pdf->Output($outPath, 'F');
$url = 'output/' . $outName;
echo json_encode(['ok'=>true, 'url'=>$url]);
