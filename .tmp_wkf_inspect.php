<?php
$u = 'https://pmsr.net/ont/WKF1788053879778132';
$api = \Drupal::service('rep.api_connector');
$obj = $api->parseObjectResponse($api->getUri($u), 'getUri');
if (!is_object($obj)) {
  echo "NO_OBJECT\n";
  return;
}
$fields = ['uri','label','typeUri','hasDataFileUri','hasSIRManager','hasSIRManagerEmail','hasOrganization'];
foreach ($fields as $f) {
  if (isset($obj->$f)) {
    echo $f . ': ' . json_encode($obj->$f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  }
}
if (isset($obj->hasDataFile) && is_object($obj->hasDataFile)) {
  echo 'hasDataFile: ' . json_encode($obj->hasDataFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

$dataFileId = 0;
$dataFileUri = '';
if (isset($obj->hasDataFile) && is_object($obj->hasDataFile)) {
  if (isset($obj->hasDataFile->id) && is_numeric($obj->hasDataFile->id)) {
    $dataFileId = (int) $obj->hasDataFile->id;
  }
  if (isset($obj->hasDataFile->uri) && is_string($obj->hasDataFile->uri)) {
    $dataFileUri = trim((string) $obj->hasDataFile->uri);
  }
}

if ($dataFileId > 0) {
  $file = \Drupal\file\Entity\File::load($dataFileId);
  if ($file) {
    $fileUri = $file->getFileUri();
    $realPath = \Drupal::service('file_system')->realpath($fileUri);
    echo 'file_uri: ' . $fileUri . "\n";
    echo 'real_path: ' . $realPath . "\n";
    echo 'readable: ' . ((is_string($realPath) && is_file($realPath) && is_readable($realPath)) ? 'yes' : 'no') . "\n";

    if (is_string($realPath) && is_file($realPath) && is_readable($realPath) && \Drupal::hasService('ctt.wkf_metadata_extractor')) {
      $json = (string) \Drupal::service('ctt.wkf_metadata_extractor')->extractMetadataJsonFromFile($realPath);
      echo 'meta.json: ' . $json . "\n";
      $meta = json_decode($json, TRUE);
      if (is_array($meta)) {
        $keys = [
          'wkf_uri',
          'study_uri',
          'study_label',
          'study_pi_uri',
          'study_pi_name',
          'study_organization_uri',
          'study_organization_name',
          'used_component_instances',
          'tasks_count',
          'scenario_properties_with_values',
        ];
        foreach ($keys as $k) {
          if (array_key_exists($k, $meta)) {
            echo 'meta.' . $k . ': ' . json_encode($meta[$k], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
          }
        }
      }
      else {
        echo "metadata_decode_failed\n";
      }
    }
  }
}
