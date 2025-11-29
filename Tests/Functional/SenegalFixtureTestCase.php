<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Functional;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

require_once dirname(__DIR__, 4) . '/Packages/Framework/Neos.Flow/Tests/FunctionalTestCase.php';

use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Utility\Files;

abstract class SenegalFixtureTestCase extends FunctionalTestCase
{
    private const RELATIVE_FIXTURE_PATH = 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline';
    private const TEMPORARY_FIXTURE_DIRECTORY = 'Temporary/Testing/SenegalBaseline/Two13Tec.Senegal';

    private static bool $fixturePrepared = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$fixturePrepared === false) {
            self::mirrorFixtureIntoSandbox();
            self::$fixturePrepared = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFixture();
    }

    protected static function getFixturePackagePath(): string
    {
        return Files::concatenatePaths([FLOW_PATH_DATA, self::TEMPORARY_FIXTURE_DIRECTORY]);
    }

    protected function resetFixture(): void
    {
        self::mirrorFixtureIntoSandbox();
    }

    protected function setProtectedProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($object, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \RuntimeException(sprintf('Property %s not found on %s', $propertyName, $object::class), 1731159001);
    }

    private static function mirrorFixtureIntoSandbox(): void
    {
        $sourceDirectory = Files::concatenatePaths([FLOW_PATH_ROOT, self::RELATIVE_FIXTURE_PATH]);
        $targetDirectory = self::getFixturePackagePath();

        if (is_dir($targetDirectory)) {
            Files::removeDirectoryRecursively($targetDirectory);
        }

        Files::createDirectoryRecursively($targetDirectory);
        Files::copyDirectoryRecursively($sourceDirectory, $targetDirectory);
    }
}
