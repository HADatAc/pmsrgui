<?php

declare(strict_types=1);

/**
 * WKF-SPEC-V3 validator for WKF .xlsx files.
 *
 * Usage:
 *   php scripts/wkf_validate_v3.php wkf/WKF-FOO.xlsx
 *   php scripts/wkf_validate_v3.php wkf/*.xlsx
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$files = array_slice($argv, 1);
if (empty($files)) {
    fwrite(STDERR, "Usage: php scripts/wkf_validate_v3.php <wkf1.xlsx> [wkf2.xlsx ...]\n");
    exit(1);
}

$allResults = [];
$overallOk = true;
foreach ($files as $file) {
    $allResults[] = validateWorkbook($file);
    if (!$allResults[count($allResults) - 1]['ok']) {
        $overallOk = false;
    }
}

echo json_encode([
    'spec' => 'WKF-SPEC-V3',
    'overallOk' => $overallOk,
    'results' => $allResults,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($overallOk ? 0 : 2);

function validateWorkbook(string $file): array
{
    $result = [
        'file' => $file,
        'ok' => true,
        'errors' => [],
        'warnings' => [],
    ];

    if (!is_file($file)) {
        $result['ok'] = false;
        $result['errors'][] = 'File not found';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        $result['ok'] = false;
        $result['errors'][] = 'Unable to open workbook';
        return $result;
    }

    $workbookXml = zipReadRequired($zip, 'xl/workbook.xml');
    $relsXml = zipReadRequired($zip, 'xl/_rels/workbook.xml.rels');
    if ($workbookXml === null || $relsXml === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Missing workbook metadata XML';
        $zip->close();
        return $result;
    }

    [$sheetOrder, $sheetTargets, $fatal] = resolveSheetTargets($workbookXml, $relsXml);
    if ($fatal !== null) {
        $result['ok'] = false;
        $result['errors'][] = $fatal;
        $zip->close();
        return $result;
    }

    $expectedOrder = ['InfoSheet', 'Namespaces', 'STD', 'ProcessStems', 'Processes', 'Tasks'];
    if ($sheetOrder !== $expectedOrder) {
        $result['ok'] = false;
        $result['errors'][] = 'Sheet order mismatch. Expected: ' . implode(' > ', $expectedOrder)
            . ' | Found: ' . implode(' > ', $sheetOrder);
    }

    if (in_array('RequiredInstruments', $sheetOrder, true)) {
        $result['ok'] = false;
        $result['errors'][] = 'Deprecated sheet RequiredInstruments is still present';
    }

    $sharedStrings = loadSharedStrings($zip);

    // Validate InfoSheet.
    $infoTarget = $sheetTargets['InfoSheet'] ?? null;
    if ($infoTarget === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Missing InfoSheet sheet';
    } else {
        $infoXml = zipReadRequired($zip, $infoTarget);
        if ($infoXml === null) {
            $result['ok'] = false;
            $result['errors'][] = 'Unable to read InfoSheet XML';
        } else {
            validateInfoSheet($infoXml, $sharedStrings, $result);
        }
    }

    // Validate Tasks header + V3 usesComponentInstance semantics.
    $tasksTarget = $sheetTargets['Tasks'] ?? null;
    if ($tasksTarget === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Missing Tasks sheet';
    } else {
        $tasksXml = zipReadRequired($zip, $tasksTarget);
        if ($tasksXml === null) {
            $result['ok'] = false;
            $result['errors'][] = 'Unable to read Tasks XML';
        } else {
            validateTasksSheetV3($tasksXml, $sharedStrings, $result);
        }
    }

    $zip->close();

    return $result;
}

function validateInfoSheet(string $sheetXml, array $sharedStrings, array &$result): void
{
    [$dom, $xp] = xmlDocAndXPath($sheetXml);
    if ($dom === null || $xp === null) {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet XML parse failed';
        return;
    }

    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false || $rows->length < 2) {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet must contain header and data rows';
        return;
    }

    $header = rowToCells($rows->item(0), $sharedStrings);
    if (($header[0] ?? '') !== 'Attribute' || ($header[1] ?? '') !== 'Value') {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet header must be exactly Attribute | Value';
    }

    $pairs = [];
    for ($i = 1; $i < $rows->length; $i++) {
        $cells = rowToCells($rows->item($i), $sharedStrings);
        $key = trim((string)($cells[0] ?? ''));
        $value = trim((string)($cells[1] ?? ''));
        if ($key === '') {
            continue;
        }
        $pairs[$key] = $value;
    }

    $expected = [
        'hasDependencies' => '#Namespaces',
        'hasStudyDescription' => '#STD',
        'ProcessStems' => '#ProcessStems',
        'Processes' => '#Processes',
        'Tasks' => '#Tasks',
    ];

    if (count($pairs) !== 6) {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet must contain exactly 6 data rows (found ' . count($pairs) . ')';
    }

    foreach ($expected as $k => $v) {
        if (!array_key_exists($k, $pairs)) {
            $result['ok'] = false;
            $result['errors'][] = 'InfoSheet missing key: ' . $k;
            continue;
        }
        if ($pairs[$k] !== $v) {
            $result['ok'] = false;
            $result['errors'][] = 'InfoSheet key ' . $k . ' must map to ' . $v . ' (found ' . $pairs[$k] . ')';
        }
    }

    if (array_key_exists('RequiredInstruments', $pairs)) {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet contains deprecated key RequiredInstruments';
    }

    $version = trim((string)($pairs['hasVersion'] ?? ''));
    if ($version === '' || !preg_match('/^\d+(\.\d+)*$/', $version)) {
        $result['ok'] = false;
        $result['errors'][] = 'InfoSheet hasVersion must match ^\\d+(\\.\\d+)*$';
    }
}

function validateTasksSheetV3(string $sheetXml, array $sharedStrings, array &$result): void
{
    [$dom, $xp] = xmlDocAndXPath($sheetXml);
    if ($dom === null || $xp === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Tasks XML parse failed';
        return;
    }

    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false || $rows->length < 1) {
        $result['ok'] = false;
        $result['errors'][] = 'Tasks sheet missing header row';
        return;
    }

    $headerCells = rowToAssociativeCells($rows->item(0), $sharedStrings);
    $headerMap = [];
    foreach ($headerCells as $col => $value) {
        $headerMap[$value] = $col;
    }

    if (!isset($headerMap['vstoi:usesComponentInstance'])) {
        $result['ok'] = false;
        $result['errors'][] = 'Tasks header missing vstoi:usesComponentInstance';
        return;
    }

    if (isset($headerMap['vstoi:hasRequiredInstrument'])) {
        $result['ok'] = false;
        $result['errors'][] = 'Tasks header still contains deprecated vstoi:hasRequiredInstrument';
    }

    $colType = $headerMap['rdf:type'] ?? null;
    $colTaskUri = $headerMap['hasURI'] ?? null;
    $colUses = $headerMap['vstoi:usesComponentInstance'];

    if ($colType === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Tasks header missing rdf:type';
        return;
    }

    for ($i = 1; $i < $rows->length; $i++) {
        $cells = rowToAssociativeCells($rows->item($i), $sharedStrings);
        $taskUri = trim((string)($cells[$colTaskUri] ?? ''));
        $type = trim((string)($cells[$colType] ?? ''));
        $uses = trim((string)($cells[$colUses] ?? ''));

        if ($uses === '') {
            continue;
        }

        if (!isAutomatedOrInteractionType($type)) {
            $result['ok'] = false;
            $result['errors'][] = 'Task ' . ($taskUri !== '' ? $taskUri : ('row ' . ($i + 1)))
                . ' has usesComponentInstance but rdf:type is not vstoi:AutomatedTask or vstoi:InteractionTask';
        }

        $parts = preg_split('/\s*[;|]\s*/', $uses);
        if (!is_array($parts)) {
            $parts = [$uses];
        }

        foreach ($parts as $part) {
            $v = trim((string)$part);
            if ($v === '') {
                continue;
            }
            if (!preg_match('/^https?:\/\//i', $v)) {
                $result['ok'] = false;
                $result['errors'][] = 'Task ' . ($taskUri !== '' ? $taskUri : ('row ' . ($i + 1)))
                    . ' has non-URI usesComponentInstance value: ' . $v;
            }
        }
    }
}

