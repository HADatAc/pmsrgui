<?php

namespace Drupal\Tests\pmsrgui\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Guards DP2 preflight wiring in ingestion controller.
 *
 * @group pmsrgui
 */
class Dp2PreflightWiringTest extends UnitTestCase {

  /**
   * Ensures DP2 preflight is wired before uploadTemplate('dp2').
   */
  public function testControllerIncludesBlockingPreflightGate() {
    $controllerPath = dirname(__DIR__, 3) . '/src/Controller/IngestionKgrPeopleController.php';
    $this->assertFileExists($controllerPath);

    $source = file_get_contents($controllerPath);
    $this->assertIsString($source);

    $this->assertStringContainsString('runDp2Preflight(', $source);
    $this->assertStringContainsString('if (!$preflight[\'success\'])', $source);
    $this->assertStringContainsString("uploadTemplate('dp2'", $source);

    $preflightPos = strpos($source, 'runDp2Preflight(');
    $uploadPos = strpos($source, "uploadTemplate('dp2'");

    $this->assertNotFalse($preflightPos);
    $this->assertNotFalse($uploadPos);
    $this->assertLessThan($uploadPos, $preflightPos, 'Preflight must occur before DP2 uploadTemplate call.');
  }

  /**
   * Ensures preflight uses stable system Python runtime.
   */
  public function testPreflightUsesSystemPythonRuntime() {
    $controllerPath = dirname(__DIR__, 3) . '/src/Controller/IngestionKgrPeopleController.php';
    $this->assertFileExists($controllerPath);

    $source = file_get_contents($controllerPath);
    $this->assertIsString($source);

    $this->assertStringContainsString("'/usr/bin/python3'", $source);
    $this->assertStringContainsString("'--ins-workbook'", $source);
  }
}
