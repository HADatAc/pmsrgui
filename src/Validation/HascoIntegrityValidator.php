<?php

namespace Drupal\pmsr\Validation;

/**
 * Validates HASCO ontology integrity before modifications.
 * 
 * CRITICAL: This validator prevents catastrophic data loss by ensuring
 * hasco.ttl always contains all required entry point definitions.
 */
class HascoIntegrityValidator {

  /**
   * Required entry points that MUST exist in hasco.ttl
   */
  const REQUIRED_ENTRY_POINTS = [
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

  /**
   * Validate hasco.ttl file integrity.
   * 
   * @param string $ttl_content
   *   Content of hasco.ttl file.
   * 
   * @return array
   *   Validation result with 'valid', 'errors', and 'warnings' keys.
   */
  public static function validateHascoTtl($ttl_content) {
    $errors = [];
    $warnings = [];
    
    // Check 1: File is not empty
    if (empty(trim($ttl_content))) {
      $errors[] = "CRITICAL: hasco.ttl is empty!";
      return [
        'valid' => FALSE,
        'errors' => $errors,
        'warnings' => $warnings,
      ];
    }
    
    // Check 2: Has proper RDF/Turtle syntax
    if (strpos($ttl_content, '@prefix') === FALSE) {
      $errors[] = "CRITICAL: hasco.ttl missing @prefix declarations - may be corrupted";
    }
    
    if (strpos($ttl_content, 'hasco:') === FALSE) {
      $errors[] = "CRITICAL: hasco.ttl missing hasco: namespace prefix";
    }
    
    // Check for common syntax errors
    if (preg_match('/rdfs:subclassOf/', $ttl_content)) {
      $errors[] = 'CRITICAL: Found "rdfs:subclassOf" (lowercase) - must be "rdfs:subClassOf" (capital C)';
    }
    
    // Check 3: Has ontology declaration
    if (strpos($ttl_content, 'rdf:type         owl:Ontology') === FALSE &&
        strpos($ttl_content, 'a owl:Ontology') === FALSE) {
      $warnings[] = "WARNING: hasco.ttl missing ontology declaration";
    }
    
    // Check 4: Check for each required entry point
    $missing_entry_points = [];
    $defined_entry_points = [];
    
    foreach (self::REQUIRED_ENTRY_POINTS as $entry_point) {
      // Look for the entry point definition with rdfs:subClassOf hasco:ClassEntryPoint
      $pattern1 = "/hasco:{$entry_point}\s+.*rdfs:subClassOf\s+hasco:ClassEntryPoint/s";
      $pattern2 = "/hasco:{$entry_point}\s+a\s+owl:Class.*rdfs:subClassOf\s+hasco:ClassEntryPoint/s";
      
      if (preg_match($pattern1, $ttl_content) || preg_match($pattern2, $ttl_content)) {
        $defined_entry_points[] = $entry_point;
      } else {
        $missing_entry_points[] = $entry_point;
      }
    }
    
    if (!empty($missing_entry_points)) {
      $errors[] = "CRITICAL: Missing " . count($missing_entry_points) . " required entry point(s): " . 
                  implode(', ', $missing_entry_points);
    }
    
    // Check 5: Count total entry points (should have at least the required ones)
    $total_found = count($defined_entry_points);
    $total_required = count(self::REQUIRED_ENTRY_POINTS);
    
    if ($total_found < $total_required) {
      $errors[] = "CRITICAL: Only {$total_found} of {$total_required} required entry points defined";
    }
    
    // Check 6: Verify ClassEntryPoint exists
    if (strpos($ttl_content, 'hasco:ClassEntryPoint') === FALSE) {
      $errors[] = "CRITICAL: ClassEntryPoint class not found in hasco.ttl";
    }
    
    $is_valid = empty($errors);
    
    return [
      'valid' => $is_valid,
      'errors' => $errors,
      'warnings' => $warnings,
      'stats' => [
        'defined_entry_points' => $total_found,
        'required_entry_points' => $total_required,
        'missing_entry_points' => count($missing_entry_points),
      ],
    ];
  }

  /**
   * Create a validated backup before modifying hasco.ttl.
   * 
   * @param string $ttl_path
   *   Path to hasco.ttl file.
   * 
   * @return array
   *   Result with 'success', 'backup_path', and 'validation' keys.
   */
  public static function createValidatedBackup($ttl_path) {
    if (!file_exists($ttl_path)) {
      return [
        'success' => FALSE,
        'error' => 'hasco.ttl file does not exist',
      ];
    }
    
    $content = file_get_contents($ttl_path);
    $validation = self::validateHascoTtl($content);
    
    // Always create backup, but warn if validation fails
    $backup_dir = dirname($ttl_path) . '/emergency-backups';
    if (!is_dir($backup_dir)) {
      mkdir($backup_dir, 0755, TRUE);
    }
    
    $timestamp = date('Y-m-d_His');
    $backup_path = $backup_dir . '/hasco_' . $timestamp . '.ttl';
    
    $backup_success = copy($ttl_path, $backup_path);
    
    // Write validation report
    $report_path = $backup_dir . '/validation_' . $timestamp . '.json';
    file_put_contents($report_path, json_encode($validation, JSON_PRETTY_PRINT));
    
    return [
      'success' => $backup_success,
      'backup_path' => $backup_path,
      'report_path' => $report_path,
      'validation' => $validation,
    ];
  }

  /**
   * Restore hasco.ttl from most recent valid backup.
   * 
   * @param string $ttl_path
   *   Path to hasco.ttl file.
   * 
   * @return array
   *   Result with 'success', 'restored_from', and 'validation' keys.
   */
  public static function restoreFromBackup($ttl_path) {
    $backup_dir = dirname($ttl_path) . '/emergency-backups';
    
    if (!is_dir($backup_dir)) {
      return [
        'success' => FALSE,
        'error' => 'No backup directory found',
      ];
    }
    
    // Find all backup files sorted by date (newest first)
    $backups = glob($backup_dir . '/hasco_*.ttl');
    rsort($backups);
    
    // Try each backup until we find a valid one
    foreach ($backups as $backup_file) {
      $content = file_get_contents($backup_file);
      $validation = self::validateHascoTtl($content);
      
      if ($validation['valid']) {
        // Found a valid backup - restore it
        $restore_success = copy($backup_file, $ttl_path);
        
        return [
          'success' => $restore_success,
          'restored_from' => $backup_file,
          'validation' => $validation,
        ];
      }
    }
    
    return [
      'success' => FALSE,
      'error' => 'No valid backup found',
      'backups_checked' => count($backups),
    ];
  }

  /**
   * Generate complete hasco.ttl with all entry point definitions.
   * 
   * This is used as a last resort to rebuild hasco.ttl from scratch.
   * 
   * @return string
   *   Complete hasco.ttl content with all required entry points.
   */
  public static function generateCompleteHascoTtl() {
    $ttl = <<<'TTL'
@prefix hadatac: <http://hadatac.org/ont/hadatac/> .
@prefix hasco: <http://hadatac.org/ont/hasco/> .
@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .
@prefix owl: <http://www.w3.org/2002/07/owl#> .
@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .
@prefix skos: <http://www.w3.org/2004/02/skos/core#> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
@prefix vstoi: <http://hadatac.org/ont/vstoi#> .
@prefix sio: <http://semanticscience.org/resource/> .
@prefix schema: <https://schema.org/> .
@prefix foaf: <http://xmlns.com/foaf/0.1/> .

hasco:  owl:imports      <http://hadatac.org/ont/vstoi> ;
        owl:imports      <http://www.w3.org/TR/prov-o/> ;
        owl:imports      <http://www.w3.org/2004/02/skos/core> ;
        owl:imports      <http://purl.obolibrary.org/obo/uo.owl> ;
        owl:imports      <http://semanticscience.org/ontology/sio.owl> ;
        owl:versionIRI   hasco:10 ;
        rdfs:label       "HASCO APP Ontology" ;
        rdf:type         owl:Ontology .

# ============================================================================
# ENTRY POINT CLASS DEFINITIONS
# ============================================================================
# All entry points must be defined as subclasses of ClassEntryPoint
# DO NOT DELETE THESE DEFINITIONS - They are required for system integrity
# ============================================================================

# Base class for all entry points
hasco:ClassEntryPoint
	a owl:Class;
	rdfs:subClassOf owl:Class;
	rdfs:label "Class Entry Point"@en;
	rdfs:comment "Base class for all HASCO entry points. Entry points serve as binding points where external ontologies can be integrated into the HASCO framework."@en .

hasco:AnnotationStemEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Annotation Stem Entry Point"@en;
	rdfs:comment "Entry point for annotation stems."@en .

hasco:AnatomicalPartEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Anatomical Part Entry Point"@en;
	rdfs:comment "Entry point for anatomical parts and body structures."@en .

hasco:AttributeEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Attribute Entry Point"@en;
	rdfs:comment "Entry point for attributes."@en .

hasco:CodeBookEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Code Book Entry Point"@en;
	rdfs:comment "Entry point for code books."@en .

hasco:ComponentEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Component Entry Point"@en;
	rdfs:comment "Entry point for components."@en .

hasco:ComponentAttributeEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Component Attribute Entry Point"@en;
	rdfs:comment "Entry point for component attributes."@en .

hasco:ComponentStemEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Component Stem Entry Point"@en;
	rdfs:comment "Entry point for component stems."@en .

hasco:EntityEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Entity Entry Point"@en;
	rdfs:comment "Entry point for entities."@en .

hasco:GroupEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Group Entry Point"@en;
	rdfs:comment "Entry point for groups."@en .

hasco:InstrumentEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Instrument Entry Point"@en;
	rdfs:comment "Entry point for instruments."@en .

hasco:MedicalDeviceEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Medical Device Entry Point"@en;
	rdfs:comment "Entry point for medical devices and equipment."@en .

hasco:OrganizationEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Organization Entry Point"@en;
	rdfs:comment "Entry point for organizations."@en .

hasco:PersonEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Person Entry Point"@en;
	rdfs:comment "Entry point for persons."@en .

hasco:PlaceEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Place Entry Point"@en;
	rdfs:comment "Entry point for places."@en .

hasco:PlatformEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Platform Entry Point"@en;
	rdfs:comment "Entry point for platforms."@en .

hasco:QuestionnaireEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Questionnaire Entry Point"@en;
	rdfs:comment "Entry point for questionnaires."@en .

hasco:ResponseOptionEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Response Option Entry Point"@en;
	rdfs:comment "Entry point for response options."@en .

hasco:StudyEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Study Entry Point"@en;
	rdfs:comment "Entry point for studies."@en .

hasco:TaskEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Task Entry Point"@en;
	rdfs:comment "Entry point for tasks."@en .

hasco:TaskTemporalDependencyEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Task Temporal Dependency Entry Point"@en;
	rdfs:comment "Entry point for task temporal dependencies."@en .

hasco:UnitEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Unit Entry Point"@en;
	rdfs:comment "Entry point for units."@en .

hasco:WorkflowEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Workflow Entry Point"@en;
	rdfs:comment "Entry point for workflows."@en .

hasco:WorkflowStemEntryPoint
	a owl:Class;
	rdfs:subClassOf hasco:ClassEntryPoint;
	rdfs:label "Workflow Stem Entry Point"@en;
	rdfs:comment "Entry point for workflow stems and process stems."@en .

# ============================================================================
# END OF ENTRY POINT DEFINITIONS
# ============================================================================

TTL;
    
    return $ttl;
  }

}
