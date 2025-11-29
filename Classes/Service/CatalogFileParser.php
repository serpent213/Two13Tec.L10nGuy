<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Service;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Two13Tec\L10nGuy\Exception\CatalogFileParserException;

/**
 * Lightweight XML parser used to inspect catalog metadata and existing units.
 */
final class CatalogFileParser
{
    /**
     * @return array{
     *     meta: array<string, mixed>,
     *     units: array<string, array{
     *         source: ?string,
     *         target: ?string,
     *         state: ?string,
     *         attributes: array<string, string>,
     *         sourceAttributes: array<string, string>,
     *         targetAttributes: array<string, string>,
     *         children: list<array{type: 'source'|'target'|'unknown', xml?: string}>,
     *         hasSource: bool,
     *         hasTarget: bool
     *     }>,
     *     fileAttributes: array<string, string>,
     *     fileChildren: list<string>,
     *     bodyOrder: list<array{type: 'trans-unit', identifier: string}|array{type: 'unknown', xml: string}>
     * }
     */
    public static function parse(string $filePath): array
    {
        $meta = [
            'productName' => null,
            'sourceLanguage' => null,
            'targetLanguage' => null,
            'original' => null,
            'datatype' => null,
        ];
        $units = [];
        $fileAttributes = [];
        $fileChildren = [];
        $bodyOrder = [];

        if (!is_file($filePath)) {
            return [
                'meta' => $meta,
                'units' => $units,
                'fileAttributes' => $fileAttributes,
                'fileChildren' => $fileChildren,
                'bodyOrder' => $bodyOrder,
            ];
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            throw CatalogFileParserException::becauseUnreadable($filePath);
        }
        if (trim($contents) === '') {
            throw CatalogFileParserException::becauseEmpty($filePath);
        }

        $normalizedXml = preg_replace('/xmlns="[^"]+"/', '', $contents, 1) ?? $contents;
        $previousSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($normalizedXml);
        if ($xml === false) {
            $error = libxml_get_last_error();
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
            $reason = $error?->message ?? '';
            throw CatalogFileParserException::becauseMalformed($filePath, $reason);
        }

        $fileElement = $xml->file[0] ?? null;
        if ($fileElement instanceof \SimpleXMLElement) {
            $meta['productName'] = isset($fileElement['product-name']) ? (string)$fileElement['product-name'] : null;
            $meta['sourceLanguage'] = isset($fileElement['source-language']) ? (string)$fileElement['source-language'] : null;
            $meta['targetLanguage'] = isset($fileElement['target-language']) ? (string)$fileElement['target-language'] : null;
            $meta['original'] = isset($fileElement['original']) ? (string)$fileElement['original'] : null;
            $meta['datatype'] = isset($fileElement['datatype']) ? (string)$fileElement['datatype'] : null;

            $fileAttributes = self::collectUnknownAttributes($fileElement, [
                'product-name',
                'source-language',
                'target-language',
                'original',
                'datatype',
            ]);

            foreach ($fileElement->children() as $child) {
                if (!$child instanceof \SimpleXMLElement || $child->getName() === 'body') {
                    continue;
                }
                $fileChildren[] = self::exportNode($child);
            }

            $bodyNodes = $fileElement->xpath('body/*') ?: [];
            foreach ($bodyNodes as $bodyNode) {
                if (!$bodyNode instanceof \SimpleXMLElement) {
                    continue;
                }

                if ($bodyNode->getName() !== 'trans-unit' || !isset($bodyNode['id'])) {
                    $bodyOrder[] = ['type' => 'unknown', 'xml' => self::exportNode($bodyNode)];
                    continue;
                }

                $identifier = (string)$bodyNode['id'];
                $unitAttributes = self::collectUnknownAttributes($bodyNode, ['id', 'xml:space']);
                $unitChildren = [];
                $sourceAttributes = [];
                $targetAttributes = [];
                $source = null;
                $target = null;
                $state = null;
                $hasSource = false;
                $hasTarget = false;

                foreach ($bodyNode->children() as $child) {
                    if (!$child instanceof \SimpleXMLElement) {
                        continue;
                    }
                    $childName = $child->getName();
                    if ($childName === 'source') {
                        $hasSource = true;
                        $source = (string)$child;
                        $sourceAttributes = self::collectUnknownAttributes($child, []);
                        $unitChildren[] = ['type' => 'source'];
                        continue;
                    }
                    if ($childName === 'target') {
                        $hasTarget = true;
                        $target = (string)$child;
                        $state = isset($child['state']) ? (string)$child['state'] : null;
                        $targetAttributes = self::collectUnknownAttributes($child, ['state']);
                        $unitChildren[] = ['type' => 'target'];
                        continue;
                    }

                    $unitChildren[] = ['type' => 'unknown', 'xml' => self::exportNode($child)];
                }

                $units[$identifier] = [
                    'source' => $source,
                    'target' => $target,
                    'state' => $state,
                    'attributes' => $unitAttributes,
                    'sourceAttributes' => $sourceAttributes,
                    'targetAttributes' => $targetAttributes,
                    'children' => $unitChildren,
                    'hasSource' => $hasSource,
                    'hasTarget' => $hasTarget,
                ];
                $bodyOrder[] = ['type' => 'trans-unit', 'identifier' => $identifier];
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        return [
            'meta' => $meta,
            'units' => $units,
            'fileAttributes' => $fileAttributes,
            'fileChildren' => $fileChildren,
            'bodyOrder' => $bodyOrder,
        ];
    }

    /**
     * @param list<string> $known
     * @return array<string, string>
     */
    private static function collectUnknownAttributes(\SimpleXMLElement $element, array $known): array
    {
        $attributes = [];
        foreach ($element->attributes() as $name => $value) {
            if (in_array((string)$name, $known, true)) {
                continue;
            }
            $attributes[(string)$name] = (string)$value;
        }
        foreach ($element->attributes('xml', true) as $name => $value) {
            $qualified = 'xml:' . (string)$name;
            if (in_array($qualified, $known, true)) {
                continue;
            }
            $attributes[$qualified] = (string)$value;
        }

        return $attributes;
    }

    private static function exportNode(\SimpleXMLElement $element): string
    {
        $domNode = dom_import_simplexml($element);
        if (!$domNode instanceof \DOMElement) {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->appendChild($document->importNode($domNode, true));

        $xml = $document->saveXML($document->documentElement);

        return $xml === false ? '' : trim($xml);
    }
}
