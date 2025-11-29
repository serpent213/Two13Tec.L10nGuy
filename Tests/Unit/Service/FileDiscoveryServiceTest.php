<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Service;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Utility\Files;
use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;

/**
 * @covers \Two13Tec\L10nGuy\Service\FileDiscoveryService
 */
final class FileDiscoveryServiceTest extends TestCase
{
    /**
     * @test
     */
    public function patternsApplyRelativeToSearchRoot(): void
    {
        $tempRoot = Files::concatenatePaths([sys_get_temp_dir(), 'l10nguy_discovery_' . uniqid('', true)]);
        $catalogDirectory = $tempRoot . '/DistributionPackages/Vendor.Package/Resources/Private/Translations/en';
        Files::createDirectoryRecursively($catalogDirectory);
        $templateDirectory = $tempRoot . '/DistributionPackages/Vendor.Package/Resources/Private/Templates';
        Files::createDirectoryRecursively($templateDirectory);
        file_put_contents($catalogDirectory . '/Main.xlf', '<xliff/>');
        file_put_contents($templateDirectory . '/Foo.html', '<div />');

        try {
            $service = new FileDiscoveryService([
                'includes' => [
                    ['pattern' => 'Resources/Private/Translations/**/*.xlf', 'enabled' => true],
                ],
                'excludes' => [
                    ['pattern' => '**/*.html', 'enabled' => true],
                ],
            ]);

            $matches = $service->discover($tempRoot, ['DistributionPackages/Vendor.Package']);

            self::assertCount(1, $matches);
            self::assertSame('Main.xlf', $matches[0]->getFilename());
        } finally {
            Files::removeDirectoryRecursively($tempRoot);
        }
    }
}
