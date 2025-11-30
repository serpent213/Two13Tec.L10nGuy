<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Llm;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Validates that translations preserve expected placeholders.
 */
#[Flow\Scope('singleton')]
final class PlaceholderValidator
{
    #[Flow\Inject]
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param list<string> $expectedPlaceholders
     */
    public function validate(string $identifier, string $locale, string $translation, array $expectedPlaceholders): bool
    {
        if ($expectedPlaceholders === []) {
            return true;
        }

        $found = $this->extractPlaceholders($translation);
        $missing = array_values(array_diff($expectedPlaceholders, $found));

        if ($missing !== []) {
            $this->logger->warning('LLM translation missing placeholders', [
                'identifier' => $identifier,
                'locale' => $locale,
                'missing' => $missing,
                'expected' => $expectedPlaceholders,
                'found' => $found,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $translation): array
    {
        preg_match_all('/\{([A-Za-z0-9_.:-]+)\}/', $translation, $matches);

        $placeholders = array_filter(
            $matches[1] ?? [],
            static fn ($placeholder): bool => $placeholder !== ''
        );

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);

        return $placeholders;
    }
}
