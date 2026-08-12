<?php

declare(strict_types=1);

$mapPath = __DIR__ . '/../wkf/WKF-INTERACTION-TASK-COMPONENT-DEPLOYMENT-MAP.json';
$applyPath = '/tmp/wkf_apply_map_result.json';

$map = json_decode((string) file_get_contents($mapPath), true);
$apply = is_file($applyPath) ? json_decode((string) file_get_contents($applyPath), true) : null;

$allRecommendedUcp = true;
$recommendedOrgs = [];
$wkfCount = 0;

foreach (($map['wkfDeploymentMap'] ?? []) as $wkf => $entry) {
    $wkfCount++;
    $name = (string)($entry['recommendedOrganization']['organizationName'] ?? '');
    $uri = (string)($entry['recommendedOrganization']['organizationUri'] ?? '');
    $recommendedOrgs[$name . '|' . $uri] = true;
    if ($name !== 'Universidade Católica Portuguesa') {
        $allRecommendedUcp = false;
    }
}

echo 'WKF_COUNT=' . $wkfCount . PHP_EOL;
echo 'ALL_RECOMMENDED_UCP=' . ($allRecommendedUcp ? 'true' : 'false') . PHP_EOL;
echo 'RECOMMENDED_ORG_COUNT=' . count($recommendedOrgs) . PHP_EOL;
foreach (array_keys($recommendedOrgs) as $orgKey) {
    echo 'RECOMMENDED_ORG=' . $orgKey . PHP_EOL;
}

if (is_array($apply)) {
    $applyOk = true;
    $updatedRows = 0;
    foreach (($apply['results'] ?? []) as $r) {
        if (!(bool)($r['ok'] ?? false)) {
            $applyOk = false;
        }
        $updatedRows += (int)($r['updatedRows'] ?? 0);
    }
    echo 'APPLY_OK=' . ($applyOk ? 'true' : 'false') . PHP_EOL;
    echo 'APPLY_UPDATED_ROWS=' . $updatedRows . PHP_EOL;
}
