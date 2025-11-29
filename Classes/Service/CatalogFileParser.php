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
     * @return array{meta: array<string, mixed>, units: array<string, array{source: ?string, target: ?string, state: ?string}>}
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

        if (!is_file($filePath)) {
            return ['meta' => $meta, 'units' => $units];
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

            $transUnits = $fileElement->xpath('body/trans-unit') ?: [];
            foreach ($transUnits as $transUnit) {
                if (!$transUnit instanceof \SimpleXMLElement || !isset($transUnit['id'])) {
                    continue;
                }
                $identifier = (string)$transUnit['id'];
                $units[$identifier] = [
                    'source' => isset($transUnit->source) ? (string)$transUnit->source : null,
                    'target' => isset($transUnit->target) ? (string)$transUnit->target : null,
                    'state' => isset($transUnit->target) ? (string)$transUnit->target['state'] : null,
                ];
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        return ['meta' => $meta, 'units' => $units];
    }
}