function isAutomatedOrInteractionType(string $type): bool
{
    $t = trim($type);
    if ($t === '') {
        return false;
    }

    if ($t === 'vstoi:AutomatedTask' || $t === 'vstoi:InteractionTask') {
        return true;
    }

    $norm = strtolower($t);
    return str_ends_with($norm, '#automatedtask') || str_ends_with($norm, '#interactiontask');
}

function resolveSheetTargets(string $workbookXml, string $relsXml): array
{
    [$wbDom, $wbXp] = xmlDocAndXPath($workbookXml);
    [$relDom, $relXp] = xmlDocAndXPath($relsXml, true);
    if ($wbDom === null || $wbXp === null || $relDom === null || $relXp === null) {
        return [[], [], 'Failed to parse workbook metadata XML'];
    }

    $relById = [];
    $relNodes = $relXp->query('//r:Relationship');
    if ($relNodes !== false) {
        foreach ($relNodes as $rel) {
            $id = (string)$rel->getAttribute('Id');
            $target = (string)$rel->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $relById[$id] = normalizeWorksheetTarget($target);
            }
        }
    }

    $sheetOrder = [];
    $sheetTargets = [];
    $sheetNodes = $wbXp->query('//x:sheets/x:sheet');
    if ($sheetNodes === false) {
        return [[], [], 'Workbook contains no sheets'];
    }

    foreach ($sheetNodes as $sheet) {
        $name = (string)$sheet->getAttribute('name');
        $rid = (string)$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($name === '' || $rid === '') {
            continue;
        }
        $sheetOrder[] = $name;
        if (isset($relById[$rid])) {
            $sheetTargets[$name] = $relById[$rid];
        }
    }

    return [$sheetOrder, $sheetTargets, null];
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
    if ($xml === false || $xml === null || trim($xml) === '') {
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
        $tNodes = $xp->query('.//x:t', $si);
        if ($tNodes !== false) {
            foreach ($tNodes as $t) {
                $text .= $t->textContent;
            }
        }
        $values[] = $text;
    }

    return $values;
}

