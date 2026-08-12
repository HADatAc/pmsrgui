<?php

declare(strict_types=1);

/**
 * Build WKF deployability map for InteractionTasks using organization-scoped
 * component instances from hascoapi prefilter endpoint.
 */

const API_BASE = 'http://localhost:9001';
const WKF_GLOB = __DIR__ . '/../wkf/*.xlsx';
const OUT_JSON = __DIR__ . '/../wkf/WKF-INTERACTION-TASK-COMPONENT-DEPLOYMENT-MAP.json';
const OUT_MD = __DIR__ . '/../wkf/WKF-INTERACTION-TASK-COMPONENT-DEPLOYMENT-MAP.md';

function httpGetJson(string $url): ?array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Fallback for occasional stream truncation or encoding glitches.
    $cmd = 'curl -s --max-time 120 ' . escapeshellarg($url);
    $curlBody = shell_exec($cmd);
    if (!is_string($curlBody) || trim($curlBody) === '') {
        return null;
    }

    $decoded = json_decode($curlBody, true);
    return is_array($decoded) ? $decoded : null;
}

function loadXmlAndXpath(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [null, null];
    }
    $xpath = new DOMXPath($dom);
    return [$dom, $xpath];
}

function normalizeWorkbookTarget(string $target): string {
    $target = ltrim($target, '/');
    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
}

function cellValue(DOMElement $cell, DOMXPath $xpath, array $sharedStrings): string {
    $type = $cell->getAttribute('t');

    if ($type === 'inlineStr') {
        $inline = '';
        foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $textNode) {
            $inline .= $textNode->textContent;
        }
        return trim($inline);
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
    $strings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return $strings;
    }

    [$dom, $xpath] = loadXmlAndXpath($xml);
    if (!$dom || !$xpath) {
        return $strings;
    }

    foreach ($xpath->query('//*[local-name()="si"]') as $si) {
        $text = '';
        foreach ($xpath->query('.//*[local-name()="t"]', $si) as $tNode) {
            $text .= $tNode->textContent;
        }
        $strings[] = $text;
    }

    return $strings;
}

function getTasksSheetPath(ZipArchive $zip): ?string {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        return null;
    }

    [$wbDom, $wbXpath] = loadXmlAndXpath($workbookXml);
    [$relDom, $relXpath] = loadXmlAndXpath($relsXml);
    if (!$wbDom || !$wbXpath || !$relDom || !$relXpath) {
        return null;
    }

    $relById = [];
    foreach ($relXpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $relNode) {
        $relById[$relNode->getAttribute('Id')] = normalizeWorkbookTarget($relNode->getAttribute('Target'));
    }

    foreach ($wbXpath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
        if ($sheet->getAttribute('name') !== 'Tasks') {
            continue;
        }
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        return $relById[$rid] ?? null;
    }

    return null;
}

function parseTaskRows(string $xlsxPath): array {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        return [];
    }

    $tasksPath = getTasksSheetPath($zip);
    if ($tasksPath === null) {
        $zip->close();
        return [];
    }

    $sharedStrings = loadSharedStrings($zip);
    $tasksXml = $zip->getFromName($tasksPath);
    if ($tasksXml === false) {
        $zip->close();
        return [];
    }

    [$dom, $xpath] = loadXmlAndXpath($tasksXml);
    if (!$dom || !$xpath) {
        $zip->close();
        return [];
    }

    $rows = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
    if ($rows->length <= 1) {
        $zip->close();
        return [];
    }

    $headersByCol = [];
    foreach ($xpath->query('*[local-name()="c"]', $rows->item(0)) as $cell) {
        $ref = $cell->getAttribute('r');
        $col = preg_replace('/\d+/', '', $ref);
        $headersByCol[$col] = cellValue($cell, $xpath, $sharedStrings);
    }

    $headerToCol = [];
    foreach ($headersByCol as $col => $header) {
        $headerToCol[$header] = $col;
    }

    $typeCol = $headerToCol['rdf:type'] ?? null;
    $labelCol = $headerToCol['rdfs:label'] ?? null;
    $uriCol = $headerToCol['hasco:hasURI'] ?? ($headerToCol['vstoi:hasTask'] ?? null);

    if ($typeCol === null || $labelCol === null) {
        $zip->close();
        return [];
    }

    $result = [];
    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        $rowNum = $i + 1;
        $rowValues = [];

        foreach ($xpath->query('*[local-name()="c"]', $row) as $cell) {
            $ref = $cell->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            $rowValues[$col] = cellValue($cell, $xpath, $sharedStrings);
        }

        $taskType = trim((string)($rowValues[$typeCol] ?? ''));
        if ($taskType !== 'vstoi:InteractionTask' && !str_ends_with(strtolower($taskType), '#interactiontask')) {
            continue;
        }

        $label = trim((string)($rowValues[$labelCol] ?? ''));
        if ($label === '') {
            $label = 'Interaction Task row ' . $rowNum;
        }

        $taskUri = '';
        if ($uriCol !== null) {
            $taskUri = trim((string)($rowValues[$uriCol] ?? ''));
        }

        $result[] = [
            'row' => $rowNum,
            'label' => $label,
            'uri' => $taskUri,
            'type' => $taskType,
        ];
    }

    $zip->close();
    return $result;
}

