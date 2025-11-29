# L10nGuy

Flow CLI companion for keeping Neos translation catalogs in sync with actual source usage.

Scans PHP, Fusion, and YAML files for `I18n.translate()` calls, compares them against XLIFF catalogs, and reports (or fixes) discrepancies.

## Quick Start

```bash
# Find missing translations
./flow l10n:scan --package Two13Tec.Senegal

# Delete unused catalog entries
./flow l10n:unused --package Two13Tec.Senegal

# Enforce consistent XLF formatting
./flow l10n:format --check
```

## Commands

### `l10n:scan`

Detects missing catalog entries and optionally writes them.

```bash
./flow l10n:scan \
  --package Two13Tec.Senegal \    # Limit to one package
  --source Presentation.Cards \   # Limit to one source file
  --path DistributionPackages/... \ # Custom scan root
  --locales de,en \               # Specific locales (default: Flow i18n settings)
  --format table|json \           # Output format
  --update                        # Write missing entries to catalogs
```

**Exit codes**: `0` clean, `5` missing translations, `7` fatal error

When `--update` is set, missing entries are added to the appropriate XLF file with:
- `<source>` containing the fallback text (or identifier if no fallback)
- `<target>` with state `needs-review` (for non-source locales)

### `l10n:unused`

Finds catalog entries with no matching source reference.

```bash
./flow l10n:unused \
  --package Two13Tec.Senegal \
  --locales de,en \
  --format table|json \
  --delete                        # Remove unused entries
```

**Exit codes**: `0` clean, `6` unused entries found, `7` fatal error

### `l10n:format`

Re-renders catalogs with canonical formatting (attribute order, indentation, trailing newline).

```bash
./flow l10n:format \
  --package Two13Tec.Senegal \
  --locales de,en \
  --check                         # Exit non-zero if formatting needed (CI mode)
```

**Exit codes**: `0` clean, `8` formatting needed, `7` fatal error

## Configuration

Settings live in `Configuration/Settings.Flow.yaml` (or context-specific overrides).

```yaml
Two13Tec:
  L10nGuy:
    defaultFormat: 'table'        # 'table' or 'json'

    exitCodes:
      success: 0
      missing: 5
      unused: 6
      failure: 7
      dirty: 8

    filePatterns:
      includes:
        - name: php
          pattern: 'Classes/**/*.php'
          enabled: true
        - name: fusion
          pattern: 'Resources/**/*.fusion'
          enabled: true
        - name: afx
          pattern: 'Resources/**/*.afx'
          enabled: true
        - name: yaml
          pattern: 'Configuration/**/*.yaml'
          enabled: true
        - name: translations
          pattern: 'Resources/Private/Translations/**/*.xlf'
          enabled: true

      excludes:
        - name: node_modules
          pattern: '**/node_modules/**'
          enabled: true
        - name: build
          pattern: 'Build/**'
          enabled: true
```

### Adding Custom Patterns

To scan additional file types (e.g., HTML templates):

```yaml
# Configuration/Settings.Development.yaml
Two13Tec:
  L10nGuy:
    filePatterns:
      includes:
        - name: html
          pattern: 'Resources/Private/Templates/**/*.html'
          enabled: true
```

To disable a pattern without removing it:

```yaml
Two13Tec:
  L10nGuy:
    filePatterns:
      includes:
        - name: yaml
          enabled: false
```

## CI Integration

Add these checks to your pipeline:

```yaml
# GitHub Actions example
- name: Check translations
  run: |
    ./flow l10n:scan --format json > scan-report.json
    ./flow l10n:format --check
```

```bash
# Shell script
set -e
./flow l10n:scan --package Two13Tec.Senegal --format table
./flow l10n:unused --package Two13Tec.Senegal --format table
./flow l10n:format --check
```

The commands exit with distinct codes so you can handle each case:

