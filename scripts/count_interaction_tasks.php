<?php

declare(strict_types=1);

/**
 * Count tasks with rdf:type == vstoi:InteractionTask across wkf/*.xlsx
 */

function loadXmlAndXpath(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [null, null];
    }
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return [$dom, $xpath];
}

function normalizeWorkbookTarget(string $target): string {
    $target = ltrim($target, '/');
    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
}

function cellValue(DOMElement $cell, DOMXPath $xpath, array $sharedStrings): string {
    $type = $cell->getAttribute('t');
    $value = '';

    if ($type === 'inlineStr') {
        foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $node) {
            $value .= $node->textContent;
        }
        return trim($value);
    }

    $valueNodes = $xpath->query('*[local-name()="v"]', $cell);
    if ($valueNodes->length === 0) {
        return '';
    }

    $raw = $valueNodes->item(0)->textContent;
    if ($type === 's') {
        return trim($sharedStrings[(int) $raw] ?? '');
    }

    return trim($raw);
}

function loadSharedStrings(ZipArchive $zip): array {
    $sharedStrings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return $sharedStrings;
    }

    [$dom, $xpath] = loadXmlAndXpath($xml);
    if (!$dom || !$xpath) {
        return $sharedStrings;
    }

    foreach ($xpath->query('//*[local-name()="si"]') as $si) {
        $text = '';
        foreach ($xpath->query('.//*[local-name()="t"]', $si) as $tNode) {
            $text .= $tNode->textContent;
        }
        $sharedStrings[] = $text;
    }

    return $sharedStrings;
}

function getTasksSheetPath(ZipArchive $zip): ?string {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        return null;
    }

    [$workbookDom, $workbookXpath] = loadXmlAndXpath($workbookXml);
    [$relsDom, $relsXpath] = loadXmlAndXpath($relsXml);
    if (!$workbookDom || !$workbookXpath || !$relsDom || !$relsXpath) {
        return null;
    }

    $relationshipById = [];
    foreach ($relsXpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $relNode) {
        $id = $relNode->getAttribute('Id');
        $target = $relNode->getAttribute('Target');
        $relationshipById[$id] = normalizeWorkbookTarget($target);
    }

    foreach ($workbookXpath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheetNode) {
        if ($sheetNode->getAttribute('name') !== 'Tasks') {
            continue;
        }
        $relId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        return $relationshipById[$relId] ?? null;
    }

    return null;
}

function getTypeColumnLetter(DOMXPath $xpath, DOMElement $headerRow, array $sharedStrings): ?string {
    foreach ($xpath->query('*[local-name()="c"]', $headerRow) as $cell) {
        $ref = $cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        if (cellValue($cell, $xpath, $sharedStrings) === 'rdf:type') {
            return $col;
        }
    }
    return null;
}

$wkfFiles = glob(__DIR__ . '/../wkf/*.xlsx');
sort($wkfFiles);

$totalInteractionTasks = 0;
$perFile = [];

foreach ($wkfFiles as $filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        continue;
    }

    $tasksPath = getTasksSheetPath($zip);
    if ($tasksPath === null) {
        $zip->close();
        continue;
    }

    $sharedStrings = loadSharedStrings($zip);
    $tasksXml = $zip->getFromName($tasksPath);
    if ($tasksXml === false) {
        $zip->close();
        continue;
    }

    [$tasksDom, $tasksXpath] = loadXmlAndXpath($tasksXml);
    if (!$tasksDom || !$tasksXpath) {
        $zip->close();
        continue;
    }

    $rows = $tasksXpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
    if ($rows->length === 0) {
        $zip->close();
        continue;
    }

    $headerRow = $rows->item(0);
    $typeCol = getTypeColumnLetter($tasksXpath, $headerRow, $sharedStrings);
    if ($typeCol === null || $typeCol === '') {
        $zip->close();
        continue;
    }

    $fileCount = 0;
    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        $targetCellRef = $typeCol . (string) ($i + 1);
        $typeValue = '';

        foreach ($tasksXpath->query('*[local-name()="c"]', $row) as $cell) {
            if ($cell->getAttribute('r') === $targetCellRef) {
                $typeValue = cellValue($cell, $tasksXpath, $sharedStrings);
                break;
            }
        }

        if ($typeValue === 'vstoi:InteractionTask' || str_ends_with(strtolower($typeValue), '#interactiontask')) {
            $fileCount++;
            $totalInteractionTasks++;
        }
    }

    $perFile[basename($filePath)] = $fileCount;
    $zip->close();
}

echo 'INTERACTION_TASKS_TOTAL=' . $totalInteractionTasks . PHP_EOL;
foreach ($perFile as $name => $count) {
    echo $name . '=' . $count . PHP_EOL;
}