function fetchOrganizations(): array {
    $candidates = [50, 100, 200];
    $body = [];

    foreach ($candidates as $pageSize) {
        $data = httpGetJson(API_BASE . '/hascoapi/api/organization/keyword/_/' . $pageSize . '/0');
        if (!is_array($data)) {
            continue;
        }

        if (isset($data['body']) && is_array($data['body'])) {
            $body = $data['body'];
            if (count($body) > 0) {
                break;
            }
        }
        elseif (array_is_list($data)) {
            $body = $data;
            if (count($body) > 0) {
                break;
            }
        }
    }

    if (count($body) === 0) {
        $fallbackRaw = shell_exec('curl -s --max-time 120 ' . escapeshellarg(API_BASE . '/hascoapi/api/organization/keyword/_/50/0'));
        if (is_string($fallbackRaw) && trim($fallbackRaw) !== '') {
            $fallbackDecoded = json_decode($fallbackRaw, true);
            if (is_array($fallbackDecoded)) {
                if (isset($fallbackDecoded['body']) && is_array($fallbackDecoded['body'])) {
                    $body = $fallbackDecoded['body'];
                }
                elseif (array_is_list($fallbackDecoded)) {
                    $body = $fallbackDecoded;
                }
            }
        }
    }

    if (count($body) === 0) {
        return [];
    }

    $orgs = [];
    foreach ($body as $org) {
        if (!is_array($org)) {
            continue;
        }
        $uri = trim((string)($org['uri'] ?? ''));
        if ($uri === '') {
            continue;
        }
        $label = trim((string)($org['label'] ?? ''));
        $name = trim((string)($org['name'] ?? ''));
        $orgs[] = [
            'uri' => $uri,
            'label' => $label,
            'name' => $name,
        ];
    }

    return $orgs;
}

function fetchOrgComponentPool(string $orgUri): array {
    $url = API_BASE . '/hascoapi/api/instrument/prefilter?organizationUri=' . rawurlencode($orgUri);
    $data = httpGetJson($url);

    if (!is_array($data) || !($data['ok'] ?? false)) {
        return [
            'ok' => false,
            'count' => 0,
            'components' => [],
        ];
    }

    $componentsByUri = [];
    $instruments = $data['payload']['instruments'] ?? [];
    if (!is_array($instruments)) {
        $instruments = [];
    }

    foreach ($instruments as $instrument) {
        if (!is_array($instrument)) {
            continue;
        }

        $components = $instrument['components'] ?? [];
        if (!is_array($components)) {
            continue;
        }

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $uri = trim((string)($component['uri'] ?? ($component['hasURI'] ?? '')));
            if ($uri === '') {
                continue;
            }
            $componentsByUri[$uri] = [
                'uri' => $uri,
                'label' => trim((string)($component['label'] ?? '')),
                'componentModelUri' => trim((string)($component['componentModelUri'] ?? '')),
                'componentModelLabel' => trim((string)($component['componentModelLabel'] ?? '')),
                'componentTypeUri' => trim((string)($component['componentTypeUri'] ?? '')),
                'componentTypeLabel' => trim((string)($component['componentTypeLabel'] ?? '')),
                'componentRole' => trim((string)($component['componentRole'] ?? '')),
            ];
        }
    }

    return [
        'ok' => true,
        'count' => count($componentsByUri),
        'components' => array_values($componentsByUri),
    ];
}

function assignComponentsToTasks(array $interactionTasks, array $components): array {
    $assignments = [];
    $componentCount = count($components);

    if ($componentCount === 0) {
        foreach ($interactionTasks as $task) {
            $assignments[] = [
                'taskRow' => $task['row'],
                'taskLabel' => $task['label'],
                'taskUri' => $task['uri'],
                'componentInstanceUri' => null,
                'componentLabel' => null,
                'note' => 'No component instance available in organization scope',
            ];
        }
        return $assignments;
    }

    $idx = 0;
    foreach ($interactionTasks as $task) {
        $component = $components[$idx % $componentCount];
        $assignments[] = [
            'taskRow' => $task['row'],
            'taskLabel' => $task['label'],
            'taskUri' => $task['uri'],
            'componentInstanceUri' => $component['uri'],
            'componentLabel' => $component['label'],
            'componentModelUri' => $component['componentModelUri'],
            'componentModelLabel' => $component['componentModelLabel'],
        ];
        $idx++;
    }

    return $assignments;
}