```bash
./flow l10n:scan || {
  case $? in
    5) echo "Missing translations found" ;;
    7) echo "Scan failed" ;;
  esac
  exit 1
}
```

## Supported Reference Patterns

### PHP

```php
// Neos I18n helper
$this->translator->translateById(
    'button.submit',
    [],
    null,
    null,
    'Forms',
    'Two13Tec.Senegal'
);

// Static shorthand
I18n::translate('Two13Tec.Senegal:Forms:button.submit');
```

### Fusion / AFX

```fusion
// Classic syntax
label = ${I18n.translate('button.submit', 'Submit', {}, 'Forms', 'Two13Tec.Senegal')}

// Fluent syntax
label = ${Translation.id('button.submit').package('Two13Tec.Senegal').source('Forms').value('Submit')}
```

### YAML (NodeTypes)

```yaml
'Two13Tec.Senegal:Content.Card':
  ui:
    label: 'i18n'                # Detected as package:NodeTypes.Content.Card:label
    inspector:
      groups:
        card:
          label: 'i18n'          # Detected as package:NodeTypes.Content.Card:groups.card
```

## Project Structure

```
Two13Tec.L10nGuy/
├── Classes/
│   ├── Command/
│   │   └── L10nCommandController.php     # CLI entry points
│   ├── Domain/Dto/                       # Value objects
│   │   ├── ScanConfiguration.php
│   │   ├── TranslationReference.php
│   │   ├── CatalogEntry.php
│   │   ├── ReferenceIndex.php
│   │   └── CatalogIndex.php
│   ├── Reference/Collector/              # Strategy pattern
│   │   ├── ReferenceCollectorInterface.php
│   │   ├── PhpReferenceCollector.php     # AST-based
│   │   ├── FusionReferenceCollector.php  # Regex/state-machine
│   │   └── YamlReferenceCollector.php    # Line-based
│   └── Service/
│       ├── FileDiscoveryService.php
│       ├── ReferenceIndexBuilder.php
│       ├── CatalogIndexBuilder.php
│       ├── ScanResultBuilder.php
│       └── CatalogWriter.php
├── Configuration/
│   └── Settings.Flow.yaml
├── Tests/
│   ├── Unit/
│   └── Functional/
└── docs/llm/
    ├── flow_i18n_helper.md              # Implementation spec
    └── review.md                        # Code review notes
```

## Development

```bash
cd DistributionPackages/Two13Tec.L10nGuy

# Format code
XDG_CACHE_HOME=$PWD/.cache just format

# Lint (formatting + php -l)
XDG_CACHE_HOME=$PWD/.cache just lint

# Run tests
XDG_CACHE_HOME=$PWD/.cache just test
```

Unit test fixtures live in `Tests/Fixtures/SenegalBaseline/`, mirroring a real package structure.

## Requirements

- PHP 8.4+
- Neos Flow 9.0+ (or compatible Neos CMS distribution)
- Translations in standard Flow paths: `Resources/Private/Translations/<locale>/*.xlf`

## Troubleshooting

**Q: Scan reports missing translations that exist in my XLF file**

Check that:
1. The XLF file path matches Flow conventions (`Resources/Private/Translations/<locale>/`)
2. The `<trans-unit id="...">` matches the identifier exactly
3. The package key in the source reference matches the package containing the XLF

**Q: Placeholder mismatch warnings appear**

The scan compares placeholders in source code (`{name}`, `{count}`) against those in catalog `<source>` and `<target>` elements. Warnings appear when:
- Code references a placeholder not present in the catalog
- Catalog contains placeholders not passed by code

**Q: Format command reports dirty files but content looks identical**

The formatter normalises:
- Attribute order (`original`, `product-name`, `source-language`, `datatype`, `target-language`)
- Indentation (2-space increments)
- Trailing newline

Run `./flow l10n:format` (without `--check`) to apply the canonical format.

## Licence

Open Source Software. See `LICENSE` file.
