<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Reference\Collector;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use SplFileInfo;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Reference\TranslationMetadataResolver;

/**
 * AST-based collector for PHP sources.
 */
final class PhpReferenceCollector implements ReferenceCollectorInterface
{
    private Parser $parser;
    private NodeFinder $nodeFinder;
    private PrettyPrinter $prettyPrinter;

    public function __construct(?Parser $parser = null, ?NodeFinder $nodeFinder = null, ?PrettyPrinter $prettyPrinter = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = $nodeFinder ?? new NodeFinder();
        $this->prettyPrinter = $prettyPrinter ?? new PrettyPrinter();
    }

    public function supports(SplFileInfo $file): bool
    {
        return str_ends_with(strtolower($file->getFilename()), '.php');
    }

    public function collect(SplFileInfo $file): array
    {
        if (!$this->supports($file)) {
            return [];
        }

        $code = @file_get_contents($file->getPathname());
        if ($code === false) {
            return [];
        }

        try {
            $statements = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $references = [];
        $callNodes = $this->nodeFinder->find($statements, static function (Node $node): bool {
            return $node instanceof StaticCall || $node instanceof MethodCall;
        });

        foreach ($callNodes as $callNode) {
            $reference = $this->buildReferenceFromCall($callNode, $file->getPathname());
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    private function buildReferenceFromCall(Node $node, string $filePath): ?TranslationReference
    {
        if ($node instanceof StaticCall) {
            return $this->buildFromStaticCall($node, $filePath);
        }

        if ($node instanceof MethodCall) {
            return $this->buildFromMethodCall($node, $filePath);
        }

        return null;
    }

    private function buildFromStaticCall(StaticCall $call, string $filePath): ?TranslationReference
    {
        $className = $call->class instanceof Name ? $call->class->toString() : null;
        $methodName = $call->name instanceof Node\Identifier ? strtolower($call->name->name) : null;
        if ($className === null || $methodName === null) {
            return null;
        }

        $normalizedClass = ltrim($className, '\\');
        $isTranslationClass = in_array($normalizedClass, ['I18n', 'Neos\\Flow\\I18n'], true);
        if ($isTranslationClass && $methodName === 'translate') {
            return $this->createReference(
                identifier: $this->resolveStringArgument($call->args[0] ?? null),
                fallback: $this->resolveStringArgument($call->args[1] ?? null),
                placeholders: $this->extractPlaceholders($call->args[2] ?? null),
                sourceName: $this->resolveStringArgument($call->args[3] ?? null),
                packageKey: $this->resolveStringArgument($call->args[4] ?? null),
                filePath: $filePath,
                lineNumber: $call->getStartLine()
            );
        }

        return null;
    }

    private function buildFromMethodCall(MethodCall $call, string $filePath): ?TranslationReference
    {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }

        $methodName = strtolower($call->name->name);
        if (!in_array($methodName, ['translatebyid'], true)) {
            return null;
        }

        return $this->createReference(
            identifier: $this->resolveStringArgument($call->args[0] ?? null),
            fallback: null,
            placeholders: $this->extractPlaceholders($call->args[1] ?? null),
            sourceName: $this->resolveStringArgument($call->args[4] ?? null),
            packageKey: $this->resolveStringArgument($call->args[5] ?? null),
            filePath: $filePath,
            lineNumber: $call->getStartLine()
        );
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function createReference(
        ?string $identifier,
        ?string $fallback,
        array $placeholders,
        ?string $sourceName,
        ?string $packageKey,
        string $filePath,
        int $lineNumber
    ): ?TranslationReference {
        if ($identifier === null) {
            return null;
        }

        $resolved = TranslationMetadataResolver::resolve($identifier, $packageKey, $sourceName);
        if ($resolved === null) {
            return null;
        }

        return new TranslationReference(
            packageKey: $resolved['packageKey'],
            sourceName: $resolved['sourceName'],
            identifier: $resolved['identifier'],
            context: TranslationReference::CONTEXT_PHP,
            filePath: $filePath,
            lineNumber: $lineNumber,
            fallback: $fallback,
            placeholders: $placeholders
        );
    }

    private function resolveStringArgument(?Node\Arg $argument): ?string
    {
        if ($argument === null) {
            return null;
        }

        $value = $argument->value;
        if ($value instanceof String_) {
            return $value->value;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractPlaceholders(?Node\Arg $argument): array
    {
        if ($argument === null) {
            return [];
        }
        $value = $argument->value;
        if (!$value instanceof Array_) {
            return [];
        }

        $placeholders = [];
        foreach ($value->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }
            if (!$item->key instanceof String_) {
                continue;
            }
            $placeholders[$item->key->value] = $this->prettyPrinter->prettyPrintExpr($item->value);
        }

        return $placeholders;
    }
}
