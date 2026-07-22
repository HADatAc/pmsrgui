<?php
// Quick test of statistics logic
$api_url = "http://localhost:9001";

// Test 1: Get platforms
echo "Testing platform retrieval...\n";
$platforms_response = file_get_contents("$api_url/hascoapi/api/platforminstance/keyword/_/10/0");
$platforms_data = json_decode($platforms_response);
if ($platforms_data && isset($platforms_data->body)) {
    $platforms = $platforms_data->body;
    echo "Found " . count($platforms) . " platforms\n";
    
    // Count by organization
    $org_counts = [];
    foreach ($platforms as $p) {
        if (isset($p->partOf) && $p->partOf) {
            $org_counts[$p->partOf] = ($org_counts[$p->partOf] ?? 0) + 1;
        }
    }
    echo "Platform counts by org:\n";
    foreach ($org_counts as $org => $count) {
        $orgShort = substr($org, strrpos($org, '/') + 1);
        echo "  $orgShort: $count\n";
    }
}

// Test 2: Get deployments for first platform
echo "\nTesting deployment retrieval...\n";
if (!empty($platforms[0]->uri)) {
    $platformUri = rawurlencode($platforms[0]->uri);
    $deploy_response = file_get_contents("$api_url/hascoapi/api/deploymentbyplatforminstance/$platformUri/100/0");
    $deploy_data = json_decode($deploy_response);
    if ($deploy_data && isset($deploy_data->body)) {
        $deployments = $deploy_data->body;
        echo "Found " . count($deployments) . " deployments for " . $platforms[0]->label . "\n";
        $instruments = [];
        foreach ($deployments as $d) {
            if (isset($d->instrumentInstanceUri)) {
                $instruments[$d->instrumentInstanceUri] = true;
            }
        }
        echo "Unique instruments: " . count($instruments) . "\n";
    }
}
