# L10nGuy LLM Translation Extension — PRD

## Overview

Extend L10nGuy with LLM-based translation capabilities. When running `l10n:scan --update`, offer automatic generation of:
- **Source texts** (when missing/placeholder)
- **Translations** (for all configured target languages)

Uses [php-llm/llm-chain](https://github.com/php-llm/llm-chain) as the LLM abstraction layer, supporting OpenAI, Anthropic, OpenRouter, and local Ollama through a unified interface.

---

## Goals

1. **Zero-friction translation workflow** — Run scan, get translations, review, ship
2. **Context-aware translations** — Feed the LLM surrounding code, existing translations, and domain context
3. **Provider flexibility** — Support multiple LLM providers via explicit configuration
4. **Quality tracking** — Mark LLM-generated translations for human review
5. **Token transparency** — Dry-run mode with token estimation before API calls
6. **Bulk operations** — Translate entire catalogs to new languages efficiently

## Non-Goals

- Real-time translation during Neos rendering
- Translation memory management (TM/TMX integration)
- Human-in-the-loop approval UI (CLI only for now)

---

## Technical Approach

### Optional Dependency via Composer

```json
{
  "suggest": {
    "php-llm/llm-chain": "Required for LLM-based translation features (^0.x)"
  }
}
```

Runtime detection:
```php
if (!class_exists(\PhpLlm\LlmChain\Chain\Chain::class)) {
    $this->outputLine('! LLM features require php-llm/llm-chain. Run: composer require php-llm/llm-chain');
    return self::EXIT_FAILURE;
}
```

### Provider Configuration

Supported providers: `openai`, `anthropic`, `openrouter`, `ollama`

Provider must be explicitly configured via Settings or CLI options. No auto-detection — if provider config is missing required credentials (API key, base URL), the command fails fast with a clear error message.

### CLI Interface

Extend existing commands + add new ones:

```bash
# Scan with LLM translation (extends existing --update)
./flow l10n:scan --update --llm

# With explicit provider/model
./flow l10n:scan --update --llm --llm-provider=anthropic --llm-model=claude-sonnet-4-20250514

# Dry-run: estimate costs without making API calls
./flow l10n:scan --update --llm --dry-run

# New command: bulk translate to new language
./flow l10n:translate --from=en --to=fr --package=Two13Tec.Senegal

# Copy existing translations from one locale to another (with LLM)
./flow l10n:translate --from=de --to=de_CH --package=Two13Tec.Senegal
```

**New CLI Options:**
| Option | Description | Default |
|--------|-------------|---------|
| `--llm` | Enable LLM-based translation | `false` |
| `--llm-provider` | Provider name (openai, anthropic, openrouter, ollama) | from Settings |
| `--llm-model` | Model identifier | from Settings |
| `--dry-run` | Estimate tokens without API calls | `false` |
| `--batch-size` | Translations per LLM call | from Settings |
| `--from` | Source locale for bulk translation | - |
| `--to` | Target locale for bulk translation | - |

**Shared Filter Options** (available on all l10n commands):
| Option | Description | Supports Glob |
|--------|-------------|---------------|
| `--package` | Package key (e.g., `Two13Tec.Senegal`) | No |
| `--source` | Source name pattern | Yes (`*`) |
| `--path` | Root path for discovery | No |
| `--locales` | Comma-separated locales | No |
| `--id` | Translation ID pattern | Yes (`*`) |

**`--source` glob examples:** `Presentation.*`, `NodeType.Content.*`
**`--id` glob examples:** `hero.*`, `*.label`, `content.hero.headline`

---

## Settings Structure

```yaml
Two13Tec:
  L10nGuy:
    # ... existing settings ...

    llm:
      # Provider configuration (choose one)
      provider: ollama
      model: llama3.2:latest
      base_url: 'http://localhost:11434'

      # provider: openai
      # model: gpt-4o-mini
      # api_key: '%env(OPENAI_API_KEY)%'

      # provider: anthropic
      # model: claude-sonnet-4-5
      # api_key: '%env(ANTHROPIC_API_KEY)%'

      # OpenRouter (uses openai provider type with custom base_url)
      # provider: openai
      # model: meta-llama/llama-3.3-8b-instruct:free
      # api_key: '%env(OPENROUTER_API_KEY)%'
      # base_url: 'https://openrouter.ai/api/v1'

      # Context extraction
      contextWindowLines: 5           # Lines before/after translate() call
      includeNodeTypeContext: true    # Parse full NodeType when reference is from YAML
      includeExistingTranslations: true  # Feed all translations from same file as context

      # Batching
      batchSize: 1                    # Translations per API call (1 = one ID, all languages)
      maxBatchSize: 10                # Upper limit for batch size

      # Quality & metadata
      markAsGenerated: true           # Add 'llm-generated' note to XLF entries
      defaultState: 'needs-review'    # State for LLM-generated entries

      # Rate limiting
      maxTokensPerCall: 4096          # Token limit per API call
      rateLimitDelay: 100             # Milliseconds between API calls

      # Prompting (user-editable, includes domain context and glossary)
      systemPrompt: |
        You are a professional translator for a web application built with Neos CMS.

        Guidelines:
        - Preserve all placeholders exactly: {0}, {name}, %s, etc.
        - Maintain consistent terminology with existing translations provided
        - Match tone and formality level of the application
        - For UI labels: be concise
        - For help text: be clear and helpful
        - Never translate brand names or technical identifiers

        Domain context:
        [Describe your business domain here, e.g.:
        "B2B e-commerce platform for industrial packaging.
        Target audience: procurement managers. Tone: professional, technical."]

        Glossary (enforce these translations):
        [Add key terms here, e.g.:
        - "Dashboard" → DE: "Übersicht", FR: "Tableau de bord"
        - "Settings" → DE: "Einstellungen", FR: "Paramètres"]
```

---

## Context Extraction

### 1. Source File Snippet

For each `TranslationReference`, read surrounding lines from the source file:

```php
// Example: contextWindowLines = 5
// Reference at line 42 → extract lines 37-47

private function extractSourceContext(TranslationReference $ref, int $windowLines): string
{
    $lines = file($ref->file);
    $start = max(0, $ref->line - $windowLines - 1);
    $end = min(count($lines), $ref->line + $windowLines);
    return implode('', array_slice($lines, $start, $end - $start));
}
```

### 2. NodeType Context (when applicable)

When reference comes from `NodeTypes/**/*.yaml`, parse the full NodeType definition:

```yaml
# Extracted context for LLM
'Two13Tec.Senegal:Content.Hero':
  ui:
    label: 'i18n'  # ← this is the translation reference
    help:
      message: 'A hero banner with headline and CTA'
  properties:
    headline:
      type: string
      ui:
        label: 'i18n'
        help: 'Main headline displayed prominently'
```

This gives the LLM semantic understanding: "This is a UI label for a Hero content component."

### 3. Existing Translations from Same File

For a reference in `Presentation/Cards.fusion`, provide all existing translations from `Cards.xlf`:

```json
{
  "existingTranslations": [
    {"id": "card.title", "source": "Card Title", "de": "Kartentitel", "fr": "Titre de la carte"},
    {"id": "card.description", "source": "Description", "de": "Beschreibung", "fr": "Description"},
    // ... helps LLM maintain consistent terminology
  ]
}
```

---

## Batching Strategy

Configurable via `batchSize` setting:

### `batchSize: 1` (Default, Recommended)

One API call per translation ID, producing all target languages:

```json
// Request
{
  "id": "hero.headline",
  "source": "Welcome to our platform",
  "context": "...",
  "targetLanguages": ["de", "fr", "es"]
}

// Response
{
  "id": "hero.headline",
  "translations": {
    "de": "Willkommen auf unserer Plattform",
    "fr": "Bienvenue sur notre plateforme",
    "es": "Bienvenido a nuestra plataforma"
  }
}
```

**Pros:** Consistent interpretation, easy retry on failure
**Cons:** More API calls for large catalogs

### `batchSize: N` (Cost Optimization)

Multiple IDs per call:

```json
// Request
{
  "translations": [
    {"id": "hero.headline", "source": "Welcome", "context": "..."},
    {"id": "hero.cta", "source": "Get Started", "context": "..."},
    {"id": "hero.subtitle", "source": "Your journey begins here", "context": "..."}
  ],
  "targetLanguages": ["de", "fr"]
}

// Response
{
  "translations": [
    {"id": "hero.headline", "de": "Willkommen", "fr": "Bienvenue"},
    {"id": "hero.cta", "de": "Jetzt starten", "fr": "Commencer"},
    {"id": "hero.subtitle", "de": "Ihre Reise beginnt hier", "fr": "Votre voyage commence ici"}
  ]
}
```

**Pros:** Fewer API calls, lower cost
**Cons:** Harder to retry individual failures, potential quality degradation

### Structured Output

Use JSON mode / structured output for reliable parsing:

```php
$response = $chain->call($messages, [
    'response_format' => ['type' => 'json_object'],
]);
```

---

## Quality Metadata in XLF

Mark LLM-generated entries for review workflows:

```xml
<trans-unit id="hero.headline">
  <source>Welcome to our platform</source>
  <target state="needs-review">Willkommen auf unserer Plattform</target>
  <note from="l10nguy" priority="1">llm-generated</note>
  <note from="l10nguy">provider:anthropic model:claude-sonnet-4-20250514 generated:2025-01-15T10:30:00Z</note>
</trans-unit>
```

This enables:
- Filtering in translation tools (filter by `state="needs-review"`)
- Audit trail (which model, when)
- Batch approval workflows

---

## Dry-Run & Token Estimation

Before making API calls, estimate token usage:

```
→ Analysing translation workload...

  Translations to generate:    47 entries × 3 languages = 141 translations
  Estimated input tokens:      ~12,400 (context + prompts)
  Estimated output tokens:     ~4,200
  Batch configuration:         1 ID per call = 47 API calls

? Proceed with LLM translation? [Y/n]
```

Token estimation heuristics:
- Input: count characters in context + source texts, divide by 4
- Output: estimate ~30 tokens per translation (adjust by language)

---

## Bulk Translation Features

### New Language Translation

Translate entire catalog to a new language:

```bash
./flow l10n:translate --to=ja --package=Two13Tec.Senegal
```

Context strategy for bulk:
- No source file context (XLF-to-XLF translation)
- Use ALL existing translations as context (en, de, fr → ja)
- Include `systemPrompt` (with domain context and glossary)
- Process in batches grouped by source file for terminology consistency

**Behaviour**: Skip entries that already have a target translation.

### Copy & Translate Between Locales

For regional variants (de → de_CH, en → en_GB):

```bash
./flow l10n:translate --from=de --to=de_CH --package=Two13Tec.Senegal
```

Prompt includes:
- Source translation (de)
- Target locale description ("Swiss German, formal 'Sie', Swiss terminology")
- Existing de_CH translations for consistency

---

## Prompting Strategy

### System Prompt (Configurable via Settings)

The `systemPrompt` setting is fully user-editable and should include domain context and glossary terms. See the Settings Structure section for the default template.

### Per-Translation Prompt

```
Translate the following text from {sourceLanguage} to {targetLanguages}.

Source text: "{sourceText}"

Context (surrounding code):
```
{sourceContext}
```

Existing translations in this file:
{existingTranslations}

Respond in JSON format:
{
  "translations": {
    "de": "...",
    "fr": "...",
    "es": "..."
  }
}
```

---

## Error Handling

**Fail-fast approach**: If the configured provider fails, the command exits with an error. No automatic fallback to alternative providers.

| Error | Handling |
|-------|----------|
| Missing provider config | Exit with error, show configuration help |
| API authentication failure | Exit with error, check credentials |
| API rate limit | Exponential backoff, respect `Retry-After` header |
| API timeout | Retry up to 3 times with increasing timeout, then fail |
| Invalid response (not JSON) | Log warning, skip entry, continue |
| Missing translation in response | Log warning, mark as failed, continue |
| Placeholder mismatch | Validate post-translation, warn if placeholders differ |

Failed translations logged to `Data/Logs/L10nGuy_LLM.log`:
```
[2025-01-15 10:30:45] WARNING: Translation failed for hero.headline (de): API timeout after 3 retries
[2025-01-15 10:30:46] INFO: Skipping hero.headline, continuing with next entry
```

---

## Implementation Phases

### Phase 1: Core Infrastructure
- [ ] Add php-llm/llm-chain as suggested dependency
- [ ] Create `LlmProviderFactory` for explicit provider instantiation (no auto-detection)
- [ ] Add Settings structure under `llm` key
- [ ] Add `--llm`, `--llm-provider`, `--llm-model` CLI options
- [ ] Add `--id` filter option with glob pattern support

### Phase 2: Context Extraction
- [ ] Implement `SourceContextExtractor` for line snippets
- [ ] Extend `YamlReferenceCollector` to capture full NodeType context
- [ ] Create `TranslationContextBuilder` to aggregate all context types

### Phase 3: Translation Engine
- [ ] Create `LlmTranslationService` with prompt building
- [ ] Implement configurable batching
- [ ] Add JSON structured output handling
- [ ] Integrate with `CatalogMutationFactory`

### Phase 4: Quality & Metadata
- [ ] Extend `CatalogWriter` to add LLM metadata notes
- [ ] Add post-translation placeholder validation
- [ ] Implement `state="needs-review"` for generated entries

### Phase 5: Token Estimation
- [ ] Implement `--dry-run` with token estimation
- [ ] Add rate limiting between API calls

### Phase 6: Bulk Operations
- [ ] Add `l10n:translate` command (shares filter options with other commands)
- [ ] Implement `--from`/`--to` locale translation
- [ ] Skip existing translations by default
- [ ] Add progress reporting for large catalogs

