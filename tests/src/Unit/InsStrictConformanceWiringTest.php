<?php

namespace Drupal\Tests\pmsrgui\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Guards strict INS-SPEC conformance wiring in INS ingestion controller.
 *
 * @group pmsrgui
 */
class InsStrictConformanceWiringTest extends UnitTestCase {

  /**
   * Ensures INS ingestion no longer falls back to legacy workbook.
   */
  public function testNoLegacyWorkbookFallbackInProcessFlow() {
    $controllerPath = dirname(__DIR__, 3) . '/src/Controller/IngestionINSController.php';
    $this->assertFileExists($controllerPath);

    $source = file_get_contents($controllerPath);
    $this->assertIsString($source);

    $this->assertStringContainsString('INS_PRIMARY_FILENAME', $source);
    $this->assertStringNotContainsString('INS_LEGACY_FILENAME', $source);
    $this->assertStringNotContainsString('Falling back to legacy file', $source);
    $this->assertStringNotContainsString('if (!file_exists($primary_file_path) && file_exists($legacy_file_path))', $source);
  }

  /**
   * Ensures unexpected InfoSheet keys fail instead of being sanitized.
   */
  public function testStrictInfoSheetValidationIsWired() {
    $controllerPath = dirname(__DIR__, 3) . '/src/Controller/IngestionINSController.php';
    $this->assertFileExists($controllerPath);

    $source = file_get_contents($controllerPath);
    $this->assertIsString($source);

    $this->assertStringContainsString('validateINSInfoSheetKeySet(', $source);
    $this->assertStringContainsString('INS InfoSheet key-set does not match INS-SPEC', $source);
    $this->assertStringNotContainsString('sanitizeINSInfoSheetEvidenceKeys(', $source);
  }
}
