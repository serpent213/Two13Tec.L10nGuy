# Flow i18n Helper – Implementation Brief

The `Two13Tec.L10nGuy` package ships a Flow CLI companion that detects missing or stale translations and keeps XLF catalogs in sync. This brief turns the early PRD into actionable engineering notes that are grounded in real data from the running site package `DistributionPackages/Two13Tec.Senegal`. The helper targets PHP 8.4 and should lean on promoted/readonly properties, typed class constants, property hooks, and first-class callable syntax wherever it keeps the code expressive.

## Why we are building it
- **Regression proofing** – stop regressions where new `I18n.translate()` calls never reach XLF catalogs.
- **Automation** – Flow projects already expose the locale configuration via `Neos.Flow.i18n.*`; the helper merely orchestrates scanning, diffing, and (optionally) writing XLF files.
- **Ground truth** – reusable fixtures mirror `Two13Tec.Senegal`, ensuring scanners understand real Fusion, YAML, and XLF constructs instead of contrived samples.

## Real data baseline (Two13Tec.Senegal)
`DistributionPackages/Two13Tec.Senegal` contains every scenario our helper must cover:

| Path | Type | Reference | Notes |
| --- | --- | --- | --- |
| `Resources/Private/Fusion/Presentation/Cards/Card.fusion:82` | Fusion AFX | `{I18n.translate('cards.authorPublishedBy', 'Published by {authorName}', { authorName: props.authorName }, 'Presentation.Cards:cards', 'Two13Tec.Senegal')}` | Uses inline fallback and placeholders; catalog lives in `Resources/Private/Translations/*/Presentation/Cards.xlf`. |
| `Resources/Private/Fusion/Presentation/YouTube.fusion:24` | Fusion | `I18n.translate('Two13Tec.Senegal:NodeTypes.Content.YouTube:error.no.videoid')` | Demonstrates fully qualified signature (package + source embedded in id). |
| `Resources/Private/Translations/en/Presentation/Cards.xlf` | XLF | `<trans-unit id="cards.authorPublishedBy">` | English catalog with `product-name="Two13Tec.Senegal"`; the `de` locale mirrors structure. |
| `NodeTypes/Content/ContactForm/ContactForm.yaml` | YAML | `label: i18n` under multiple properties | NodeType UI metadata referencing `NodeTypes/Content/ContactForm` catalogs. |
| `Configuration/Settings.Flow.yaml` | YAML | Default locale `de`, fallback `en` | Used to seed CLI default locale list. |
| `Resources/Private/Translations/de/NodeTypes/Document/Page.xlf` | XLF | 40+ `trans-unit` nodes | Ensures the helper handles non-default locales and nested directory layouts. |

Any fixtures or reference data we create in `Two13Tec.L10nGuy/Tests/Fixtures` should be trimmed copies of the files above, preserving IDs, nesting, and metadata (product-name, source paths, placeholders). Keep translation IDs identical so CLI output matches production.

## Command surface
| Command | Behavior | Exit codes |
| --- | --- | --- |
| `./flow l10n:scan` | Builds a reference index from PHP, Fusion/AFX, and YAML files, compares it against the catalog index per locale, prints a table/json report, and fails with code `5` when missing translations exist (unless `--silent`). | `0` = clean, `5` = missing units, `7` = scanner failure. |
| `./flow l10n:scan --update` (alias `l10n:merge`) | Performs the scan and writes missing `<trans-unit>` skeletons into the relevant XLF files. Uses Flow’s `XliffFileProvider` for locale-aware read/write and formats XML with 2-space indentation. | `0` when every update succeeds, non-zero if a catalog was locked or invalid. |
| `./flow l10n:unused` | Loads the reference index (same code path as `scan`), then lists catalog entries not referenced anywhere. Supports `--delete` to remove entries in-place (defaults to dry-run). | `0` = clean, `6` = unused entries reported, `7` = runtime failure. |

Shared options:
- `--package`, `--source`, `--path` limit scanning scope (default package = `Two13Tec.L10nGuy` to keep runtime tight).
- `--locales=de,en` overrides locale list; otherwise we read `Neos.Flow.i18n.defaultLocale` (in Senegal: `de`) and append the fallback chain.
- `--format=table|json` toggles CLI table vs JSON. Table rendering uses `initphp/cli-table` with muted borders. JSON is canonical for CI.
- `--dry-run` defaults to `true` for every command that would touch disk; passing `--update` flips it to `false` unless explicitly set.

## Implementation building blocks

### File discovery
- Configure defaults under `Neos.Flow.i18n.helper.filePatterns` with `includes` covering `Classes/**/*.php`, `Resources/**/*.fusion`, `Resources/**/*.afx`, `Configuration/**/*.yaml`, and `Resources/Private/Translations/**/*.xlf`.
- Patterns follow `{ name, pattern, enabled = true, priority = 100 }` to allow downstream overrides.
- Use `Neos\Utility\Files::readDirectoryRecursively()` to gather files, then filter with `flow.utility.files::getMatchingFiles()` while honoring excludes.

### PHP scanner
The collector uses `nikic/php-parser` in tolerant mode and embraces PHP 8.4 idioms:

