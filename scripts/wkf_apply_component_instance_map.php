<?php

declare(strict_types=1);

/**
 * Apply component-instance URI assignments into WKF Tasks sheet
 * vstoi:usesComponentInstance column based on deployment map JSON.
 *
 * Usage:
 *   php scripts/wkf_apply_component_instance_map.php
 *   php scripts/wkf_apply_component_instance_map.php <map.json>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$defaultMap = __DIR__ . '/../wkf/WKF-INTERACTION-TASK-COMPONENT-DEPLOYMENT-MAP.json';
$mapPath = $argv[1] ?? $defaultMap;

if (!is_file($mapPath)) {
    fwrite(STDERR, "Mapping file not found: {$mapPath}\n");
    exit(1);
}

$mapRaw = file_get_contents($mapPath);
$map = json_decode((string) $mapRaw, true);
if (!is_array($map) || !isset($map['wkfDeploymentMap']) || !is_array($map['wkfDeploymentMap'])) {
    fwrite(STDERR, "Invalid mapping JSON format.\n");
    exit(1);
}

$wkfRoot = realpath(__DIR__ . '/../wkf');
if ($wkfRoot === false) {
    fwrite(STDERR, "WKF folder not found.\n");
    exit(1);
}

$results = [];
$allOk = true;

foreach ($map['wkfDeploymentMap'] as $wkfFile => $wkfEntry) {
    $filePath = $wkfRoot . DIRECTORY_SEPARATOR . $wkfFile;
    if (!is_file($filePath)) {
        $results[] = [
            'file' => $wkfFile,
            'ok' => false,
            'changed' => false,
            'updatedRows' => 0,
            'errors' => ['WKF file missing'],
        ];
        $allOk = false;
        continue;
    }

    $assignments = [];
    $recommended = $wkfEntry['recommendedOrganization'] ?? null;
    if (is_array($recommended) && isset($recommended['sampleAssignments']) && is_array($recommended['sampleAssignments'])) {
        $assignments = $recommended['sampleAssignments'];
    }

    $res = applyAssignmentsToWorkbook($filePath, $assignments);
    $res['file'] = $wkfFile;
    $results[] = $res;
    if (!$res['ok']) {
        $allOk = false;
    }
}

echo json_encode([
    'mappingFile' => $mapPath,
    'ok' => $allOk,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($allOk ? 0 : 2);

function applyAssignmentsToWorkbook(string $xlsxPath, array $assignments): array
{
    $res = [
        'ok' => true,
        'changed' => false,
        'updatedRows' => 0,
        'errors' => [],
    ];

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        $res['ok'] = false;
        $res['errors'][] = 'Unable to open workbook';
        return $res;
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Workbook metadata missing';
        return $res;
    }

    $tasksPath = resolveTasksSheetPath($workbookXml, $relsXml);
    if ($tasksPath === null) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Tasks sheet not found';
        return $res;
    }

    $tasksXml = $zip->getFromName($tasksPath);
    if ($tasksXml === false) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Unable to read Tasks sheet XML';
        return $res;
    }

    [$dom, $xp] = xmlDocAndXPath($tasksXml);
    if ($dom === null || $xp === null) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Tasks XML parse failed';
        return $res;
    }

    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false || $rows->length === 0) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Tasks sheet has no rows';
        return $res;
    }

    $sharedStrings = loadSharedStrings($zip);

    $headerRow = $rows->item(0);
    if (!$headerRow instanceof DOMElement) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Tasks header row missing';
        return $res;
    }

    $usesCol = findHeaderColumn($headerRow, $sharedStrings, 'vstoi:usesComponentInstance');
    if ($usesCol === null) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Tasks header missing vstoi:usesComponentInstance';
        return $res;
    }

    $assignByRow = [];
    foreach ($assignments as $assignment) {
        if (!is_array($assignment)) {
            continue;
        }
        $rowNum = (int)($assignment['taskRow'] ?? 0);
        $componentUri = trim((string)($assignment['componentInstanceUri'] ?? ''));
        if ($rowNum <= 1 || $componentUri === '') {
            continue;
        }
        $assignByRow[$rowNum] = $componentUri;
    }

    if (count($assignByRow) === 0) {
        $zip->close();
        return $res;
    }

    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        if (!$row instanceof DOMElement) {
            continue;
        }

        $rowNumAttr = trim((string)$row->getAttribute('r'));
        $rowNum = $rowNumAttr !== '' ? (int)$rowNumAttr : ($i + 1);
        if (!isset($assignByRow[$rowNum])) {
            continue;
        }

        $targetRef = $usesCol . $rowNum;
        $cell = findCellByRef($row, $targetRef);
        if (!$cell instanceof DOMElement) {
            $cell = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
            $cell->setAttribute('r', $targetRef);
            $row->appendChild($cell);
        }

        setCellInlineString($cell, $assignByRow[$rowNum]);
        $res['updatedRows']++;
    }

    if ($res['updatedRows'] > 0) {
        $zip->deleteName($tasksPath);
        $zip->addFromString($tasksPath, (string)$dom->saveXML());
        $res['changed'] = true;
    }

    $zip->close();
    return $res;
}

function resolveTasksSheetPath(string $workbookXml, string $relsXml): ?string
{
    [$wbDom, $wbXp] = xmlDocAndXPath($workbookXml);
    [$relDom, $relXp] = xmlDocAndXPath($relsXml, true);
    if ($wbDom === null || $wbXp === null || $relDom === null || $relXp === null) {
        return null;
    }

    $relById = [];
    $relNodes = $relXp->query('//r:Relationship');
    if ($relNodes !== false) {
        foreach ($relNodes as $rel) {
            if (!$rel instanceof DOMElement) {
                continue;
            }
            $id = (string)$rel->getAttribute('Id');
            $target = (string)$rel->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $relById[$id] = normalizeWorksheetTarget($target);
            }
        }
    }

    $sheetNodes = $wbXp->query('//x:sheets/x:sheet');
    if ($sheetNodes === false) {
        return null;
    }

    foreach ($sheetNodes as $sheet) {
        if (!$sheet instanceof DOMElement) {
            continue;
        }
        if ((string)$sheet->getAttribute('name') !== 'Tasks') {
            continue;
        }
        $rid = (string)$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        return $relById[$rid] ?? null;
    }

    return null;
}

function xmlDocAndXPath(string $xml, bool $relsNs = false): array
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [null, null];
    }

    $xp = new DOMXPath($dom);
    if ($relsNs) {
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    } else {
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    }

    return [$dom, $xp];
}

function normalizeWorksheetTarget(string $target): string
{
    $target = ltrim($target, '/');
    if (!str_starts_with($target, 'xl/')) {
        $target = 'xl/' . $target;
    }
    return $target;
}

function loadSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false || trim($xml) === '') {
        return [];
    }

    [$dom, $xp] = xmlDocAndXPath($xml);
    if ($dom === null || $xp === null) {
        return [];
    }

    $values = [];
    $siNodes = $xp->query('//x:si');
    if ($siNodes === false) {
        return [];
    }

    foreach ($siNodes as $si) {
        $text = '';
        foreach ($xp->query('.//x:t', $si) as $t) {
            $text .= $t->textContent;
        }
        $values[] = $text;
    }

    return $values;
}

function readCellValue(DOMElement $cell, array $sharedStrings): string
{
    $type = (string)$cell->getAttribute('t');
    if ($type === 'inlineStr') {
        $text = '';
        foreach ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't') as $t) {
            $text .= $t->textContent;
        }
        return trim($text);
    }

    $vNode = null;
    foreach ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v') as $v) {
        $vNode = $v;
        break;
    }

    if ($vNode === null) {
        return '';
    }

    $raw = trim($vNode->textContent);
    if ($type === 's') {
        $idx = (int)$raw;
        return trim((string)($sharedStrings[$idx] ?? ''));
    }

    return $raw;
}

function findHeaderColumn(DOMElement $headerRow, array $sharedStrings, string $headerName): ?string
{
    foreach ($headerRow->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        $value = readCellValue($cell, $sharedStrings);
        if ($value !== $headerName) {
            continue;
        }
        $ref = (string)$cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        return $col !== '' ? $col : null;
    }
    return null;
}

function findCellByRef(DOMElement $row, string $targetRef): ?DOMElement
{
    foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        if ((string)$cell->getAttribute('r') === $targetRef) {
            return $cell;
        }
    }
    return null;
}

function setCellInlineString(DOMElement $cell, string $value): void
{
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
