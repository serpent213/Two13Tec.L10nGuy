<?php
declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Functional;

require_once __DIR__ . '/SenegalFixtureTestCase.php';

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Package\PackageManager;

final class SenegalFixtureBootTest extends SenegalFixtureTestCase
{
    /**
     * @test
     */
    public function packageManagerFindsSenegalPackageAndFixtureTreeIsAvailable(): void
    {
        /** @var PackageManager $packageManager */
        $packageManager = $this->objectManager->get(PackageManager::class);

        self::assertTrue(
            $packageManager->isPackageAvailable('Two13Tec.Senegal'),
            'Two13Tec.Senegal must be registered for test fixtures.'
        );
        self::assertDirectoryExists(self::getFixturePackagePath());
        self::assertFileExists(self::getFixturePackagePath() . '/Resources/Private/Fusion/Presentation/Cards/Card.fusion');
    }
}
