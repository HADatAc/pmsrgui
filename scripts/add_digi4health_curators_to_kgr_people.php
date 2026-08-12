<?php

declare(strict_types=1);

/**
 * Add one curator person per Digi4Health contributor organization to KGR-PEOPLE.xlsx.
 *
 * Usage:
 *   php scripts/add_digi4health_curators_to_kgr_people.php
 */

const KGR_PEOPLE_XLSX = __DIR__ . '/../mts/KGR-PEOPLE.xlsx';
const DIGI_XLSX = __DIR__ . '/../mts/KGR-Digi4health.xlsx';
const PMSR_NS = 'https://pmsr.net/ont/';

function sx(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [null, null];
    }
    $xp = new DOMXPath($dom);
    return [$dom, $xp];
}

function normTarget(string $target): string {
    $target = ltrim($target, '/');
    return str_starts_with($target, 'xl/') ? $target : ('xl/' . $target);
}

function getWorkbookSheetPaths(ZipArchive $zip): array {
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        return [];
    }

    [$d1, $x1] = sx($wb);
    [$d2, $x2] = sx($rels);
    if (!$d1 || !$x1 || !$d2 || !$x2) {
        return [];
    }

    $relById = [];
    foreach ($x2->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $rel) {
        if (!$rel instanceof DOMElement) {
            continue;
        }
        $relById[$rel->getAttribute('Id')] = normTarget($rel->getAttribute('Target'));
    }

    $map = [];
    foreach ($x1->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
        if (!$sheet instanceof DOMElement) {
            continue;
        }
        $name = $sheet->getAttribute('name');
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $path = $relById[$rid] ?? '';
        if ($name !== '' && $path !== '') {
            $map[$name] = $path;
        }
    }

    return $map;
}

function readSharedStrings(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    [$d, $x] = sx($xml);
    if (!$d || !$x) {
        return [];
    }
    $values = [];
    foreach ($x->query('//*[local-name()="si"]') as $si) {
        $txt = '';
        foreach ($x->query('.//*[local-name()="t"]', $si) as $t) {
            $txt .= $t->textContent;
        }
        $values[] = $txt;
    }
    return $values;
}

function cellValue(DOMElement $cell, DOMXPath $xp, array $sharedStrings): string {
    $type = $cell->getAttribute('t');
    if ($type === 'inlineStr') {
        $v = '';
        foreach ($xp->query('.//*[local-name()="t"]', $cell) as $n) {
            $v .= $n->textContent;
        }
        return trim($v);
    }

    $vn = $xp->query('*[local-name()="v"]', $cell);
    if ($vn->length === 0) {
        return '';
    }
    $raw = $vn->item(0)->textContent;
    if ($type === 's') {
        return trim((string)($sharedStrings[(int)$raw] ?? ''));
    }
    return trim((string)$raw);
}

function rowMap(DOMElement $row, DOMXPath $xp, array $sharedStrings): array {
    $vals = [];
    foreach ($xp->query('*[local-name()="c"]', $row) as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        $ref = $cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        $vals[$col] = cellValue($cell, $xp, $sharedStrings);
    }
    return $vals;
}

function canonicalOrgUri(string $uri): string {
    $uri = trim($uri);
    if ($uri === '') {
        return '';
    }
    if (str_starts_with($uri, 'pmsr:')) {
        return PMSR_NS . substr($uri, 5);
    }
    return $uri;
}

function uriLocalId(string $uri): string {
    $uri = trim($uri);
    $pos = strrpos($uri, '/');
    return $pos === false ? $uri : substr($uri, $pos + 1);
}

function setInlineString(DOMElement $cell, string $value): void {
    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }
    $cell->setAttribute('t', 'inlineStr');
    $is = $cell->ownerDocument->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
    $t = $cell->ownerDocument->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
    $t->appendChild($cell->ownerDocument->createTextNode($value));
    $is->appendChild($t);
    $cell->appendChild($is);
}

