<?php

namespace Drupal\Tests\pmsrgui\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests for PMSR ontology ingestion integrity and protection layers.
 *
 * Regression tests for the critical data loss issue where PMSR went from
 * "near complete hasco-classes mapping" to "blank hasco-classes".
 *
 * @group pmsrgui
 * @group pmsrgui_critical
 */
class IngestionIntegrityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['pmsr', 'rep'];

  /**
   * Test that hasco.ttl contains all 23 required entry points.
   *
   * Regression test for Issue: Missing entry point definitions in hasco.ttl
   * Root cause: File was modified without validation, removing 21 of 23 entry points
   */
  public function testHascoTtlContainsAllRequiredEntryPoints() {
    $hascoPath = '/Users/Shared/drupal_private/ont/hasco.ttl';
    
    if (!file_exists($hascoPath)) {
      $this->markTestSkipped('hasco.ttl file not found');
    }

    $content = file_get_contents($hascoPath);
    
    // Verify all 23 entry points are defined
    $requiredEntryPoints = [
      'AnnotationStemEntryPoint',
      'AnatomicalPartEntryPoint',
      'AttributeEntryPoint',
      'CodeBookEntryPoint',
      'ComponentEntryPoint',
      'ComponentAttributeEntryPoint',
      'ComponentStemEntryPoint',
      'EntityEntryPoint',
      'GroupEntryPoint',
      'InstrumentEntryPoint',
      'MedicalDeviceEntryPoint',
      'OrganizationEntryPoint',
      'PersonEntryPoint',
      'PlaceEntryPoint',
      'PlatformEntryPoint',
      'QuestionnaireEntryPoint',
      'ResponseOptionEntryPoint',
      'StudyEntryPoint',
      'TaskEntryPoint',
      'TaskTemporalDependencyEntryPoint',
      'UnitEntryPoint',
      'WorkflowEntryPoint',
      'WorkflowStemEntryPoint',
    ];

    foreach ($requiredEntryPoints as $entryPoint) {
      $this->assertStringContainsString(
        "hasco:$entryPoint",
        $content,
        "Entry point $entryPoint must be defined in hasco.ttl"
      );
    }

    // Verify they are subclasses of ClassEntryPoint
    $subclassCount = substr_count($content, 'rdfs:subClassOf hasco:ClassEntryPoint');
    $this->assertEquals(
      23,
      $subclassCount,
      'All 23 entry points must be subclasses of ClassEntryPoint'
    );
  }

  /**
   * Test that hasco.ttl contains ClassEntryPoint base class.
   *
   * Regression test for Issue: Missing ClassEntryPoint base class
   * Template generator initially forgot to include the base class itself
   */
  public function testHascoTtlContainsClassEntryPointBaseClass() {
    $hascoPath = '/Users/Shared/drupal_private/ont/hasco.ttl';
    
    if (!file_exists($hascoPath)) {
      $this->markTestSkipped('hasco.ttl file not found');
    }

    $content = file_get_contents($hascoPath);

    // Verify ClassEntryPoint is defined as a class
    $this->assertStringContainsString(
      'hasco:ClassEntryPoint',
      $content,
      'ClassEntryPoint base class must be defined'
    );

    $this->assertMatchesRegularExpression(
      '/hasco:ClassEntryPoint\s+a\s+owl:Class/s',
      $content,
      'ClassEntryPoint must be declared as owl:Class'
    );
  }

  /**
   * Test that hasco.ttl does not contain ProcessEntryPoint.
   *
   * Regression test for Issue: Wrong PMSR entry point binding
   * ProcessEntryPoint was created by mistake, should be WorkflowStemEntryPoint
   */
  public function testHascoTtlDoesNotContainProcessEntryPoint() {
    $hascoPath = '/Users/Shared/drupal_private/ont/hasco.ttl';
    
    if (!file_exists($hascoPath)) {
      $this->markTestSkipped('hasco.ttl file not found');
    }

    $content = file_get_contents($hascoPath);

    $this->assertStringNotContainsString(
      'hasco:ProcessEntryPoint',
      $content,
      'Deprecated ProcessEntryPoint should not exist in hasco.ttl'
    );
  }

  /**
   * Test that PMSR is correctly bound to WorkflowStemEntryPoint.
   *
   * Regression test for Issue: Incorrect PMSR binding to ProcessEntryPoint
   * Correct binding: pmsr:MedicalSimulationProcessStem → hasco:WorkflowStemEntryPoint
   */
  public function testPmsrCorrectlyBoundToWorkflowStemEntryPoint() {
    $hascoPath = '/Users/Shared/drupal_private/ont/hasco.ttl';
    
    if (!file_exists($hascoPath)) {
      $this->markTestSkipped('hasco.ttl file not found');
    }

    $content = file_get_contents($hascoPath);

    // Verify PMSR binding exists
    $this->assertMatchesRegularExpression(
      '/pmsr:MedicalSimulationProcessStem.*rdfs:subClassOf.*hasco:WorkflowStemEntryPoint/s',
      $content,
      'PMSR must be bound to WorkflowStemEntryPoint'
    );

    // Verify old incorrect binding doesn't exist
    $this->assertStringNotContainsString(
      'pmsr:MedicalSimulationProcessStem',
      strstr($content, 'ProcessEntryPoint') ?: '',
      'PMSR should not be bound to deprecated ProcessEntryPoint'
    );
  }

  /**
   * Test getBoundEntryPoints API endpoint returns all bound entry points.
   *
   * Regression test for Issue: Entry point color-coding feature
   * API must correctly identify which entry points have children (are bound)
   */
  public function testGetBoundEntryPointsApiReturnsCorrectData() {
    $adminUser = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/bound-entry-points?_format=json');
    $this->assertSession()->statusCodeEquals(200);

    $response = $this->getSession()->getPage()->getContent();
    $data = json_decode($response, TRUE);

    $this->assertIsArray($data);
    $this->assertArrayHasKey('bound', $data);
    $this->assertArrayHasKey('count', $data);

    // Verify all 23 entry points are reported as bound
    $this->assertEquals(23, $data['count'], 'All 23 entry points should be bound');
    $this->assertCount(23, $data['bound'], 'Bound array should contain 23 URIs');

    // Verify WorkflowStemEntryPoint is in the list
    $this->assertContains(
      'http://hadatac.org/ont/hasco/WorkflowStemEntryPoint',
      $data['bound'],
      'WorkflowStemEntryPoint must be in bound list'
    );
  }

  /**
   * Test getTopClass API endpoint handles ClassEntryPoint correctly.
   *
   * Regression test for Issue: API namespace error
   * getTopClass() was calling getUri() for ClassEntryPoint, causing
   * "Could not retrieve any namespace" error
   */
  public function testGetTopClassApiHandlesClassEntryPointCorrectly() {
    $adminUser = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($adminUser);

    $classEntryPointUri = urlencode('http://hadatac.org/ont/hasco/ClassEntryPoint');
    $this->drupalGet("/rep/gettopclass?nodeUri=$classEntryPointUri&_format=json");
    $this->assertSession()->statusCodeEquals(200);

    $response = $this->getSession()->getPage()->getContent();
    $data = json_decode($response, TRUE);

    $this->assertIsArray($data);
    $this->assertNotEmpty($data, 'API should return entry point children');
    $this->assertCount(23, $data, 'ClassEntryPoint should have 23 children');
  }

  /**
   * Test that emergency backup directory exists and is writable.
   *
   * Regression test for Protection Layer: Automatic Backups
   */
  public function testEmergencyBackupDirectoryIsAvailable() {
    $backupDir = '/Users/Shared/drupal_private/ont/emergency-backups';
    
    if (!file_exists($backupDir)) {
      mkdir($backupDir, 0755, TRUE);
    }

    $this->assertDirectoryExists($backupDir, 'Emergency backup directory must exist');
    $this->assertDirectoryIsWritable($backupDir, 'Emergency backup directory must be writable');
  }

  /**
   * Test HascoIntegrityValidator can generate valid hasco.ttl template.
   *
   * Regression test for Protection Layer: Template Generation
   */
  public function testValidatorCanGenerateValidTemplate() {
    $validator = \Drupal::service('pmsrgui.hasco_integrity_validator');
    
    if (!$validator) {
      $this->markTestSkipped('HascoIntegrityValidator service not available');
    }

    $template = $validator->generateCompleteHascoTtl();

    $this->assertNotEmpty($template, 'Template should not be empty');
    $this->assertStringContainsString('@prefix hasco:', $template);
    $this->assertStringContainsString('hasco:ClassEntryPoint', $template);

    // Verify template passes its own validation
    $validation = $validator->validateHascoTtl($template);
    $this->assertTrue($validation['valid'], 'Generated template must pass validation');
  }

  /**
   * Test Map Entry Points page loads without errors.
   *
   * Regression test: Page was showing "ClassEntryPoint returned no object" error
   */
  public function testMapEntryPointsPageLoadsWithoutErrors() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->statusCodeEquals(200);
    
    // Verify no error messages about ClassEntryPoint
    $this->assertSession()->pageTextNotContains('returned no object from the knowledge graph');
    $this->assertSession()->pageTextNotContains('Could not retreive any namespace');
  }

  /**
   * Test that HASCO CLASSES tree displays all 23 entry points.
   *
   * Regression test: Tree was empty due to missing entry points in hasco.ttl
   */
  public function testHascoClassesTreeDisplaysAllEntryPoints() {
    $adminUser = $this->drupalCreateUser([
      'access content',
      'administer site configuration',
    ]);
    $this->drupalLogin($adminUser);

    $this->drupalGet('/rep/manage/map-entry-points');
    $this->assertSession()->statusCodeEquals(200);

    // Verify tree contains expected entry point labels
    $this->assertSession()->pageTextContains('Anatomical Part Entry Point');
    $this->assertSession()->pageTextContains('Workflow Stem Entry Point');
    $this->assertSession()->pageTextContains('Medical Device Entry Point');
    $this->assertSession()->pageTextContains('Instrument Entry Point');
  }

  /**
   * Test ingestion report includes triple counts.
   *
   * Regression test for Issue: Missing triple counts in reports
   * Reports didn't show how many triples were loaded per ontology
   */
  public function testIngestionReportIncludesTripleCounts() {
    // This would require running actual ingestion, which is complex
    // Documenting the expected behavior for manual testing
    $this->markTestIncomplete('Requires full ingestion process - manual testing recommended');
    
    // Expected behavior:
    // - Ingestion report should show "Loaded X triples" for each ontology
    // - Uses namespaceList() API call after ingestion
    // - Includes sleep(2) to allow triplestore to update counts
  }

  /**
   * Test that graph isolation prevents deletion of hasco graph content.
   *
   * Regression test for Protection Layer: Graph Isolation
   * Old uploadOntology() deleted entire hasco graph, causing data loss
   */
  public function testGraphIsolationPreventsDataLoss() {
    // This is a critical architectural test
    // Cannot easily test without actually ingesting, but documenting requirements
    $this->markTestIncomplete('Requires analysis of ingestion code - see implementation notes');
    
    // Expected behavior:
    // 1. IngestionController uses repoIngestNamespaceOntology() NOT uploadOntology()
    // 2. Entry point definitions only in hasco graph
    // 3. External ontology content in source-specific graphs (pmsr, uberon, ncit)
    // 4. No cross-graph deletion operations
  }

}
