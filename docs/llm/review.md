# L10nGuy Code Review

Pre-release review focusing on consistency, simplicity, and robustness.

## Executive Summary

L10nGuy is solid work—clean DTOs, sensible service separation, and a strategy pattern for collectors that'll age well. The codebase follows Flow conventions and maintains consistent style throughout. However, the nested loop structures across several services create cognitive overhead and make the code harder to reason about. A handful of refactorings would tighten things up before leaving alpha.

**Verdict**: Ready for beta with the suggested improvements.

---

## Architecture Strengths

1. **Clean value objects** — DTOs are readonly with `#[Flow\Proxy(false)]`, preventing accidental mutations and Flow proxying overhead.

2. **Strategy pattern for collectors** — `ReferenceCollectorInterface` allows new source types (e.g., Twig, Latte) without touching core logic.

3. **Deterministic output** — Catalog rendering uses consistent attribute ordering and natural sorting, enabling reliable CI diffs.

4. **Explicit dependency injection** — Constructor promotion with typed properties; no hidden Flow magic.

5. **Comprehensive exit codes** — Configurable per context, so CI pipelines can distinguish failure modes.

---

## Issues & Refactoring Suggestions

### 1. The Nested Loop Problem

The codebase has several instances of 3-4 level nested `foreach` loops that are difficult to follow. The primary culprits:

| File | Method | Depth |
|------|--------|-------|
| `ScanResultBuilder.php:46-98` | `build()` | 4 levels |
| `L10nCommandController.php:488-517` | `findUnusedEntries()` | 4 levels |
| `L10nCommandController.php:570-594` | `summarizeReferenceDuplicates()` | 3 levels |
| `ReferenceIndex.php:84-106` | `uniqueCount()` / `duplicateCount()` | 3 levels |
| `CatalogIndex.php:129-146` | `catalogList()` | 3 levels |

**Suggested Pattern**: Extract the inner loop body into a generator or a dedicated iterator method. Here's a concrete example for `ScanResultBuilder`:

```php
// Before (ScanResultBuilder.php:46-98)
foreach ($referenceIndex->references() as $packageKey => $sources) {
    if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
        continue;
    }
    foreach ($sources as $sourceName => $identifiers) {
        if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
            continue;
        }
        foreach ($identifiers as $identifier => $reference) {
            // ... 30 lines of logic
        }
    }
}

// After — flat iteration with generator
private function iterateFilteredReferences(
    ReferenceIndex $referenceIndex,
    ScanConfiguration $configuration
): iterable {
    foreach ($referenceIndex->references() as $packageKey => $sources) {
        if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
            continue;
        }
        foreach ($sources as $sourceName => $identifiers) {
            if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                continue;
            }
            foreach ($identifiers as $identifier => $reference) {
                yield new ReferenceContext($packageKey, $sourceName, $identifier, $reference);
            }
        }
    }
}

public function build(...): ScanResult
{
    // ...
    foreach ($this->iterateFilteredReferences($referenceIndex, $configuration) as $ctx) {
        $this->processReference($ctx, $locales, $catalogIndex, $missing, $placeholderMismatches);
    }
    // ...
}
```

This pattern:
- Separates **filtering** from **processing**
- Makes the main loop body testable in isolation
- Reduces indentation from 4 levels to 1

The same approach applies to `findUnusedEntries()`, `summarizeReferenceDuplicates()`, and the index counting methods.

---

### 2. Duplicated Utility Methods

Three methods are copy-pasted across files:

| Method | Locations |
|--------|-----------|
| `isAbsolutePath()` | `ReferenceIndexBuilder:101`, `CatalogIndexBuilder:243`, `CatalogWriter:335` |
| `resolveRoots()` | `ReferenceIndexBuilder:74-99`, `CatalogIndexBuilder:216-241` |

**Suggestion**: Extract to a shared utility or trait.

```php
// Option A: Static utility (Flow convention for stateless helpers)
namespace Two13Tec\L10nGuy\Utility;

final class PathResolver
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    /**
     * @return list<array{base: string, paths: list<string>}>
     */
    public static function resolveRoots(ScanConfiguration $configuration, string $basePath): array
    {
        // ... shared implementation
    }
}

// Option B: Trait for index builders (if they share more behaviour)
trait ResolvesSearchRoots
{
    abstract protected function getDefaultPath(ScanConfiguration $configuration): string;

    private function resolveRoots(ScanConfiguration $configuration, string $basePath): array
    {
        // ...
    }
}
```

---

### 3. Command Controller Is Doing Too Much

`L10nCommandController.php` is 645 lines and handles:
- Configuration orchestration
- Index building
- Result rendering (table + JSON)
- Mutation building
- Exit code resolution
- Logging
- Path formatting

The controller has 8 injected dependencies—a mild smell.

**Suggestion**: Extract rendering and reporting to dedicated services.

```
L10nCommandController (orchestration only)
├── ScanReportRenderer (table + JSON output, placeholder warnings)
├── UnusedReportRenderer (table + JSON output)
└── ExitCodeResolver (configurable exit code logic)
```

