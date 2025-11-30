<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * DTO used to express catalog mutations when running in update/delete mode.
 */
#[Flow\Proxy(false)]
final class CatalogMutation
{
    private string $normalizedIdentifier = '';
    private string $fallbackValue = '';
    private string $sourceValue = '';
    private string $targetValue = '';
    private bool $llmGenerated = false;
    private ?string $llmProviderValue = null;
    private ?string $llmModelValue = null;
    private ?\DateTimeImmutable $llmGeneratedAtValue = null;

    /**
     * @param array<string, string> $placeholders
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $packageKey,
        public readonly string $sourceName,
        string $identifier = '',
        string $fallback = '',
        public array $placeholders = [],
    ) {
        $this->identifier = $identifier;
        $this->fallback = $fallback;
    }

    public string $identifier {
        get => $this->normalizedIdentifier;
        set => $this->normalizedIdentifier = trim((string)$value);
    }

    public string $fallback {
        get => $this->fallbackValue;
        set {
            $value = trim((string)$value);
            $this->fallbackValue = $value;
            if ($this->source === '') {
                $this->source = $value;
            }
            if ($this->target === '') {
                $this->target = $value;
            }
        }
    }

    public string $source {
        get => $this->sourceValue;
        set => $this->sourceValue = (string)$value;
    }

    public string $target {
        get => $this->targetValue;
        set => $this->targetValue = (string)$value;
    }

    public bool $isLlmGenerated {
        get => $this->llmGenerated;
        set => $this->llmGenerated = (bool)$value;
    }

    public ?string $llmProvider {
        get => $this->llmProviderValue;
        set => $this->llmProviderValue = $value === null ? null : trim((string)$value);
    }

    public ?string $llmModel {
        get => $this->llmModelValue;
        set => $this->llmModelValue = $value === null ? null : trim((string)$value);
    }

    public ?\DateTimeImmutable $llmGeneratedAt {
        get => $this->llmGeneratedAtValue;
        set => $this->llmGeneratedAtValue = $value instanceof \DateTimeImmutable ? $value : null;
    }
}
