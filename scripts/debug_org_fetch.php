<?php

declare(strict_types=1);

$url = 'http://localhost:9001/hascoapi/api/organization/keyword/_/50/0';
$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 120,
        'ignore_errors' => true,
    ],
]);

$body = @file_get_contents($url, false, $ctx);
echo "stream_body_len=" . ($body === false ? 'false' : strlen($body)) . PHP_EOL;
$data = json_decode((string)$body, true);
echo "stream_decoded_is_array=" . (is_array($data) ? '1' : '0') . PHP_EOL;
echo "stream_has_body=" . ((is_array($data) && isset($data['body']) && is_array($data['body'])) ? '1' : '0') . PHP_EOL;
if (is_array($data) && isset($data['body']) && is_array($data['body'])) {
    echo "stream_body_count=" . count($data['body']) . PHP_EOL;
    if (!empty($data['body'][0]['uri'])) {
        echo "stream_first_uri=" . $data['body'][0]['uri'] . PHP_EOL;
    }
}

$raw = shell_exec('curl -s --max-time 120 ' . escapeshellarg($url));
echo "curl_body_len=" . (is_string($raw) ? strlen($raw) : 0) . PHP_EOL;
$decoded = json_decode((string)$raw, true);
echo "curl_decoded_is_array=" . (is_array($decoded) ? '1' : '0') . PHP_EOL;
echo "curl_has_body=" . ((is_array($decoded) && isset($decoded['body']) && is_array($decoded['body'])) ? '1' : '0') . PHP_EOL;
if (is_array($decoded) && isset($decoded['body']) && is_array($decoded['body'])) {
    echo "curl_body_count=" . count($decoded['body']) . PHP_EOL;
    if (!empty($decoded['body'][0]['uri'])) {
        echo "curl_first_uri=" . $decoded['body'][0]['uri'] . PHP_EOL;
    }
}
