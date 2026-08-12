<?php

declare(strict_types=1);

/**
 * Second-level ownership verification for assigned component-instance URIs.
 *
 * Checks performed per assigned URI:
 * 1) URI exists in hascoapi componentinstance list index.
 * 2) URI appears in UCP organization-scoped prefilter component pool.
 * 3) Ownership metadata fields from componentinstance payload are captured.
 *
 * Outputs JSON report at:
 *   wkf/WKF-COMPONENT-INSTANCE-OWNERSHIP-CHECK.json
 */

const API_BASE = 'http://localhost:9001';
const MAP_JSON = __DIR__ . '/../wkf/WKF-INTERACTION-TASK-COMPONENT-DEPLOYMENT-MAP.json';
const OUT_JSON = __DIR__ . '/../wkf/WKF-COMPONENT-INSTANCE-OWNERSHIP-CHECK.json';
const UCP_URI = 'https://pmsr.net/ont/ORG174547834502838939';

function httpGetJson(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function loadAssignedUrisFromMap(string $mapPath): array
{
    $raw = file_get_contents($mapPath);
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return [];
    }

    $uris = [];
    $perWkf = [];

    foreach (($json['wkfDeploymentMap'] ?? []) as $wkfName => $entry) {
        $assignments = $entry['recommendedOrganization']['sampleAssignments'] ?? [];
        if (!is_array($assignments)) {
            continue;
        }

        foreach ($assignments as $a) {
            if (!is_array($a)) {
                continue;
            }
            $uri = trim((string)($a['componentInstanceUri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $uris[$uri] = true;
            $perWkf[$wkfName][] = [
                'taskRow' => (int)($a['taskRow'] ?? 0),
                'taskLabel' => (string)($a['taskLabel'] ?? ''),
                'componentInstanceUri' => $uri,
            ];
        }
    }

    ksort($uris);
    return ['uniqueUris' => array_keys($uris), 'perWkf' => $perWkf];
}

function fetchComponentCount(): int
{
    $url = API_BASE . '/hascoapi/api/componentinstance/count';
    $json = httpGetJson($url);
    if (!is_array($json) || !($json['isSuccessful'] ?? false)) {
        return 0;
    }
    return (int)($json['body']['total'] ?? 0);
}

function fetchAllComponentsIndexed(int $pageSize = 200): array
{
    $total = fetchComponentCount();
    $index = [];

    if ($total <= 0) {
        return $index;
    }

    for ($offset = 0; $offset < $total; $offset += $pageSize) {
        $url = API_BASE . '/hascoapi/api/componentinstance/list/' . $pageSize . '/' . $offset;
        $json = httpGetJson($url);
        if (!is_array($json) || !($json['isSuccessful'] ?? false)) {
            continue;
        }

        $items = $json['body'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            continue;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uri = trim((string)($item['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $index[$uri] = $item;
        }
    }

    return $index;
}

function fetchUcpPrefilterComponentUris(): array
{
    $url = API_BASE . '/hascoapi/api/instrument/prefilter?organizationUri=' . rawurlencode(UCP_URI);
    $json = httpGetJson($url);

    $uris = [];
    if (!is_array($json) || !($json['ok'] ?? false)) {
        return $uris;
    }

    $instruments = $json['payload']['instruments'] ?? [];
    if (!is_array($instruments)) {
        return $uris;
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
            if ($uri !== '') {
                $uris[$uri] = true;
            }
        }
    }

    ksort($uris);
    return array_keys($uris);
}

if (!is_file(MAP_JSON)) {
    fwrite(STDERR, 'Mapping JSON not found: ' . MAP_JSON . PHP_EOL);
    exit(1);
}

$assigned = loadAssignedUrisFromMap(MAP_JSON);
$assignedUris = $assigned['uniqueUris'] ?? [];
$perWkf = $assigned['perWkf'] ?? [];

$componentIndex = fetchAllComponentsIndexed(200);
$ucpPool = fetchUcpPrefilterComponentUris();
$ucpSet = array_fill_keys($ucpPool, true);

$items = [];
$missingInList = 0;
$missingInUcpPool = 0;
$explicitNonUcpOwner = 0;
$nullOwnerMetadata = 0;

foreach ($assignedUris as $uri) {
    $row = [
        'componentInstanceUri' => $uri,
        'foundInComponentList' => false,
        'inUcpPrefilterPool' => false,
        'hasOwnerUri' => null,
        'hasOwner' => null,
        'hasOwnerLabel' => null,
        'ownershipAssessment' => 'unknown',
    ];

    if (isset($componentIndex[$uri])) {
        $row['foundInComponentList'] = true;
        $obj = $componentIndex[$uri];
        $hasOwnerUri = trim((string)($obj['hasOwnerUri'] ?? ''));
        $row['hasOwnerUri'] = $hasOwnerUri !== '' ? $hasOwnerUri : null;

        $hasOwner = $obj['hasOwner'] ?? null;
        if (is_array($hasOwner)) {
            $ownerUri = trim((string)($hasOwner['uri'] ?? ''));
            $ownerLabel = trim((string)($hasOwner['label'] ?? ''));
            $row['hasOwner'] = $ownerUri !== '' ? $ownerUri : null;
            $row['hasOwnerLabel'] = $ownerLabel !== '' ? $ownerLabel : null;
        }

        if ($row['hasOwnerUri'] === null && $row['hasOwner'] === null) {
            $nullOwnerMetadata++;
        }

        $ownerCandidate = $row['hasOwnerUri'] ?? $row['hasOwner'];
        if (is_string($ownerCandidate) && $ownerCandidate !== '') {
            if ($ownerCandidate === UCP_URI) {
                $row['ownershipAssessment'] = 'explicit-ucp-owner';
            } else {
                $row['ownershipAssessment'] = 'explicit-non-ucp-owner';
                $explicitNonUcpOwner++;
            }
        } else {
            $row['ownershipAssessment'] = 'owner-not-populated';
        }
    } else {
        $missingInList++;
        $row['ownershipAssessment'] = 'not-found-in-component-list';
    }

    if (isset($ucpSet[$uri])) {
        $row['inUcpPrefilterPool'] = true;
    } else {
        $missingInUcpPool++;
    }

    $items[] = $row;
}

$allFoundInList = $missingInList === 0;
$allInUcpPool = $missingInUcpPool === 0;
$allExplicitlyUcpOwned = $explicitNonUcpOwner === 0 && $nullOwnerMetadata === 0;

$report = [
    'generatedAt' => gmdate('c'),
    'ucpOrganizationUri' => UCP_URI,
    'summary' => [
        'assignedUniqueComponentInstances' => count($assignedUris),
        'assignedTaskRowsTotal' => array_sum(array_map('count', $perWkf)),
        'foundInComponentList' => count($assignedUris) - $missingInList,
        'missingInComponentList' => $missingInList,
        'inUcpPrefilterPool' => count($assignedUris) - $missingInUcpPool,
        'missingInUcpPrefilterPool' => $missingInUcpPool,
        'explicitNonUcpOwnerMetadata' => $explicitNonUcpOwner,
        'nullOwnerMetadata' => $nullOwnerMetadata,
        'allFoundInComponentList' => $allFoundInList,
        'allInUcpPrefilterPool' => $allInUcpPool,
        'allExplicitlyUcpOwnedByMetadata' => $allExplicitlyUcpOwned,
    ],
    'interpretation' => [
        'membershipRule' => 'URI is treated as UCP-scoped if it appears in hascoapi instrument/prefilter for UCP organizationUri.',
        'ownerMetadataNote' => 'Many componentinstance records do not populate hasOwnerUri/hasOwner; membership in UCP prefilter is stronger in this dataset.',
    ],
    'items' => $items,
];

file_put_contents(OUT_JSON, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo 'Wrote report: ' . OUT_JSON . PHP_EOL;
echo 'assignedUnique=' . $report['summary']['assignedUniqueComponentInstances'] . PHP_EOL;
echo 'allFoundInComponentList=' . ($allFoundInList ? 'true' : 'false') . PHP_EOL;
echo 'allInUcpPrefilterPool=' . ($allInUcpPool ? 'true' : 'false') . PHP_EOL;
echo 'allExplicitlyUcpOwnedByMetadata=' . ($allExplicitlyUcpOwned ? 'true' : 'false') . PHP_EOL;
echo 'nullOwnerMetadata=' . $nullOwnerMetadata . PHP_EOL;
