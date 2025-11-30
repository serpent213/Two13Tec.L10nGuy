<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Llm;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Llm\SourceContextExtractor;
use Two13Tec\L10nGuy\Llm\TranslationContextBuilder;

/**
 * @covers \Two13Tec\L10nGuy\Llm\TranslationContextBuilder
 */
final class TranslationContextBuilderTest extends TestCase
{
    /**
     * @test
     */
    public function buildsContextWithSourceSnippetAndExistingTranslations(): void
    {
        $catalogIndex = new CatalogIndex();
        $catalogIndex->addEntry(new CatalogEntry(
            locale: 'en',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.moreButton',
            filePath: '/tmp/en.xlf',
            source: 'More',
            target: 'More'
        ));
        $catalogIndex->addEntry(new CatalogEntry(
            locale: 'de',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.moreButton',
            filePath: '/tmp/de.xlf',
            source: 'More',
            target: 'Mehr'
        ));
        $catalogIndex->addEntry(new CatalogEntry(
            locale: 'de',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.placeholderWarning',
            filePath: '/tmp/de.xlf',
            source: 'Placeholder {name}',
            target: 'Platzhalter {name}'
        ));
        $catalogIndex->addEntry(new CatalogEntry(
            locale: 'de',
            packageKey: 'Other.Package',
            sourceName: 'Other.Source',
            identifier: 'other.id',
            filePath: '/tmp/other.xlf',
            source: 'Other',
            target: 'Andere'
        ));

        $reference = new TranslationReference(
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.authorPublishedBy',
            context: TranslationReference::CONTEXT_PHP,
            filePath: $this->fixturePath('Classes/Presentation/CardComponent.php'),
            lineNumber: 28,
        );
        $missing = new MissingTranslation(
            locale: 'fr',
            key: new TranslationKey('Two13Tec.Senegal', 'Presentation.Cards', 'cards.authorPublishedBy'),
            reference: $reference
        );

        $builder = new TranslationContextBuilder(new SourceContextExtractor());
        $context = $builder->build(
            $missing,
            $catalogIndex,
            new LlmConfiguration(
                enabled: true,
                contextWindowLines: 1,
                includeExistingTranslations: true,
                includeNodeTypeContext: true
            )
        );

        self::assertNotNull($context->sourceSnippet);
        self::assertStringContainsString('I18n::translate', $context->sourceSnippet);
        self::assertNull($context->nodeTypeContext);
        self::assertSame([
            [
                'id' => 'cards.moreButton',
                'source' => 'More',
                'translations' => [
                    'de' => 'Mehr',
                    'en' => 'More',
                ],
            ],
            [
                'id' => 'cards.placeholderWarning',
                'source' => 'Placeholder {name}',
                'translations' => [
                    'de' => 'Platzhalter {name}',
                ],
            ],
        ], $context->existingTranslations);
    }

    /**
     * @test
     */
    public function prefersNodeTypeContextFromReference(): void
    {
        $reference = new TranslationReference(
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'NodeTypes.Content.ContactForm',
            identifier: 'ui.label',
            context: TranslationReference::CONTEXT_YAML,
            filePath: '/does/not/matter.yaml',
            lineNumber: 3,
            nodeTypeContext: "Two13Tec.Senegal:Content.ContactForm:\n  ui:\n    label: i18n\n"
        );
        $missing = new MissingTranslation(
            locale: 'de',
            key: new TranslationKey('Two13Tec.Senegal', 'NodeTypes.Content.ContactForm', 'ui.label'),
            reference: $reference
        );

        $builder = new TranslationContextBuilder(new SourceContextExtractor());
        $context = $builder->build(
            $missing,
            new CatalogIndex(),
            new LlmConfiguration(
                includeExistingTranslations: false,
                includeNodeTypeContext: true,
                contextWindowLines: 0
            )
        );

        self::assertNull($context->sourceSnippet);
        self::assertSame($reference->nodeTypeContext, $context->nodeTypeContext);
        self::assertSame([], $context->existingTranslations);
    }

    private function fixturePath(string $relative): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/' . $relative;
    }
}