function appendPersonRow(DOMDocument $dom, DOMXPath $xp, DOMElement $sheetData, int $rowNum, array $headerToCol, array $payload): void {
    $row = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');
    $row->setAttribute('r', (string)$rowNum);

    foreach ($payload as $header => $value) {
        if ($value === '' || !isset($headerToCol[$header])) {
            continue;
        }
        $col = $headerToCol[$header];
        $cell = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
        $cell->setAttribute('r', $col . $rowNum);
        setInlineString($cell, $value);
        $row->appendChild($cell);
    }

    $sheetData->appendChild($row);
}

if (!is_file(KGR_PEOPLE_XLSX) || !is_file(DIGI_XLSX)) {
    fwrite(STDERR, "Required workbook files not found.\n");
    exit(1);
}

// 1) Read Digi4Health organizations (10 contributors).
$zDigi = new ZipArchive();
if ($zDigi->open(DIGI_XLSX) !== true) {
    fwrite(STDERR, "Cannot open " . DIGI_XLSX . "\n");
    exit(2);
}
$ssDigi = readSharedStrings($zDigi);
$pathsDigi = getWorkbookSheetPaths($zDigi);
$projOrgPath = $pathsDigi['ProjectOrganizations'] ?? '';
if ($projOrgPath === '') {
    fwrite(STDERR, "ProjectOrganizations sheet missing in Digi4Health workbook.\n");
    exit(3);
}
$projOrgXml = $zDigi->getFromName($projOrgPath);
[$dpo, $xpo] = sx((string)$projOrgXml);
if (!$dpo || !$xpo) {
    fwrite(STDERR, "Cannot parse Digi4Health ProjectOrganizations sheet.\n");
    exit(4);
}

$projectOrgUris = [];
$rows = $xpo->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
for ($i = 1; $i < $rows->length; $i++) {
    $row = $rows->item($i);
    if (!$row instanceof DOMElement) {
        continue;
    }
    $vals = rowMap($row, $xpo, $ssDigi);
    $orgRaw = trim((string)($vals['B'] ?? ''));
    if ($orgRaw === '') {
        continue;
    }
    $projectOrgUris[canonicalOrgUri($orgRaw)] = true;
}
$zDigi->close();

if (count($projectOrgUris) !== 10) {
    fwrite(STDERR, "Expected 10 Digi4Health organizations, found " . count($projectOrgUris) . "\n");
}

// 2) Open KGR-PEOPLE workbook and gather organization labels/emails.
$zip = new ZipArchive();
if ($zip->open(KGR_PEOPLE_XLSX) !== true) {
    fwrite(STDERR, "Cannot open " . KGR_PEOPLE_XLSX . "\n");
    exit(5);
}
$shared = readSharedStrings($zip);
$paths = getWorkbookSheetPaths($zip);

$orgPath = $paths['Organizations'] ?? '';
$personsPath = $paths['Persons'] ?? '';
if ($orgPath === '' || $personsPath === '') {
    fwrite(STDERR, "Organizations or Persons sheet missing in KGR-PEOPLE workbook.\n");
    exit(6);
}

$orgXml = $zip->getFromName($orgPath);
[$do, $xo] = sx((string)$orgXml);
if (!$do || !$xo) {
    fwrite(STDERR, "Cannot parse Organizations sheet.\n");
    exit(7);
}

$orgInfo = [];
$orgRows = $xo->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
for ($i = 1; $i < $orgRows->length; $i++) {
    $row = $orgRows->item($i);
    if (!$row instanceof DOMElement) {
        continue;
    }
    $vals = rowMap($row, $xo, $shared);
    $uri = canonicalOrgUri((string)($vals['A'] ?? ''));
    if ($uri === '') {
        continue;
    }
    $short = trim((string)($vals['E'] ?? ''));
    $mbox = trim((string)($vals['G'] ?? ''));
    $orgInfo[$uri] = [
        'short' => $short,
        'mbox' => $mbox,
    ];
}

$personsXml = $zip->getFromName($personsPath);
[$dp, $xp] = sx((string)$personsXml);
if (!$dp || !$xp) {
    fwrite(STDERR, "Cannot parse Persons sheet.\n");
    exit(8);
}

