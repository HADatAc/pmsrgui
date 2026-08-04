<?php

declare(strict_types=1);

namespace Drupal\Tests\pmsr\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Verifies auxiliary tool ontology ingestion wiring and wildcard retrieval.
 *
 * @group pmsr
 */
final class AuxiliaryToolsIngestionAndEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'pmsr', 'ctt', 'rep'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'hasco_barrio';

  /**
   * Ensures tool.ttl is part of auxiliary ingestion and wildcard tools are retrievable.
   */
  public function testAuxiliaryToolTtlAndWildcardEndpointVisibility(): void {
    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $toolTtlPath = DRUPAL_ROOT . '/' . $modulePath . '/ontologies/tool.ttl';
    $auxControllerPath = DRUPAL_ROOT . '/' . $modulePath . '/src/Controller/IngestionAuxiliaryDataController.php';

    $this->assertFileExists($toolTtlPath, 'tool.ttl must exist in pmsrgui/ontologies.');

    $toolTtlContent = (string) file_get_contents($toolTtlPath);
    $this->assertStringContainsString('hasco:AnyProcess', $toolTtlContent);
    $this->assertStringContainsString('Individual CTT Simulator', $toolTtlContent);
    $this->assertStringContainsString('Cohort CTT Simulator', $toolTtlContent);

    $this->assertFileExists($auxControllerPath, 'IngestionAuxiliaryDataController must exist.');
    $auxControllerCode = (string) file_get_contents($auxControllerPath);
    $this->assertStringContainsString('tool.ttl', $auxControllerCode, 'Auxiliary ingestion must reference tool.ttl.');
    $this->assertStringContainsString('repoIngestNamespaceOntology', $auxControllerCode, 'Auxiliary ingestion must ingest ontology content.');
    $this->assertStringContainsString('AnyProcess', $auxControllerCode, 'Auxiliary ingestion must use hasco:AnyProcess wildcard semantics.');

    $anyProcessUri = 'http://hadatac.org/ont/hasco/AnyProcess';
    \Drupal::state()->set('ctt.analytical_tools.catalog.v1', [
      'https://pmsr.net/tool/AT-INDIVIDUAL-CTT-SIMULATOR' => [
        'toolUri' => 'https://pmsr.net/tool/AT-INDIVIDUAL-CTT-SIMULATOR',
        'name' => 'Individual CTT Simulator',
        'language' => 'r',
        'status' => 'current',
        'processUri' => $anyProcessUri,
        'ownerUserEmail' => 'admin@pmsr.com',
        'createdBy' => 'admin@pmsr.com',
        'updatedBy' => 'admin@pmsr.com',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ],
      'https://pmsr.net/tool/AT-COHORT-CTT-SIMULATOR' => [
        'toolUri' => 'https://pmsr.net/tool/AT-COHORT-CTT-SIMULATOR',
        'name' => 'Cohort CTT Simulator',
        'language' => 'r',
        'status' => 'current',
        'processUri' => $anyProcessUri,
        'ownerUserEmail' => 'admin@pmsr.com',
        'createdBy' => 'admin@pmsr.com',
        'updatedBy' => 'admin@pmsr.com',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ],
    ]);

    $account = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($account);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'processUri' => 'http://example.org/process/current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertTrue((bool) ($payload['isSuccessful'] ?? FALSE));

    $rows = $payload['body'] ?? [];
    $this->assertIsArray($rows);
    $this->assertCount(2, $rows, 'Wildcard AnyProcess tools must be included in process-filtered endpoint response.');

    $names = array_map(static function (array $row): string {
      return (string) ($row['name'] ?? '');
    }, $rows);

    $this->assertContains('Individual CTT Simulator', $names);
    $this->assertContains('Cohort CTT Simulator', $names);
  }

}
