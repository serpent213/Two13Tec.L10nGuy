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
use PhpLlm\LlmChain\Chain\Chain;
use PhpLlm\LlmChain\Platform\Bridge\Anthropic\Claude;
use PhpLlm\LlmChain\Platform\Bridge\Anthropic\PlatformFactory as AnthropicPlatformFactory;
use PhpLlm\LlmChain\Platform\Bridge\Ollama\Ollama;
use PhpLlm\LlmChain\Platform\Bridge\Ollama\PlatformFactory as OllamaPlatformFactory;
use PhpLlm\LlmChain\Platform\Bridge\OpenAI\GPT;
use PhpLlm\LlmChain\Platform\Bridge\OpenAI\PlatformFactory as OpenAIPlatformFactory;
use Two13Tec\L10nGuy\Llm\Exception\LlmConfigurationException;
use Two13Tec\L10nGuy\Llm\Exception\LlmUnavailableException;

/**
 * Factory that turns configuration into an LLM Chain instance.
 */
#[Flow\Scope('singleton')]
final class LlmProviderFactory
{
    #[Flow\InjectConfiguration(path: 'llm', package: 'Two13Tec.L10nGuy')]
    protected array $config = [];

    /**
     * @throws LlmUnavailableException
     * @throws LlmConfigurationException
     */
    public function create(): Chain
    {
        $this->assertLibraryAvailable();

        $provider = $this->config['provider'] ?? null;
        if ($provider === null || $provider === '') {
            throw new LlmConfigurationException('No LLM provider configured.');
        }

        return match ($provider) {
            'ollama' => $this->createOllama(),
            'openai' => $this->createOpenAI(),
            'anthropic' => $this->createAnthropic(),
            default => throw new LlmConfigurationException(sprintf('Unknown LLM provider: %s', $provider)),
        };
    }

    /**
     * Exposed for metadata and logging.
     */
    public function model(): string
    {
        $model = $this->config['model'] ?? null;
        if ($model === null || $model === '') {
            throw new LlmConfigurationException('No LLM model configured.');
        }

        return (string)$model;
    }

    private function createOllama(): Chain
    {
        $baseUrl = $this->config['base_url'] ?? 'http://localhost:11434';
        $platform = OllamaPlatformFactory::create(baseUrl: $baseUrl);

        return new Chain($platform, new Ollama($this->model()));
    }

    private function createOpenAI(): Chain
    {
        $apiKey = $this->resolveEnvValue((string)($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new LlmConfigurationException('OpenAI API key not configured.');
        }

        $baseUrl = $this->config['base_url'] ?? null;
        $platform = OpenAIPlatformFactory::create(
            apiKey: $apiKey,
            baseUri: $baseUrl ?: null
        );

        return new Chain($platform, new GPT($this->model()));
    }

    private function createAnthropic(): Chain
    {
        $apiKey = $this->resolveEnvValue((string)($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new LlmConfigurationException('Anthropic API key not configured.');
        }

        $platform = AnthropicPlatformFactory::create(apiKey: $apiKey);

        return new Chain($platform, new Claude($this->model()));
    }

    /**
     * Resolves Flow `%env()` syntax when present.
     */
    private function resolveEnvValue(string $value): string
    {
        if (preg_match('/^%env\\(([^)]+)\\)%$/', $value, $matches)) {
            return getenv($matches[1]) ?: '';
        }

        return $value;
    }

    /**
     * @throws LlmUnavailableException
     */
    private function assertLibraryAvailable(): void
    {
        if (!class_exists(Chain::class)) {
            throw new LlmUnavailableException(
                'LLM features require php-llm/llm-chain. Run: composer require php-llm/llm-chain'
            );
        }
    }
}
