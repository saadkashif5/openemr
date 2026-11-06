<?php

/**
 * EMB platform module bootstrap. Loaded on every request while the module is enabled in a practice.
 * It only registers the namespace; endpoints live under public/ and are called directly.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\Platform\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');
