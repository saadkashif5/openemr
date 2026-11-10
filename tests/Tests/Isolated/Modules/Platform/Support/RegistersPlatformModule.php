<?php

/**
 * The platform module is not in the root composer.json autoload map; the module manager registers its prefix when it
 * is enabled, which the database-free isolated suite cannot do, so tests register it themselves.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\Platform\Support;

use Composer\Autoload\ClassLoader;

trait RegistersPlatformModule
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        if ($loaders === []) {
            self::fail('Composer ClassLoader not available to register module autoload prefix.');
        }
        // More than one loader can be registered under PHPUnit; the first is not always the one that resolves classes.
        foreach ($loaders as $loader) {
            $loader->addPsr4(
                'OpenEMR\\Modules\\Platform\\',
                dirname(__DIR__, 6) . '/interface/modules/custom_modules/oe-module-platform/src/'
            );
        }
    }
}
