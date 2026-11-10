<?php

/**
 * EMB platform module bootstrap. Loaded on every request while the module is enabled in a practice. It registers
 * the namespace and the token API routes; the session endpoints live under public/ and are called directly.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\Platform\Bootstrap;

$globalsBag = OEGlobalsBag::getInstance();
$classLoader = new ModulesClassLoader($globalsBag->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\Platform\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap($globalsBag->getKernel()->getEventDispatcher()))->subscribeToEvents();