This keeps the controller under 200 lines and makes the rendering testable without invoking Flow's CLI infrastructure.

---

### 4. Index Classes Have Growing Responsibilities

`ReferenceIndex` and `CatalogIndex` are mutable aggregates that also compute derived values (`uniqueCount()`, `duplicateCount()`, `catalogList()`). As features grow, these classes risk becoming god objects.

**Suggestion**: Consider separating the aggregate from its queries.

```php
// Aggregate remains focused on mutation
final class ReferenceIndex
{
    public function add(TranslationReference $reference): void { ... }
    public function references(): array { ... }
    public function duplicates(): array { ... }
}

// Queries extracted to a reader (or kept as static helpers)
final class ReferenceIndexQueries
{
    public static function uniqueCount(ReferenceIndex $index): int { ... }
    public static function duplicateCount(ReferenceIndex $index): int { ... }
    public static function allFor(ReferenceIndex $index, string $pkg, string $src, string $id): array { ... }
}
```

This is optional polish—the current design works, but the counting loops would be cleaner as a single `array_reduce` or generator-based calculation.

---

### 5. Missing Type Annotations

A few spots lack full PHPDoc or use `array` where a shape would help static analysis:

| Location | Issue |
|----------|-------|
| `CatalogIndex::sources(): array` | Return type should be `array<string, list<string>>` |
| `CatalogWriter::resolveMetadata()` | Param `$meta` is `array`, should specify shape |
| `L10nCommandController::$exitCodes` | Should be `array{success: int, missing: int, ...}` |

These are minor but help PHPStan/Psalm and IDE autocompletion.

---

### 6. FusionReferenceCollector Complexity

At ~390 lines, `FusionReferenceCollector` is the largest collector. It implements a mini state-machine for parsing Fusion syntax. This is pragmatic given Fusion's complexity, but:

- The `splitArguments()` method tracks 3 depth counters and a string state manually
- Error recovery is implicit (returns empty on malformed input)

**Suggestion**:
- Add inline comments explaining the state machine transitions
- Consider extracting the argument splitter to a `FusionArgumentParser` utility if it grows further
- Add test cases for edge cases (nested braces, escaped quotes)

---

### 7. Test Coverage Gaps

The test suite covers happy paths well. Missing coverage:

| Scenario | Current State |
|----------|---------------|
| Malformed XLF files | Handled gracefully (logs error) but not explicitly tested |
| Permission errors on write | Not tested |
| Empty catalog files | Not tested |
| Circular reference detection | N/A (not a feature, but could be a footgun) |
| Unicode in placeholder names | Not tested |

**Suggestion**: Add negative test cases, especially for the `CatalogFileParser` and `CatalogWriter`.

---

### 8. Configuration Schema

The `Settings.Flow.yaml` file pattern config works, but:

- `priority` field is defined but not actually used (patterns are applied in order)
- No validation that patterns are valid globs

**Suggestion**: Either use priority for conflict resolution or remove the field to avoid confusion.

---

## Minor Nits

1. **Inconsistent null checks**: Some methods use `$value === null`, others use `empty($value)`. Pick one style (prefer strict null checks).

2. **Magic strings**: Exit code keys (`'success'`, `'missing'`) appear as strings. Consider an enum or constants.

3. **`seedFromConfiguration()` is a stub**: The method exists "for future phases" but currently only validates paths. Either implement or remove the TODO.

4. **`$length` unused**: In `FusionReferenceCollector::collectI18nTranslateCalls()`, `$length` is assigned but never used.

---

## Recommended Refactoring Priority

1. **High**: Extract `isAbsolutePath()` and `resolveRoots()` to shared utility
2. **High**: Flatten `ScanResultBuilder::build()` using generator pattern
3. **Medium**: Extract rendering logic from command controller
4. **Medium**: Add negative test cases for parser edge cases
5. **Low**: Tighten type annotations for static analysis
6. **Low**: Clean up unused variables and stubs

---

## Files Changed Summary

```
Classes/
├── Command/L10nCommandController.php      # Consider splitting
├── Domain/Dto/
│   ├── ReferenceIndex.php                 # Minor: flatten counting
│   └── CatalogIndex.php                   # Minor: type annotations
├── Reference/Collector/
│   └── FusionReferenceCollector.php       # Minor: document state machine
├── Service/
│   ├── ScanResultBuilder.php              # Refactor: flatten loops
│   ├── ReferenceIndexBuilder.php          # Extract: shared utilities
│   ├── CatalogIndexBuilder.php            # Extract: shared utilities
│   └── CatalogWriter.php                  # Extract: shared utilities
└── Utility/                               # New: PathResolver
```

---

## Conclusion

L10nGuy is well-architected for a CLI tool of this scope. The main friction points are the deeply nested loops and some code duplication—both fixable without breaking changes. The suggested refactorings would reduce cognitive load and make the codebase more maintainable as it matures.

The test suite is solid, the DTOs are clean, and the strategy pattern for collectors shows good foresight. Ship it after addressing the high-priority items above.

---

*Review conducted 2025-11-29*
