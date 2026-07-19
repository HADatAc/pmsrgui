<?php

namespace Drupal\Tests\pmsrgui\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\pmsrgui\Validation\HascoIntegrityValidator;

/**
 * Tests for HascoIntegrityValidator class.
 *
 * @group pmsrgui
 * @coversDefaultClass \Drupal\pmsrgui\Validation\HascoIntegrityValidator
 */
class HascoIntegrityValidatorTest extends UnitTestCase {

  /**
   * The validator instance.
   *
   * @var \Drupal\pmsrgui\Validation\HascoIntegrityValidator
   */
  protected $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new HascoIntegrityValidator();
  }

  /**
   * Test that generateCompleteHascoTtl includes all required components.
   *
   * @covers ::generateCompleteHascoTtl
   */
  public function testGenerateCompleteHascoTtlIncludesAllComponents() {
    $ttl = $this->validator->generateCompleteHascoTtl();

    // Check for namespace declarations
    $this->assertStringContainsString('@prefix hasco:', $ttl);
    $this->assertStringContainsString('@prefix rdfs:', $ttl);
    $this->assertStringContainsString('@prefix owl:', $ttl);

    // Check for ClassEntryPoint base class
    $this->assertStringContainsString('hasco:ClassEntryPoint', $ttl);
    $this->assertStringContainsString('a owl:Class', $ttl);

    // Check for all 23 required entry points
    $requiredEntryPoints = HascoIntegrityValidator::REQUIRED_ENTRY_POINTS;
    foreach ($requiredEntryPoints as $entryPoint) {
      $this->assertStringContainsString("hasco:$entryPoint", $ttl);
      $this->assertStringContainsString('rdfs:subClassOf hasco:ClassEntryPoint', $ttl);
    }
  }

  /**
   * Test validation of valid hasco.ttl content.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlWithValidContent() {
    $validTtl = $this->validator->generateCompleteHascoTtl();
    $result = $this->validator->validateHascoTtl($validTtl);

    $this->assertTrue($result['valid'], 'Valid TTL should pass validation');
    $this->assertEmpty($result['errors'], 'Valid TTL should have no errors');
    $this->assertEquals(23, $result['stats']['entry_points_found'], 'Should find all 23 entry points');
    $this->assertTrue($result['stats']['has_class_entry_point'], 'Should have ClassEntryPoint base class');
  }

  /**
   * Test validation rejects empty content.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlRejectsEmptyContent() {
    $result = $this->validator->validateHascoTtl('');

    $this->assertFalse($result['valid'], 'Empty content should fail validation');
    $this->assertNotEmpty($result['errors'], 'Empty content should have errors');
    $this->assertStringContainsString('empty', strtolower($result['errors'][0]));
  }

  /**
   * Test validation rejects content without proper RDF syntax.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlRejectsInvalidRdfSyntax() {
    $invalidTtl = "This is not valid RDF/Turtle syntax\nJust some random text";
    $result = $this->validator->validateHascoTtl($invalidTtl);

    $this->assertFalse($result['valid'], 'Invalid RDF syntax should fail validation');
    $this->assertNotEmpty($result['errors'], 'Invalid syntax should have errors');
  }

  /**
   * Test validation detects syntax errors like lowercase "subclassOf".
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlDetectsSyntaxErrors() {
    $invalidTtl = <<<TTL
@prefix hasco: <http://hadatac.org/ont/hasco/> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .

hasco:TestEntryPoint
    a owl:Class ;
    rdfs:subclassOf hasco:ClassEntryPoint .
TTL;

    $result = $this->validator->validateHascoTtl($invalidTtl);

    $this->assertFalse($result['valid'], 'Lowercase subclassOf should fail validation');
    $this->assertNotEmpty($result['errors']);
    $this->assertStringContainsString('subclassOf', $result['errors'][0]);
  }

  /**
   * Test validation detects missing entry points.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlDetectsMissingEntryPoints() {
    // Create TTL with only 2 entry points instead of 23
    $incompleteTtl = <<<TTL
@prefix hasco: <http://hadatac.org/ont/hasco/> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
@prefix owl: <http://www.w3.org/2002/07/owl#> .

hasco:ClassEntryPoint
    a owl:Class ;
    rdfs:label "Class Entry Point"@en .

hasco:AnatomicalPartEntryPoint
    a owl:Class ;
    rdfs:subClassOf hasco:ClassEntryPoint ;
    rdfs:label "Anatomical Part Entry Point"@en .

hasco:MedicalDeviceEntryPoint
    a owl:Class ;
    rdfs:subClassOf hasco:ClassEntryPoint ;
    rdfs:label "Medical Device Entry Point"@en .
TTL;

    $result = $this->validator->validateHascoTtl($incompleteTtl);

    $this->assertFalse($result['valid'], 'Missing entry points should fail validation');
    $this->assertNotEmpty($result['errors']);
    $this->assertEquals(2, $result['stats']['entry_points_found']);
    $this->assertGreaterThan(0, count($result['stats']['missing_entry_points']));
  }

  /**
   * Test validation detects missing ClassEntryPoint base class.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlDetectsMissingClassEntryPoint() {
    // Create TTL without ClassEntryPoint base class
    $ttlWithoutBase = <<<TTL
@prefix hasco: <http://hadatac.org/ont/hasco/> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
@prefix owl: <http://www.w3.org/2002/07/owl#> .

hasco:AnatomicalPartEntryPoint
    a owl:Class ;
    rdfs:subClassOf hasco:ClassEntryPoint ;
    rdfs:label "Anatomical Part Entry Point"@en .
TTL;

    $result = $this->validator->validateHascoTtl($ttlWithoutBase);

    $this->assertFalse($result['valid'], 'Missing ClassEntryPoint should fail validation');
    $this->assertFalse($result['stats']['has_class_entry_point']);
  }

  /**
   * Test validation provides detailed statistics.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlProvidesStatistics() {
    $validTtl = $this->validator->generateCompleteHascoTtl();
    $result = $this->validator->validateHascoTtl($validTtl);

    $this->assertArrayHasKey('stats', $result);
    $this->assertArrayHasKey('entry_points_found', $result['stats']);
    $this->assertArrayHasKey('has_class_entry_point', $result['stats']);
    $this->assertArrayHasKey('missing_entry_points', $result['stats']);
    $this->assertArrayHasKey('has_proper_syntax', $result['stats']);
  }

  /**
   * Test that all 23 required entry points are defined.
   *
   * @covers ::REQUIRED_ENTRY_POINTS
   */
  public function testRequiredEntryPointsConstant() {
    $requiredEntryPoints = HascoIntegrityValidator::REQUIRED_ENTRY_POINTS;

    $this->assertIsArray($requiredEntryPoints);
    $this->assertCount(23, $requiredEntryPoints);

    // Verify specific critical entry points
    $this->assertContains('AnatomicalPartEntryPoint', $requiredEntryPoints);
    $this->assertContains('WorkflowStemEntryPoint', $requiredEntryPoints);
    $this->assertContains('MedicalDeviceEntryPoint', $requiredEntryPoints);
    $this->assertContains('InstrumentEntryPoint', $requiredEntryPoints);

    // Ensure no duplicate entry points
    $this->assertCount(23, array_unique($requiredEntryPoints));
  }

  /**
   * Test validation handles malformed TTL gracefully.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlHandlesMalformedTtlGracefully() {
    $malformedTtl = <<<TTL
@prefix hasco: <http://hadatac.org/ont/hasco/> .
This line is malformed
hasco:Something
    missing semicolon
    rdfs:label "test"
TTL;

    $result = $this->validator->validateHascoTtl($malformedTtl);

    // Should not throw exception, should return validation result
    $this->assertIsArray($result);
    $this->assertArrayHasKey('valid', $result);
    $this->assertFalse($result['valid']);
  }

  /**
   * Test validation warning for deprecated ProcessEntryPoint.
   *
   * @covers ::validateHascoTtl
   */
  public function testValidateHascoTtlWarnsAboutProcessEntryPoint() {
    $ttlWithProcessEntryPoint = $this->validator->generateCompleteHascoTtl();
    $ttlWithProcessEntryPoint .= <<<TTL


hasco:ProcessEntryPoint
    a owl:Class ;
    rdfs:subClassOf hasco:ClassEntryPoint ;
    rdfs:label "Process Entry Point"@en .
TTL;

    $result = $this->validator->validateHascoTtl($ttlWithProcessEntryPoint);

    // Should still be valid (23 required are present) but should have warning
    $this->assertTrue($result['valid']);
    $this->assertNotEmpty($result['warnings']);
    $this->assertStringContainsString('ProcessEntryPoint', $result['warnings'][0]);
  }

}
