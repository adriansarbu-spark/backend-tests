<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

// Audit logging is a side effect of several otherwise isolated unit-test
// paths. Keep those writes in memory so tests neither require access to the
// production audit directory nor leave log files behind.
define('RA_AUDIT_LOG_FILE', 'php://memory');

// The application config loaded by tests_config.php defines the audit path
// unconditionally. Ignore only that expected duplicate-definition warning;
// all other bootstrap warnings continue through Pest's error handler.
$previousErrorHandler = set_error_handler(
    static function (int $severity, string $message, string $file, int $line) use (&$previousErrorHandler): bool {
        if ($severity === E_WARNING && $message === 'Constant RA_AUDIT_LOG_FILE already defined') {
            return true;
        }

        return is_callable($previousErrorHandler)
            ? (bool) $previousErrorHandler($severity, $message, $file, $line)
            : false;
    }
);

try {
    require_once __DIR__ . '/tests_config.php';
} finally {
    restore_error_handler();
    unset($previousErrorHandler);
}

require_once __DIR__ . '/Support/AccountCompaniesApiHelper.php';

uses()
    ->beforeEach(function () {
        if (defined('SKIP_INTEGRATION_TESTS') && SKIP_INTEGRATION_TESTS) {
            return;
        }

        $testClass = get_class($this);
        if (str_contains($testClass, 'AccountCertificatesCrl')) {
            return;
        }

        AccountCompaniesApiHelper::ensureIntegrationUsersPersonalActiveRoles();
    })
    ->in('Feature/Api');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }
