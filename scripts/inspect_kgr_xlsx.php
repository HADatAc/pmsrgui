<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/inspect_kgr_xlsx.php <xlsx> [sheetName]\n");
    exit(1);
}

$file = $argv[1];
$targetSheet = $argv[2] ?? '';

function sx(string $xml): array {
    $d = new DOMDocument();
    if (!@$d->loadXML($xml)) {
        return [null, null];
    }
    $xp = new DOMXPath($d);
    return [$d, $xp];
}

function normT(string $t): string {
    $t = ltrim($t, '/');
    return str_starts_with($t, 'xl/') ? $t : ('xl/' . $t);
}

function cval(DOMElement $c, DOMXPath $xp, array $ss): string {
    $type = $c->getAttribute('t');
    if ($type === 'inlineStr') {
        $v = '';
        foreach ($xp->query('.//*[local-name()="t"]', $c) as $n) {
            $v .= $n->textContent;
        }
        return trim($v);
    }
    $vn = $xp->query('*[local-name()="v"]', $c);
    if ($vn->length === 0) {
        return '';
    }
    $raw = $vn->item(0)->textContent;
    if ($type === 's') {
        return trim((string)($ss[(int)$raw] ?? ''));
    }
    return trim((string)$raw);
}

$z = new ZipArchive();
if ($z->open($file) !== true) {
    fwrite(STDERR, "Cannot open $file\n");
    exit(2);
}

$wb = $z->getFromName('xl/workbook.xml');
$rels = $z->getFromName('xl/_rels/workbook.xml.rels');
if ($wb === false || $rels === false) {
    fwrite(STDERR, "Missing workbook metadata\n");
    exit(3);
}

[$d1,$x1] = sx($wb);
[$d2,$x2] = sx($rels);
$relById = [];
foreach ($x2->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $rel) {
    if (!$rel instanceof DOMElement) { continue; }
    $relById[$rel->getAttribute('Id')] = normT($rel->getAttribute('Target'));
}

$ss = [];
$ssXml = $z->getFromName('xl/sharedStrings.xml');
if ($ssXml !== false) {
    [$ds,$xs] = sx($ssXml);
    if ($ds && $xs) {
        foreach ($xs->query('//*[local-name()="si"]') as $si) {
            $txt = '';
            foreach ($xs->query('.//*[local-name()="t"]', $si) as $t) {
                $txt .= $t->textContent;
            }
            $ss[] = $txt;
        }
    }
}

$sheetNodes = $x1->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]');
foreach ($sheetNodes as $sheet) {
    if (!$sheet instanceof DOMElement) { continue; }
    $name = $sheet->getAttribute('name');
    $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
    $path = $relById[$rid] ?? '';
    echo "SHEET=$name PATH=$path\n";
}

foreach ($sheetNodes as $sheet) {
    if (!$sheet instanceof DOMElement) { continue; }
    $name = $sheet->getAttribute('name');
    if ($targetSheet !== '' && strcasecmp($targetSheet, $name) !== 0) {
        continue;
    }
    $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
    $path = $relById[$rid] ?? '';
    if ($path === '') { continue; }
    $xml = $z->getFromName($path);
    if ($xml === false) { continue; }
    [$d,$xp] = sx($xml);
    if (!$d || !$xp) { continue; }
    echo "\n=== SHEET $name ===\n";
    $rows = $xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
    $max = min(20, $rows->length);
    for ($i=0; $i<$max; $i++) {
      $row = $rows->item($i);
      if (!$row instanceof DOMElement) { continue; }
      $vals = [];
      foreach ($xp->query('*[local-name()="c"]',$row) as $c) {
        if (!$c instanceof DOMElement) { continue; }
        $ref = $c->getAttribute('r');
        $vals[] = $ref.'='.cval($c,$xp,$ss);
      }
      echo 'ROW '.($i+1).': '.implode(' | ',$vals)."\n";
    }
    if ($targetSheet !== '') { break; }
}

$z->close();
