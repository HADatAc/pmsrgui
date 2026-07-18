#!/usr/bin/env python3
"""
Analyze PMSR ontology files to find top-level concepts and count subclasses.
"""

import re
from collections import defaultdict

def parse_ttl_hierarchy(filename):
    """Parse a Turtle file and build class hierarchy."""
    classes = {}
    subclass_of = defaultdict(list)
    
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Find all class declarations with their properties
    # Pattern: obo:NCIT_CXXXXX or pmsr:ClassName rdf:type owl:Class
    class_pattern = r'((?:obo:NCIT_C\d+|pmsr:\w+))\s+rdf:type\s+owl:Class'
    subclass_pattern = r'rdfs:subClassOf\s+((?:obo:NCIT_C\d+|pmsr:\w+))'
    label_pattern = r'rdfs:label\s+"([^"]+)"'
    
    # Split into class blocks
    blocks = re.split(r'\n(?=(?:obo:NCIT_C\d+|pmsr:\w+)\s+rdf:type\s+owl:Class)', content)
    
    for block in blocks:
        # Find class name
        class_match = re.search(class_pattern, block)
        if not class_match:
            continue
        
        class_uri = class_match.group(1)
        
        # Find label
        label_match = re.search(label_pattern, block)
        label = label_match.group(1) if label_match else class_uri
        
        # Find parent classes
        parent_matches = re.findall(subclass_pattern, block)
        
        classes[class_uri] = {
            'label': label,
            'parents': parent_matches
        }
        
        # Build reverse mapping (parent -> children)
        for parent in parent_matches:
            subclass_of[parent].append(class_uri)
    
    return classes, subclass_of

def count_descendants(class_uri, subclass_of, visited=None):
    """Recursively count all descendants of a class."""
    if visited is None:
        visited = set()
    
    if class_uri in visited:
        return 0
    
    visited.add(class_uri)
    count = 0
    
    for child in subclass_of.get(class_uri, []):
        count += 1  # Count the direct child
        count += count_descendants(child, subclass_of, visited)  # Count its descendants
    
    return count

def analyze_ontology(filename, top_level_concepts):
    """Analyze an ontology file for specific top-level concepts."""
    print(f"\n{'='*80}")
    print(f"Analyzing: {filename}")
    print(f"{'='*80}\n")
    
    classes, subclass_of = parse_ttl_hierarchy(filename)
    
    print(f"Total classes found: {len(classes)}")
    
    for concept_uri in top_level_concepts:
        if concept_uri in classes:
            concept_info = classes[concept_uri]
            direct_children = len(subclass_of.get(concept_uri, []))
            total_descendants = count_descendants(concept_uri, subclass_of)
            
            print(f"\nTop-level concept: {concept_info['label']}")
            print(f"  URI: {concept_uri}")
            print(f"  Direct subclasses: {direct_children}")
            print(f"  Total descendants (all levels): {total_descendants}")
            
            # Show some direct children
            if direct_children > 0:
                print(f"  Direct children:")
                for child_uri in subclass_of[concept_uri][:5]:
                    child_label = classes.get(child_uri, {}).get('label', child_uri)
                    print(f"    - {child_label} ({child_uri})")
                if direct_children > 5:
                    print(f"    ... and {direct_children - 5} more")
        else:
            print(f"\n⚠️  Top-level concept not found: {concept_uri}")

if __name__ == '__main__':
    # PMSR - Clinical Processes
    print("\n" + "="*80)
    print("PMSR ONTOLOGY - Clinical Simulation Processes")
    analyze_ontology('pmsr.ttl', [
        'pmsr:MedicalSimulationProcessStem',
        'pmsr:SimulationProcessStem'
    ])
    
    # NCIT - Medical Devices
    print("\n" + "="*80)
    print("NCIT ONTOLOGY - Medical Devices")
    analyze_ontology('ncit-pmsr.ttl', [
        'obo:NCIT_C97325',  # Manufactured Object
        'obo:NCIT_C19238',  # Medical Device
    ])
    
    print("\n" + "="*80)
    print("\nNote: UBERON ontology file (uberon.ttl) is not yet present.")
    print("UBERON would provide anatomical structures with top-level concept:")
    print("  - UBERON_0001062 (Anatomical Entity)")
    print("="*80 + "\n")