```php
<?php
declare(strict_types=1);

namespace Two13Tec\L10nGuy\Scanner;

use Neos\Flow\I18n\Translator;
use PhpParser\Node;
use PhpParser\ParserFactory;

final readonly class PhpReferenceCollector
{
    public function __construct(
        private Translator $translator,
        private ParserFactory $parserFactory = new ParserFactory(),
    ) {}

    public function collect(\SplFileInfo $file): iterable
    {
        $stmts = $this->parserFactory
            ->createForNewestSupportedVersion()
            ->parse($file->getContents());
        // Walk AST, detect translateById, TranslationHelper::translate, etc.
    }
}
```

Whenever we need normalized identifiers we can use property hooks (PHP 8.4) on dedicated DTOs:

```php
final class CatalogMutation
{
    private string $normalizedId = '';

    public string $id {
        get => $this->normalizedId;
        set => $this->normalizedId = trim($value);
    }
}
```

### Fusion / AFX scanner
- Stick with regex heuristics because `Neos\Fusion\Core\Parser` is `@internal`.
- Handle both `I18n.translate('cards.authorPublishedBy', ...)` and fluent usage like `Translation.id('foo').package('Two13Tec.Senegal').source('Presentation.Cards')`.
- Emit references with `{packageKey, source, id, fallback, path, context = fusion}` and capture placeholder arguments so we can emit diagnostics when placeholders mismatch.

### YAML scanner
- Parse via Symfony YAML (bundled with Flow) and traverse keys with `i18n` values.
- For NodeType definitions derive the translation source from the file path (e.g., `NodeTypes/Content/ContactForm/ContactForm.yaml` → `NodeTypes.Content.ContactForm`) and use the property name for the translation id to mirror Neos UI behavior.

### Catalog index / writers
- Lean on `Neos\Flow\I18n\Xliff\Service\XliffFileProvider` to hydrate `LocalizedXliffModel` instances.
- Build a `ReferenceIndex` dictionary: `package => source => id => TranslationReference`.
- Build a `CatalogIndex`: `locale => package => source => id => CatalogEntry`.
- When writing, group missing entries by locale + package + source to limit file writes. All writes stay inside Flow’s resource folder to avoid collisions with `Data/Temporary`.
- Respect `dry-run` at the highest level so CLI invocations cannot mutate files unexpectedly in CI.

## Fixtures derived from Senegal

Create `DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline` with trimmed files:

1. `Resources/Private/Fusion/Presentation/Cards/Card.fusion` – copy the block around line 70–95 to cover `cards.authorPublishedBy`.
2. `Resources/Private/Fusion/Presentation/YouTube.fusion` – lines 16–28 include an error translation for missing video IDs.
3. `NodeTypes/Content/ContactForm/ContactForm.yaml` – keep the header and at least two properties with `label: i18n`.
4. `Configuration/Settings.Flow.yaml` – copy the `defaultLocale` block (`de` with fallback `en`).
5. `Resources/Private/Translations/en/Presentation/Cards.xlf` – include the `<trans-unit>` nodes for `cards.authorPublishedBy` and `cards.moreButton`.
6. `Resources/Private/Translations/de/Presentation/Cards.xlf` – same as above but with German source text to ensure locale switching works.

Functional tests can then assert:
- `l10n:scan` spots `cards.authorPublishedBy` when missing from the English catalog.
- `l10n:scan --update --locales=de,en` writes both locales.
- `l10n:unused` reports extra units like `cards.moreButton` when no reference exists.

## Example workflows
1. **Developer** adds `{I18n.translate('cards.authorPublishedBy', ...)}` to a new Fusion prototype. Running `./flow l10n:scan` prints a table showing `cards.authorPublishedBy` missing from `Presentation/Cards.xlf` for locales `de` and `en`. Exiting with code `5` fails CI.
2. **Localization manager** runs `./flow l10n:scan --update --locales=de,en` after merging. Output lists files touched (e.g., `Added 1 trans-unit to DistributionPackages/Two13Tec.Senegal/Resources/Private/Translations/en/Presentation/Cards.xlf`). New `<trans-unit>` entries contain the fallback string as both `source` and `target`.
3. **Maintainer** runs `./flow l10n:unused --package Two13Tec.Senegal --format=json` before a release. JSON output shows `cards.moreButton` is unused, referencing its locale + file path so the maintainer can delete or ignore it.

## Diagnostics & reporting
- Missing placeholders trigger warnings (e.g., translation uses `{authorName}` but the Fusion call omits it).
- Duplicate translation ids in the reference index increment a `duplicates` counter surfaced in the CLI table (and JSON payload).
- Provide actionable suggestions when catalogs cannot be parsed (e.g., `Check XML near line 11: mismatched closing tag`).

## Appendix – Data structures
- `TranslationReference` – `readonly class` with promoted constructor properties (`public function __construct(public string $packageKey, ...) {}`).
- `ReferenceIndex` – `array<string, array<string, array<string, TranslationReference|array<int, TranslationReference>>>>`.
- `CatalogEntry` – `readonly` DTO storing locale, file path, id, source, target, state.
- `CatalogMutation` – described above; uses PHP 8.4 property hooks to normalize ids before writing.

These structures, paired with fixtures that mirror `Two13Tec.Senegal`, ensure the Flow i18n helper remains grounded in authentic content while embracing modern PHP 8.4 conventions.
