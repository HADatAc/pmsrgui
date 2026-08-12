<?php

declare(strict_types=1);

/**
 * Bulk migrate WKF workbooks to WKF-SPEC-V3.
 *
 * Usage:
 *   php scripts/wkf_migrate_to_v3.php wkf/WKF-FOO.xlsx
 *   php scripts/wkf_migrate_to_v3.php wkf/*.xlsx
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI.\n");
    exit(1);
}

$files = array_slice($argv, 1);
if (empty($files)) {
    fwrite(STDERR, "Usage: php scripts/wkf_migrate_to_v3.php <wkf1.xlsx> [wkf2.xlsx ...]\n");
    exit(1);
}

$allOk = true;
foreach ($files as $file) {
    $res = migrateWorkbook($file);
    echo json_encode($res, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!$res['ok']) {
        $allOk = false;
    }
}

exit($allOk ? 0 : 2);

function migrateWorkbook(string $file): array
{
    $res = [
        'file' => $file,
        'ok' => true,
        'changed' => false,
        'notes' => [],
        'errors' => [],
    ];

    if (!is_file($file)) {
        $res['ok'] = false;
        $res['errors'][] = 'File not found';
        return $res;
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        $res['ok'] = false;
        $res['errors'][] = 'Unable to open workbook zip';
        return $res;
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $contentTypesXml = $zip->getFromName('[Content_Types].xml');
    if ($workbookXml === false || $relsXml === false || $contentTypesXml === false) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = 'Workbook metadata missing';
        return $res;
    }

    $sharedStrings = loadSharedStrings($zip);

    [$sheetOrder, $sheetTargets, $sheetRidByName, $fatal] = resolveSheetTargets($workbookXml, $relsXml);
    if ($fatal !== null) {
        $zip->close();
        $res['ok'] = false;
        $res['errors'][] = $fatal;
        return $res;
    }

    // 1) Update Tasks header + clear deprecated values.
    if (isset($sheetTargets['Tasks'])) {
        $tasksPath = $sheetTargets['Tasks'];
        $tasksXml = $zip->getFromName($tasksPath);
        if ($tasksXml !== false) {
            $updated = migrateTasksSheet($tasksXml, $sharedStrings, $tasksNote);
            if ($updated !== null) {
                $zip->deleteName($tasksPath);
                $zip->addFromString($tasksPath, $updated);
                $res['changed'] = true;
                $res['notes'][] = $tasksNote;
            }
        }
    } else {
        $res['errors'][] = 'Tasks sheet not found';
        $res['ok'] = false;
    }

    // 2) Update InfoSheet key set.
    if (isset($sheetTargets['InfoSheet'])) {
        $infoPath = $sheetTargets['InfoSheet'];
        $infoXml = $zip->getFromName($infoPath);
        if ($infoXml !== false) {
            $updated = migrateInfoSheet($infoXml, $sharedStrings, $infoNote);
            if ($updated !== null) {
                $zip->deleteName($infoPath);
                $zip->addFromString($infoPath, $updated);
                $res['changed'] = true;
                $res['notes'][] = $infoNote;
            }
        }
    } else {
        $res['errors'][] = 'InfoSheet not found';
        $res['ok'] = false;
    }

    // 3) Remove deprecated RequiredInstruments sheet + references.
    if (isset($sheetTargets['RequiredInstruments'])) {
        $removed = removeDeprecatedRequiredInstruments(
            $workbookXml,
            $relsXml,
            $contentTypesXml,
            $sheetTargets['RequiredInstruments'],
            $sheetRidByName['RequiredInstruments'] ?? '',
            $workbookUpdated,
            $relsUpdated,
            $typesUpdated,
            $removeNote
        );

        if ($removed) {
            $zip->deleteName('xl/workbook.xml');
            $zip->addFromString('xl/workbook.xml', $workbookUpdated);

            $zip->deleteName('xl/_rels/workbook.xml.rels');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relsUpdated);

            $zip->deleteName('[Content_Types].xml');
            $zip->addFromString('[Content_Types].xml', $typesUpdated);

            // Remove worksheet + local relationships (if any).
            $riPath = $sheetTargets['RequiredInstruments'];
            $zip->deleteName($riPath);
            $relsPath = str_replace('xl/worksheets/', 'xl/worksheets/_rels/', $riPath);
            $relsPath = preg_replace('/\.xml$/', '.xml.rels', (string)$relsPath);
            if (is_string($relsPath) && $relsPath !== '') {
                $zip->deleteName($relsPath);
            }

            $res['changed'] = true;
            $res['notes'][] = $removeNote;
        }
    } else {
        $res['notes'][] = 'RequiredInstruments sheet already absent';
    }

    $zip->close();

    if (!empty($res['errors'])) {
        $res['ok'] = false;
    }

    return $res;
}

function migrateTasksSheet(string $xml, array $sharedStrings, ?string &$note): ?string
{
    [$dom, $xp] = xmlDocAndXPath($xml);
    if ($dom === null || $xp === null) {
        $note = 'Tasks sheet parse failed';
        return null;
    }

    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false || $rows->length === 0) {
        $note = 'Tasks sheet has no rows';
        return null;
    }

    $header = $rows->item(0);
    if (!$header instanceof DOMElement) {
        $note = 'Tasks header row missing';
        return null;
    }

    $targetCol = 'P'; // 16th column in WKF tasks.
    $headerUpdated = false;
    $clearedCells = 0;

    foreach ($header->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
        if (!$cell instanceof DOMElement) {
            continue;
        }
        $ref = (string)$cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        if ($col === $targetCol) {
            setCellInlineString($cell, 'vstoi:usesComponentInstance');
            $headerUpdated = true;
            break;
        }

        $value = readCellValue($cell, $sharedStrings);
        if ($value === 'vstoi:hasRequiredInstrument') {
            setCellInlineString($cell, 'vstoi:usesComponentInstance');
            $targetCol = (string)$col;
            $headerUpdated = true;
            break;
        }
    }

    if (!$headerUpdated) {
        $newCell = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
        $newCell->setAttribute('r', $targetCol . '1');
        setCellInlineString($newCell, 'vstoi:usesComponentInstance');
        $header->appendChild($newCell);
        $headerUpdated = true;
    }

    // Clear all data rows in usesComponentInstance column to avoid carrying legacy RIN URIs.
    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        if (!$row instanceof DOMElement) {
            continue;
        }

        $rowNum = (string)$row->getAttribute('r');
        if ($rowNum === '') {
            $rowNum = (string)($i + 1);
        }

        $cellFound = null;
        foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }
            $ref = (string)$cell->getAttribute('r');
            if (str_starts_with($ref, $targetCol)) {
                $cellFound = $cell;
                break;
            }
        }

        if ($cellFound instanceof DOMElement) {
            if (trim(readCellValue($cellFound, $sharedStrings)) !== '') {
                clearCellValue($cellFound);
                $clearedCells++;
            }
        }
    }

    $note = 'Tasks migrated: header->vstoi:usesComponentInstance, cleared ' . $clearedCells . ' legacy values';
    return $dom->saveXML();
}

function migrateInfoSheet(string $xml, array $sharedStrings, ?string &$note): ?string
{
    [$dom, $xp] = xmlDocAndXPath($xml);
    if ($dom === null || $xp === null) {
        $note = 'InfoSheet parse failed';
        return null;
    }

    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false || $rows->length === 0) {
        $note = 'InfoSheet has no rows';
        return null;
    }

    $removed = 0;
    foreach ($rows as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }
        $cells = rowToAssociativeCells($row, $sharedStrings);
        $firstCol = trim((string)($cells['A'] ?? ''));
        if ($firstCol === 'RequiredInstruments') {
            if ($row->parentNode !== null) {
                $row->parentNode->removeChild($row);
                $removed++;
            }
        }
    }

    renumberSheetRows($dom);
    $note = 'InfoSheet migrated: removed RequiredInstruments rows=' . $removed;
    return $dom->saveXML();
}

function removeDeprecatedRequiredInstruments(
    string $workbookXml,
    string $relsXml,
    string $typesXml,
    string $riPath,
    string $riRid,
    ?string &$workbookOut,
    ?string &$relsOut,
    ?string &$typesOut,
    ?string &$note
): bool {
    [$wbDom, $wbXp] = xmlDocAndXPath($workbookXml);
    [$relDom, $relXp] = xmlDocAndXPath($relsXml, true);
    [$typesDom, $typesXp] = xmlDocAndXPath($typesXml, true);
    if ($wbDom === null || $wbXp === null || $relDom === null || $relXp === null || $typesDom === null || $typesXp === null) {
        $note = 'Failed to parse workbook metadata while removing RequiredInstruments';
        return false;
    }

    // Remove sheet node by name.
    $removedSheet = false;
    $sheetNodes = $wbXp->query('//x:sheets/x:sheet');
    if ($sheetNodes !== false) {
        foreach ($sheetNodes as $sheet) {
            if (!$sheet instanceof DOMElement) {
                continue;
            }
            if ((string)$sheet->getAttribute('name') === 'RequiredInstruments') {
                if ($sheet->parentNode !== null) {
                    $sheet->parentNode->removeChild($sheet);
                    $removedSheet = true;
                }
                break;
            }
        }
    }

    // Remove relationship by id or target.
    $removedRel = false;
    $relNodes = $relXp->query('//r:Relationship');
    if ($relNodes !== false) {
        foreach ($relNodes as $rel) {
            if (!$rel instanceof DOMElement) {
                continue;
            }
            $id = (string)$rel->getAttribute('Id');
            $target = normalizeWorksheetTarget((string)$rel->getAttribute('Target'));
            if (($riRid !== '' && $id === $riRid) || $target === $riPath) {
                if ($rel->parentNode !== null) {
                    $rel->parentNode->removeChild($rel);
                    $removedRel = true;
                }
                break;
            }
        }
    }

    // Remove content type override for removed sheet.
    $removedOverride = false;
    $overrideNodes = $typesXp->query('/*[local-name()="Types"]/*[local-name()="Override"]');
    if ($overrideNodes !== false) {
        foreach ($overrideNodes as $ov) {
            if (!$ov instanceof DOMElement) {
                continue;
            }
            $partName = (string)$ov->getAttribute('PartName');
            $normalized = ltrim($partName, '/');
            if ($normalized === $riPath) {
                if ($ov->parentNode !== null) {
                    $ov->parentNode->removeChild($ov);
                    $removedOverride = true;
                }
                break;
            }
        }
    }

    $workbookOut = $wbDom->saveXML();
    $relsOut = $relDom->saveXML();
    $typesOut = $typesDom->saveXML();
    $note = 'Removed RequiredInstruments sheet metadata: sheet=' . ($removedSheet ? '1' : '0')
        . ', rel=' . ($removedRel ? '1' : '0')
        . ', contentType=' . ($removedOverride ? '1' : '0');

    return $removedSheet || $removedRel || $removedOverride;
}

function renumberSheetRows(DOMDocument $dom): void
{
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = $xp->query('//x:sheetData/x:row');
    if ($rows === false) {
        return;
    }

    $idx = 1;
    foreach ($rows as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }
        $row->setAttribute('r', (string)$idx);

        foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }
            $ref = (string)$cell->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            if ($col === null || $col === '') {
                continue;
            }
            $cell->setAttribute('r', $col . $idx);
        }
        $idx++;
    }
}

function setCellInlineString(DOMElement $cell, string $value): void
{
    clearCellValue($cell);
    $cell->setAttribute('t', 'inlineStr');

    $doc = $cell->ownerDocument;
    if (!$doc instanceof DOMDocument) {
        return;
    }

    $is = $doc->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
    $t = $doc->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
    $t->appendChild($doc->createTextNode($value));
    $is->appendChild($t);
    $cell->appendChild($is);
}

function clearCellValue(DOMElement $cell): void
{
    while ($cell->firstChild !== null) {
        $cell->removeChild($cell->firstChild);
    }
    $cell->removeAttribute('t');
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

function resolveSheetTargets(string $workbookXml, string $relsXml): array
{
    [$wbDom, $wbXp] = xmlDocAndXPath($workbookXml);
    [$relDom, $relXp] = xmlDocAndXPath($relsXml, true);
    if ($wbDom === null || $wbXp === null || $relDom === null || $relXp === null) {
        return [[], [], [], 'Failed to parse workbook metadata XML'];
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
    $sheetRidByName = [];
    $sheetNodes = $wbXp->query('//x:sheets/x:sheet');
    if ($sheetNodes === false) {
        return [[], [], [], 'Workbook contains no sheets'];
    }

    foreach ($sheetNodes as $sheet) {
        if (!$sheet instanceof DOMElement) {
            continue;
        }
        $name = (string)$sheet->getAttribute('name');
        $rid = (string)$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($name === '' || $rid === '') {
            continue;
        }
        $sheetOrder[] = $name;
        $sheetRidByName[$name] = $rid;
        if (isset($relById[$rid])) {
            $sheetTargets[$name] = $relById[$rid];
        }
    }

    return [$sheetOrder, $sheetTargets, $sheetRidByName, null];
}

function normalizeWorksheetTarget(string $target): string
{
    $target = ltrim($target, '/');
    if (!str_starts_with($target, 'xl/')) {
        $target = 'xl/' . $target;
    }
    return $target;
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
