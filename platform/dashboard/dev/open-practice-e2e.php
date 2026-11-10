<?php

/**
 * Developer test for the one-click open (ADM-01), run in the local Docker stack against a real Chromium.
 *
 * It signs in to the dashboard, picks a practice in the dropdown, clicks Open and reports where the new tab ends up
 * (SIGNED IN means it reached the practice's main screen). It checks the page under the dashboard's real security policy.
 *
 *   docker exec development-easy-openemr-1 php platform/dashboard/dev/open-practice-e2e.php \
 *       <admin-host> <practice-host> <operator-email> <operator-password>
 *
 * Needs: the dev stack's Selenium container, a running worker, `platform_domain` set in
 * platform/dashboard/var/config.php so the practice counts as being on the platform domain, and both host names
 * under that domain (for example admin.emb.test and clinic.emb.test). The browser is told to send both names to
 * the app container, so no hosts-file or DNS change is needed. The Selenium grid allows one session at a time.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;

if (PHP_SAPI !== 'cli' || $argc !== 5) {
    fwrite(STDERR, "Usage: php open-practice-e2e.php <admin-host> <practice-host> <operator-email> <operator-password>\n");
    exit(2);
}
[, $adminHost, $practiceHost, $email, $password] = $argv;

$options = new ChromeOptions();
$options->addArguments([
    '--headless=new',
    '--no-sandbox',
    "--host-resolver-rules=MAP $adminHost openemr, MAP $practiceHost openemr",
]);
$capabilities = DesiredCapabilities::chrome();
$capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
// The practice's main screen loads a lot; do not wait for it, poll the address instead.
$capabilities->setCapability('pageLoadStrategy', 'none');
$driver = RemoteWebDriver::create('http://selenium:4444/wd/hub', $capabilities, 30000, 30000);

$safe = static function (callable $fn, mixed $default = null): mixed {
    try {
        return $fn();
    } catch (Throwable) {
        return $default;
    }
};
$waitFor = static function (callable $fn, int $seconds = 15) use ($safe): mixed {
    for ($i = 0; $i < $seconds * 2; $i++) {
        $value = $safe($fn);
        if ($value) {
            return $value;
        }
        usleep(500000);
    }
    return null;
};

$exit = 1;
try {
    $driver->get("http://$adminHost/login");
    $waitFor(fn() => $driver->findElement(WebDriverBy::name('email')))?->sendKeys($email);
    $driver->findElement(WebDriverBy::name('password'))->sendKeys($password);
    $driver->findElement(WebDriverBy::cssSelector('form button[type=submit]'))->click();
    $select = $waitFor(fn() => $driver->findElement(WebDriverBy::cssSelector('select[name=domain]')));
    if ($select === null) {
        throw new RuntimeException('The Practices page with the Open dropdown did not load (wrong login, or the role cannot open practices)');
    }
    (new WebDriverSelect($select))->selectByValue($practiceHost);

    $before = $driver->getWindowHandles();
    $driver->findElement(WebDriverBy::cssSelector('form.open-form button[type=submit]'))->click();
    $newTab = null;
    for ($i = 0; $i < 20 && $newTab === null; $i++) {
        usleep(500000);
        $newTab = array_values(array_diff($driver->getWindowHandles(), $before))[0] ?? null;
    }
    echo 'new tab opened: ', $newTab !== null ? 'yes' : 'NO', "\n";
    if ($newTab === null) {
        throw new RuntimeException('Open did not open a new tab');
    }
    $driver->switchTo()->window($newTab);

    $url = '';
    $path = [];
    for ($i = 0; $i < 120; $i++) {
        usleep(500000);
        $url = (string) $safe(fn() => $driver->getCurrentURL(), '');
        $shown = preg_replace('/token_main=[^&]+/', 'token_main=...', $url);
        if ($shown !== '' && $shown !== end($path)) {
            $path[] = $shown;
        }
        $onPractice = str_contains($url, $practiceHost);
        if ($onPractice && (str_contains($url, 'main/tabs/main.php') || str_contains($url, 'login/login.php'))) {
            break;
        }
        if ($i > 6 && str_contains($url, $adminHost) && !str_contains($url, '/jobs/')) {
            break; // an error page from the dashboard itself
        }
    }
    usleep(1500000);
    $source = (string) $safe(fn() => $driver->getPageSource(), '');
    echo "tab path:\n  ", implode("\n  ", $path), "\n";
    $signedIn = str_contains($url, 'main/tabs/main.php') && !str_contains($source, 'name="authUser"');
    echo 'RESULT: ', $signedIn ? 'SIGNED IN to the practice' : 'NOT signed in', "\n";
    foreach ((array) $safe(fn() => $driver->manage()->getCookies(), []) as $cookie) {
        echo 'cookie ', $cookie->getName(), ' sameSite=', $cookie->getSameSite() ?? 'none', "\n";
    }
    $exit = $signedIn ? 0 : 1;
} finally {
    $safe(fn() => $driver->quit());
}
exit($exit);