function main(): int {
    $wkfFiles = glob(WKF_GLOB);
    sort($wkfFiles);

    $wkfTasks = [];
    $totalInteractionTasks = 0;
    foreach ($wkfFiles as $path) {
        $name = basename($path);
        $tasks = parseTaskRows($path);
        $wkfTasks[$name] = $tasks;
        $totalInteractionTasks += count($tasks);
    }

    $organizations = fetchOrganizations();
    if (count($organizations) === 0) {
        fwrite(STDERR, "No organizations returned by hascoapi.\n");
        return 1;
    }

    $orgPools = [];
    foreach ($organizations as $org) {
        $pool = fetchOrgComponentPool($org['uri']);
        $orgPools[$org['uri']] = [
            'organization' => $org,
            'pool' => $pool,
        ];
    }

    $wkfDeployMap = [];
    foreach ($wkfTasks as $wkfName => $tasks) {
        $requiredTaskCount = count($tasks);
        $eligibleOrgs = [];

        foreach ($orgPools as $orgUri => $entry) {
            $org = $entry['organization'];
            $pool = $entry['pool'];
            $available = (int)($pool['count'] ?? 0);

            if (!$pool['ok'] || $available <= 0) {
                continue;
            }

            // Strict deployability: enough distinct component instances to avoid reuse.
            $canDeployWithoutReuse = $available >= $requiredTaskCount;
            // Soft deployability: at least one component exists and round-robin reuse is possible.
            $canDeployWithReuse = $available > 0;

            if (!$canDeployWithReuse) {
                continue;
            }

            $components = $pool['components'] ?? [];
            usort($components, static function(array $a, array $b): int {
                return strcmp($a['uri'] ?? '', $b['uri'] ?? '');
            });

            $eligibleOrgs[] = [
                'organizationUri' => $orgUri,
                'organizationLabel' => $org['label'],
                'organizationName' => $org['name'],
                'availableComponentInstances' => $available,
                'canDeployWithoutReuse' => $canDeployWithoutReuse,
                'canDeployWithReuse' => $canDeployWithReuse,
                'sampleAssignments' => assignComponentsToTasks($tasks, $components),
            ];
        }

        usort($eligibleOrgs, static function(array $a, array $b): int {
            return $b['availableComponentInstances'] <=> $a['availableComponentInstances'];
        });

        $recommended = null;
        foreach ($eligibleOrgs as $candidate) {
            if ($candidate['canDeployWithoutReuse']) {
                $recommended = $candidate;
                break;
            }
        }
        if ($recommended === null && count($eligibleOrgs) > 0) {
            $recommended = $eligibleOrgs[0];
        }

        $wkfDeployMap[$wkfName] = [
            'interactionTaskCount' => $requiredTaskCount,
            'eligibleOrganizationCount' => count($eligibleOrgs),
            'recommendedOrganization' => $recommended,
            'eligibleOrganizations' => $eligibleOrgs,
        ];
    }

    $report = [
        'generatedAt' => gmdate('c'),
        'apiBase' => API_BASE,
        'notes' => [
            'InteractionTasks in current WKFs do not contain explicit component-model requirements.',
            'Mapping is derived from organization-scoped available component instances via /hascoapi/api/instrument/prefilter.',
            'canDeployWithoutReuse requires component-instance count >= InteractionTask count for a WKF.',
            'canDeployWithReuse requires at least one component instance and allows reuse across tasks.',
        ],
        'summary' => [
            'wkfCount' => count($wkfTasks),
            'totalInteractionTasks' => $totalInteractionTasks,
            'organizationCount' => count($organizations),
        ],
        'wkfDeploymentMap' => $wkfDeployMap,
    ];

    file_put_contents(OUT_JSON, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $md = [];
    $md[] = '# WKF Interaction Task Component Deployment Map';
    $md[] = '';
    $md[] = 'Generated at: ' . $report['generatedAt'];
    $md[] = '';
    $md[] = 'Summary:';
    $md[] = '- WKFs: ' . $report['summary']['wkfCount'];
    $md[] = '- InteractionTasks: ' . $report['summary']['totalInteractionTasks'];
    $md[] = '- Organizations scanned: ' . $report['summary']['organizationCount'];
    $md[] = '';
    $md[] = 'Interpretation:';
    $md[] = '- Since WKFs have no explicit component-model constraints for InteractionTasks, deployability is availability-based.';
    $md[] = '- Recommended organization is the first candidate that can deploy without reuse; otherwise highest-capacity org.';
    $md[] = '';

    foreach ($wkfDeployMap as $wkfName => $entry) {
        $md[] = '## ' . $wkfName;
        $md[] = '- InteractionTasks: ' . $entry['interactionTaskCount'];
        $md[] = '- Eligible organizations: ' . $entry['eligibleOrganizationCount'];
        if (is_array($entry['recommendedOrganization'])) {
            $rec = $entry['recommendedOrganization'];
            $md[] = '- Recommended deploy-at organization: ' . ($rec['organizationName'] !== '' ? $rec['organizationName'] : $rec['organizationLabel']) . ' (' . $rec['organizationUri'] . ')';
            $md[] = '- Available component instances at recommended org: ' . $rec['availableComponentInstances'];
        } else {
            $md[] = '- Recommended deploy-at organization: none (no components found)';
        }
        $md[] = '';
    }

    file_put_contents(OUT_MD, implode(PHP_EOL, $md) . PHP_EOL);

    echo 'Wrote: ' . OUT_JSON . PHP_EOL;
    echo 'Wrote: ' . OUT_MD . PHP_EOL;

    return 0;
}

exit(main());
