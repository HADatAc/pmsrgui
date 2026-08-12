<?php

declare(strict_types=1);

function loadXmlAndXpath(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [null, null];
    }
    $xp = new DOMXPath($dom);
    return [$dom, $xp];
}

function normalizeTarget(string $target): string {
    $target = ltrim($target, '/');
    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
}

function cellValue(DOMElement $cell, DOMXPath $xp, array $ss): string {
    $type = $cell->getAttribute('t');
    if ($type === 'inlineStr') {
        $v = '';
        foreach ($xp->query('.//*[local-name()="t"]', $cell) as $n) {
            $v .= $n->textContent;
        }
        return trim($v);
    }

    $vNode = $xp->query('*[local-name()="v"]', $cell);
    if ($vNode->length === 0) {
        return '';
    }
    $raw = $vNode->item(0)->textContent;
    if ($type === 's') {
        return trim($ss[(int) $raw] ?? '');
    }
    return trim($raw);
}

function sharedStrings(ZipArchive $z): array {
    $xml = $z->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    [$d, $xp] = loadXmlAndXpath($xml);
    if (!$d || !$xp) {
        return [];
    }
    $ss = [];
    foreach ($xp->query('//*[local-name()="si"]') as $si) {
        $t = '';
        foreach ($xp->query('.//*[local-name()="t"]', $si) as $tn) {
            $t .= $tn->textContent;
        }
        $ss[] = $t;
    }
    return $ss;
}

function tasksSheetPath(ZipArchive $z): ?string {
    $wb = $z->getFromName('xl/workbook.xml');
    $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        return null;
    }
    [$d1, $x1] = loadXmlAndXpath($wb);
    [$d2, $x2] = loadXmlAndXpath($rels);
    if (!$d1 || !$x1 || !$d2 || !$x2) {
        return null;
    }

    $relById = [];
    foreach ($x2->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $rel) {
        $relById[$rel->getAttribute('Id')] = normalizeTarget($rel->getAttribute('Target'));
    }

    foreach ($x1->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
        if ($sheet->getAttribute('name') !== 'Tasks') {
            continue;
        }
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        return $relById[$rid] ?? null;
    }

    return null;
}

$files = glob(__DIR__ . '/../wkf/*.xlsx');
sort($files);

foreach ($files as $file) {
    $z = new ZipArchive();
    if ($z->open($file) !== true) {
        continue;
    }

    $path = tasksSheetPath($z);
    if ($path === null) {
        $z->close();
        continue;
    }

    $ss = sharedStrings($z);
    $xml = $z->getFromName($path);
    if ($xml === false) {
        $z->close();
        continue;
    }
    [$d, $xp] = loadXmlAndXpath($xml);
    if (!$d || !$xp) {
        $z->close();
        continue;
    }

    $rows = $xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
    if ($rows->length < 2) {
        $z->close();
        continue;
    }

    $headerMap = [];
    foreach ($xp->query('*[local-name()="c"]', $rows->item(0)) as $cell) {
        $ref = $cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        $headerMap[$col] = cellValue($cell, $xp, $ss);
    }

    $needed = ['hasco:hasURI', 'rdfs:label', 'rdf:type', 'vstoi:usesComponentInstance', 'vstoi:usesInstrument', 'vstoi:usesInstrumentInstance', 'vstoi:usesComponent'];
    $colForHeader = array_flip($headerMap);

    echo 'FILE=' . basename($file) . PHP_EOL;
    foreach ($needed as $h) {
        $col = $colForHeader[$h] ?? '-';
        echo 'HEADER_COL ' . $h . '=' . $col . PHP_EOL;
    }

    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        $vals = [];
        foreach ($needed as $h) {
            $col = $colForHeader[$h] ?? null;
            if ($col === null) {
                $vals[$h] = '';
                continue;
            }
            $target = $col . (string)($i + 1);
            $vals[$h] = '';
            foreach ($xp->query('*[local-name()="c"]', $row) as $cell) {
                if ($cell->getAttribute('r') === $target) {
                    $vals[$h] = cellValue($cell, $xp, $ss);
                    break;
                }
            }
        }

        if ($vals['rdf:type'] === 'vstoi:InteractionTask' || str_ends_with(strtolower($vals['rdf:type']), '#interactiontask')) {
            echo 'TASK=' . $vals['hasco:hasURI'] . ' | LABEL=' . $vals['rdfs:label'] . ' | TYPE=' . $vals['rdf:type'] . ' | usesComponent=' . $vals['vstoi:usesComponent'] . ' | usesInstrument=' . $vals['vstoi:usesInstrument'] . ' | usesInstrumentInstance=' . $vals['vstoi:usesInstrumentInstance'] . ' | usesComponentInstance=' . $vals['vstoi:usesComponentInstance'] . PHP_EOL;
        }
    }
    echo PHP_EOL;

    $z->close();
}
