# Statistics Caching System

## Overview

The PMSR Statistics page implements an ontology-specific caching system that stores each statistic independently and invalidates caches only when their source ontologies are updated or deleted.

## Cache Structure

### Cache Entries

| Cache Key | Description | Source Ontology | Cache Tag |
|-----------|-------------|-----------------|-----------|
| `pmsr_statistics:instruments` | Simulator Models count | INS | `pmsr_ontology:ins` |
| `pmsr_statistics:procedures` | Clinical Procedures count | PMSR | `pmsr_ontology:pmsr` |
| `pmsr_statistics:anatomy` | Anatomical Structures count | UBERON | `pmsr_ontology:uberon` |
| `pmsr_statistics:devices` | Medical Devices count | NCIT | `pmsr_ontology:ncit` |

### Cache Lifetime

- All statistics caches use `Cache::PERMANENT` (never expire automatically)
- Caches are only invalidated when their specific ontology is updated/deleted
- This ensures maximum performance while maintaining data accuracy

## Automatic Cache Invalidation

Caches are automatically invalidated in these scenarios:

### 1. Ontology Ingestion

When ontologies are ingested via `/pmsr/ingest/ontologies`, the system:

1. Tracks which ontologies were successfully ingested
2. Invalidates only the caches for those ontologies
3. Reports cache invalidation in the progress log

**Example log output:**
```
✓ Successfully loaded 397 triples for pmsr
✓ Invalidated statistics cache for pmsr
```

### 2. Manual Cache Invalidation

You can manually invalidate specific ontology caches:

```php
use Drupal\pmsr\Controller\IngestionController;

// Invalidate single ontology
IngestionController::invalidateStatisticsCache(['ins']);

// Invalidate multiple ontologies
IngestionController::invalidateStatisticsCache(['pmsr', 'ncit', 'uberon']);

// Invalidate all statistics
IngestionController::invalidateStatisticsCache(['ins', 'pmsr', 'uberon', 'ncit']);
```

## Testing Cache Behavior

### Verify Cache Population

1. Clear all caches: `curl http://localhost:8080/rebuild.php`
2. Load statistics page: Open http://localhost:8080/pmsr/statistics
3. First load will fetch from API and populate cache (slower)
4. Reload page - should load instantly from cache

### Verify Selective Invalidation

```php
// Test script to verify cache behavior
$cache = \Drupal::cache('data');

// Check if caches exist
$instruments = $cache->get('pmsr_statistics:instruments');
$procedures = $cache->get('pmsr_statistics:procedures');
$anatomy = $cache->get('pmsr_statistics:anatomy');
$devices = $cache->get('pmsr_statistics:devices');

echo "Instruments cache: " . ($instruments ? "EXISTS (value: {$instruments->data})" : "EMPTY") . "\n";
echo "Procedures cache: " . ($procedures ? "EXISTS (value: {$procedures->data})" : "EMPTY") . "\n";
echo "Anatomy cache: " . ($anatomy ? "EXISTS (value: {$anatomy->data})" : "EMPTY") . "\n";
echo "Devices cache: " . ($devices ? "EXISTS (value: {$devices->data})" : "EMPTY") . "\n";

// Invalidate only PMSR cache
\Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache(['pmsr']);

// Verify selective invalidation
$procedures_after = $cache->get('pmsr_statistics:procedures');
$anatomy_after = $cache->get('pmsr_statistics:anatomy');

echo "\nAfter invalidating 'pmsr':\n";
echo "Procedures cache: " . ($procedures_after ? "STILL EXISTS" : "INVALIDATED") . "\n";
echo "Anatomy cache: " . ($anatomy_after ? "STILL EXISTS" : "INVALIDATED") . "\n";
```

## Benefits

### Performance
- **First load**: Fetches from API (~1-2 seconds)
- **Cached loads**: Instant (<50ms)
- No unnecessary API calls for unchanged data

### Precision
- Updating PMSR ontology only invalidates procedures cache
- Updating INS ontology only invalidates instruments cache
- Other statistics remain cached and fast

### Reliability
- Permanent cache prevents accidental expiration
- Tagged cache allows surgical invalidation
- Automatic invalidation during ingestion

## Architecture

### Flow Diagram

```
User visits /pmsr/statistics
    ↓
StatisticsController::content()
    ↓
For each statistic:
    ├─→ Check cache (pmsr_statistics:{type})
    │   ├─→ Cache HIT: Return cached value (fast)
    │   └─→ Cache MISS: Fetch from API
    │       ├─→ Store in cache with tag (pmsr_ontology:{ontology})
    │       └─→ Return fresh value
    ↓
Render page with all statistics

When ontology updates:
    ↓
IngestionController::processOntologyIngestion()
    ↓
Track successfully ingested ontologies
    ↓
IngestionController::invalidateStatisticsCache(['pmsr', 'uberon', ...])
    ↓
Drupal cache tag invalidation
    ↓
Next page load will repopulate from API
```

## Maintenance

### Clear All Statistics Caches

```bash
# Via Drupal cache rebuild
curl http://localhost:8080/rebuild.php

# Or via PHP
\Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache(['ins', 'pmsr', 'uberon', 'ncit']);
```

### Monitor Cache Performance

Check Drupal logs for cache invalidation events:

```bash
tail -f /opt/homebrew/var/www/drupal/web/sites/default/files/php.log | grep "statistics cache"
```

### Debugging

If statistics appear stale:

1. Check if cache was invalidated during last ontology update
2. Manually invalidate specific cache: `IngestionController::invalidateStatisticsCache(['pmsr'])`
3. Check Drupal logs for errors during cache writes
4. Verify cache tags are correctly set in StatisticsController

## Future Enhancements

Potential improvements to consider:

1. **Cache warming**: Pre-populate caches after ontology ingestion
2. **Cache metrics**: Track cache hit/miss rates
3. **Async invalidation**: Queue cache invalidation for heavy operations
4. **Cache versioning**: Track which ontology version generated each cache
5. **Distributed caching**: Use Redis/Memcached for multi-server setups