$sheetDataList = $xp->query('//*[local-name()="sheetData"]');
$sheetData = $sheetDataList->item(0);
if (!$sheetData instanceof DOMElement) {
    fwrite(STDERR, "Persons sheetData missing.\n");
    exit(9);
}

$personRows = $xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
if ($personRows->length === 0) {
    fwrite(STDERR, "Persons sheet has no rows.\n");
    exit(10);
}

$header = rowMap($personRows->item(0), $xp, $shared);
$headerToCol = [];
foreach ($header as $col => $name) {
    $headerToCol[$name] = $col;
}

$requiredHeaders = [
    'hasURI', 'hasco:hascoType', 'rdfs:label', 'rdf:type', 'foaf:givenName',
    'foaf:familyName', 'foaf:member', 'foaf:mbox', 'rdfs:comment'
];
foreach ($requiredHeaders as $h) {
    if (!isset($headerToCol[$h])) {
        fwrite(STDERR, "Missing required Persons header: $h\n");
        exit(11);
    }
}

$existingByLabel = [];
$existingByEmail = [];
$maxPersonNum = 0;

for ($i = 1; $i < $personRows->length; $i++) {
    $row = $personRows->item($i);
    if (!$row instanceof DOMElement) {
        continue;
    }
    $vals = rowMap($row, $xp, $shared);
    $label = trim((string)($vals[$headerToCol['rdfs:label']] ?? ''));
    $emailVal = trim((string)($vals[$headerToCol['foaf:mbox']] ?? ''));

    if ($label !== '') {
        $existingByLabel[strtolower($label)] = true;
    }
    if ($emailVal !== '') {
        $existingByEmail[strtolower($emailVal)] = true;
    }

    $uriVal = trim((string)($vals[$headerToCol['hasURI']] ?? ''));
    if (preg_match('/Person_(\d+)$/', $uriVal, $m)) {
        $n = (int)$m[1];
        if ($n > $maxPersonNum) {
            $maxPersonNum = $n;
        }
    }
}

$rowsToAdd = [];
foreach (array_keys($projectOrgUris) as $orgUri) {
    $short = trim((string)($orgInfo[$orgUri]['short'] ?? ''));
    if ($short === '') {
        $short = uriLocalId($orgUri);
    }
    $safeShort = preg_replace('/[^A-Za-z0-9]+/', '', $short);
    if ($safeShort === '') {
        $safeShort = 'ORG';
    }

    $label = 'Curator of ' . $short;
    $email = 'curator@' . strtolower($safeShort);
    $mailto = 'mailto:' . $email;

    if (isset($existingByLabel[strtolower($label)]) || isset($existingByEmail[strtolower($mailto)])) {
        continue;
    }

    $maxPersonNum++;
    $personUri = PMSR_NS . 'Person_' . str_pad((string)$maxPersonNum, 3, '0', STR_PAD_LEFT);

    $rowsToAdd[] = [
        'hasURI' => $personUri,
        'hasco:hascoType' => 'schema:Person',
        'rdfs:label' => $label,
        'hasco:originalID' => 'CURATOR_' . strtoupper($safeShort),
        'rdf:type' => 'schema:Person',
        'foaf:givenName' => 'Curator',
        'foaf:familyName' => $short,
        'foaf:member' => $orgUri,
        'foaf:mbox' => $mailto,
        'hasco:userName' => 'curator_' . strtolower($safeShort),
        'hasco:userEmail' => $email,
        // Keep userID empty so user provisioning can auto-generate credentials downstream.
        'rdfs:comment' => $label,
    ];
}

$startRowNum = $personRows->length + 1;
$added = 0;
foreach ($rowsToAdd as $payload) {
    appendPersonRow($dp, $xp, $sheetData, $startRowNum + $added, $headerToCol, $payload);
    $added++;
}

if ($added > 0) {
    $zip->deleteName($personsPath);
    $zip->addFromString($personsPath, (string)$dp->saveXML());
}

$zip->close();

echo 'DIGI4HEALTH_ORGS=' . count($projectOrgUris) . PHP_EOL;
echo 'CURATORS_ADDED=' . $added . PHP_EOL;
echo 'PEOPLE_FILE=' . KGR_PEOPLE_XLSX . PHP_EOL;