function rowToCells(?DOMNode $row, array $sharedStrings): array
{
    $map = rowToAssociativeCells($row, $sharedStrings);
    if (empty($map)) {
        return [];
    }

    $out = [];
    foreach ($map as $col => $val) {
        $idx = colLettersToIndex($col);
        $out[$idx] = $val;
    }
    ksort($out);

    $flat = [];
    foreach ($out as $idx => $val) {
        $flat[$idx] = $val;
    }

    return $flat;
}

function rowToAssociativeCells(?DOMNode $row, array $sharedStrings): array
{
    $cells = [];
    if (!$row instanceof DOMElement) {
        return $cells;
    }

    foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }

        $ref = (string)$cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        if ($col === null || $col === '') {
            continue;
        }

        $cells[$col] = readCellValue($cell, $sharedStrings);
    }

    return $cells;
}

function readCellValue(DOMElement $cell, array $sharedStrings): string
{
    $type = (string)$cell->getAttribute('t');

    if ($type === 'inlineStr') {
        $parts = [];
        foreach ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't') as $tn) {
            $parts[] = $tn->textContent;
        }
        return implode('', $parts);
    }

    $vNodes = $cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');
    if ($vNodes->length === 0) {
        return '';
    }

    $raw = (string)$vNodes->item(0)->textContent;
    if ($type === 's') {
        $idx = (int)$raw;
        return $sharedStrings[$idx] ?? '';
    }

    return $raw;
}

function colLettersToIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $sum = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $sum = ($sum * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $sum - 1);
}

function zipReadRequired(ZipArchive $zip, string $name): ?string
{
    $xml = $zip->getFromName($name);
    if ($xml === false || $xml === null || trim($xml) === '') {
        return null;
    }
    return $xml;
}

function xmlDocAndXPath(string $xml, bool $relsNs = false): array
{
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xml);
    if (!$ok) {
        return [null, null];
    }

    $xp = new DOMXPath($dom);
    if ($relsNs) {
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    } else {
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xp->registerNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    }

    return [$dom, $xp];
}
