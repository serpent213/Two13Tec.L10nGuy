# Plural Support Implementation Plan

## Scope / Goal
- Allow L10nGuy to read, index, diff, and write XLIFF plural groups (`<group restype="x-gettext-plurals">` with `<trans-unit id="foo[0]">…</trans-unit>` children).
- Keep compatibility with existing single-form handling and Flow’s translator expectations (references use the base id `foo`; catalog units carry indexed ids `foo[0]`, `foo[1]`, …).
- Preserve deterministic formatting and ordering when re-rendering catalogs; avoid data loss when plural groups already exist.

## Current State / Gaps
- `CatalogFileParser` preserves `<group>` nodes (and any other unknown elements/attributes) instead of throwing, but still surfaces them as opaque structures.
- Parsed units are a flat `id => {source,target,state}` map; no grouping metadata.
- `CatalogIndexBuilder` only sees flat units; `ScanResultBuilder` matches references by exact id. A reference to `contentcollection.label` currently cannot match catalog entries `contentcollection.label[0]`.
- `CatalogWriter` renders only `<trans-unit>`; no group rendering, no plural-aware mutations.
- Tests enforce rejection of group nodes; no fixtures with plurals.
- Reference collectors (PHP/Fusion/YAML) already detect plural ids (e.g., `contentcollection.label`) and capture quantity arguments, but there is no special handling after collection.

## Target Representation
- Parser output should distinguish singles vs plurals while retaining child ids:
  ```php
  'units' => [
    'simple.id' => [
      'type' => 'single',
      'source' => '…',
      'target' => '…',
      'state' => '…',
    ],
    'contentcollection.label' => [
      'type' => 'plural',
      'restype' => 'x-gettext-plurals',
      'forms' => [
        0 => ['id' => 'contentcollection.label[0]', 'source' => '…', 'target' => '…', 'state' => '…'],
        1 => ['id' => 'contentcollection.label[1]', …],
      ],
    ],
  ];
  ```
- Carry through unknown group attributes? Preserve everything as parsed so we never lose data while rendering.
- Maintain child ids verbatim for Flow’s translator (`…[n]`) while exposing the base id for matching references.

## Parsing Plan
- Stop throwing on `<body/group>`.
- Detect `<group restype="x-gettext-plurals">` and collect child `<trans-unit>` nodes; derive the base id from the group `id` attribute (preferred) and fall back to stripping `[n]` suffixes from child ids when the group id is missing.
- Preserve existing parsing for top-level `<trans-unit>` as singles.
- Namespace handling remains the same (strip default xmlns for XPath).
- Ensure ordering of plural forms is stable (numeric sort of indices).

## Indexing Plan (`CatalogIndexBuilder` + `CatalogIndex`)
- When registering catalog files, store parsed plural metadata alongside singles.
- Add plural-aware entry insertion:
  - For `type=plural`, add `CatalogEntry` per child id (`foo[0]`, `foo[1]`, …) to keep compatibility with Flow’s `XliffFileProvider` data (which exposes child ids).
  - Maintain a secondary map `pluralGroups[locale][package][source][baseId] = list<childIds>` so lookups by base id can resolve plurals.
- Update accessors:
  - `entriesFor(locale, package, source)` should include plural child entries (same as today) but also allow matching by base id in downstream code.
  - Expose a helper `pluralGroup(locale, package, source, baseId)` returning the set of forms.
- `ScanResultBuilder` change (later step): when a reference id `foo` is missing as a single, check `pluralGroup` and consider the group present if all forms exist; otherwise, flag missing forms or missing group.

## Writer Plan (`CatalogWriter`)
- Accept mixed unit structures (singles + plurals). Rendering rules:
  - Sort by base identifier naturally; render single trans-units as before.
  - For plural bundles, render a `<group id="baseId" restype="x-gettext-plurals">` block with child `<trans-unit id="…[n]">` entries in numeric order.
  - Avoid double-rendering: if a plural bundle is present, do not emit its child ids as separate top-level units.
- Mutations:
  - When a mutation id looks like `foo[1]`, treat it as plural form of base `foo`; add to existing group or create a new plural group with `restype="x-gettext-plurals"`.
  - When a mutation id has no `[n]` but other forms exist, decide on behavior: prefer creating `[0]` as the first form with the base id, logging? (to be clarified in implementation).
  - Preserve state/target decisions as today (`needs-review` for written targets).
- Reformatting should round-trip existing plural groups without changing ordering/indentation beyond deterministic formatting.

## Reference Mapping / Detection
- Reference collectors remain unchanged (they already capture the base id and quantity argument when present).
- Matching logic change (in `ScanResultBuilder` or a helper):
  - When finding a catalog entry for a reference id `foo`, first try exact match (`foo`). If not found, look for a plural group `foo` and ensure at least one form exists. Treat the group as present, possibly surface missing forms as warnings (out of scope for first pass).
  - Placeholder comparison should aggregate placeholders across all plural forms (source + target) to avoid false positives.

## Testing Plan
- Replace the existing parser failure test with positive plural parsing coverage.
- Add fixtures (e.g., Carousel from Neos.Demo) under `Tests/Fixtures/SenegalBaseline` for plural catalogs.
- Unit tests:
  - Parser: plural group is parsed with base id, forms collected, singles unaffected.
  - CatalogIndexBuilder: plural child ids become entries; plural group lookup works.
  - CatalogWriter: round-trips a catalog containing plural + single units; mutations adding a new plural form create/extend the group; deterministic formatting.
- Functional/command tests (if feasible): scan detects plural references as present when catalogs have plural groups; missing translations for plurals are reported correctly when forms are absent.

## Open Decisions / Assumptions
- Default behavior when a mutation lacks `[n]` but targets a plural id: initial implementation can treat it as `[0]` (singular) and create a group; flagging missing forms could be a follow-up.
- Only `restype="x-gettext-plurals"` is supported; other group types remain opaque but are preserved during re-rendering.
- Writer preserves unknown group attributes and elements so we do not discard user-provided data.

## Work Breakdown (for upcoming implementation steps)
1) Update `CatalogFileParser` to parse plural groups into the new structure and stop throwing on groups; adjust unit tests.
2) Extend `CatalogIndex`/`CatalogIndexBuilder` to store plural metadata and provide lookup helpers.
3) Make `ScanResultBuilder` plural-aware for presence and placeholder checks.
4) Enhance `CatalogWriter` to render and mutate plural groups deterministically.
5) Add/refresh fixtures and tests (parser, writer, index, possibly command-level).
