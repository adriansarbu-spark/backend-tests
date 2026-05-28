<?php

declare(strict_types=1);

test('history export includes every json row without deduplication', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('historyDetailRowsForExport');
    $method->setAccessible(true);

    $entry = array(
        'failed_json' => array(
            array('name' => 'Fails when payload is invalid', 'file' => '/var/www/tests/Feature/Api/Documents/DocumentsFlowTest.php', 'message' => 'Assertion failed'),
            array('name' => 'Fails when payload is invalid', 'file' => '/var/www/tests/Feature/Api/Documents/DocumentsFlowTest.php', 'message' => 'Assertion failed'),
        ),
        'skipped_json' => array(
            array('name' => 'Skips when feature disabled', 'file' => '/var/www/tests/Feature/Api/Templates/TemplatesPermissionsTest.php', 'message' => ''),
        ),
        'passed_json' => array(
            array('name' => 'Passes once', 'file' => '/var/www/tests/Unit/Admin/AlphaTest.php'),
            array('name' => 'Passes twice', 'file' => '/var/www/tests/Unit/Admin/BetaTest.php'),
        ),
    );

    $rows = $method->invoke($controller, $entry);

    expect($rows)->toHaveCount(5);
    expect(array_count_values(array_column($rows, 'result')))->toMatchArray(array(
        'failed' => 2,
        'skipped' => 1,
        'passed' => 2,
    ));
});

test('history export classifies file path into type and category', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('classifyTestPathForExport');
    $method->setAccessible(true);

    expect($method->invoke($controller, 'tests/Unit/Admin/TestDashboardHistoryBehaviorTest.php'))->toEqual(array(
        'type' => 'Unit',
        'category' => 'Admin',
    ));
    expect($method->invoke($controller, 'tests/Unit/Api/Signing/GetDocumentFileTest.php'))->toEqual(array(
        'type' => 'Unit',
        'category' => 'Signing',
    ));

    expect($method->invoke($controller, 'tests/Feature/Api/Signing/SigningFlowTest.php'))->toEqual(array(
        'type' => 'Feature',
        'category' => 'Signing',
    ));

    expect($method->invoke($controller, 'tests/Feature/Auth/Login/LoginFlowTest.php'))->toEqual(array(
        'type' => 'Feature',
        'category' => 'Login',
    ));

    expect($method->invoke($controller, 'tests/Misc/OtherTest.php'))->toEqual(array(
        'type' => '',
        'category' => '',
    ));
});

test('history export collects failed skipped and passed rows from all history branches', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('collectExportResultRowsForEntry');
    $method->setAccessible(true);

    $rows = $method->invoke($controller, [
        'feature' => [
            'failed_json' => [
                ['name' => 'Feature failure', 'file' => '/var/www/tests/Feature/FeatureTest.php', 'message' => 'feature'],
            ],
            'skipped_json' => [
                ['name' => 'Feature skip', 'file' => '/var/www/tests/Feature/FeatureTest.php', 'message' => ''],
            ],
        ],
        'unit' => [
            'failed_json' => [
                ['name' => 'Unit failure', 'file' => '/var/www/tests/Unit/UnitTest.php', 'message' => 'unit'],
            ],
            'passed_json' => [
                ['name' => 'Unit pass', 'file' => '/var/www/tests/Unit/UnitTest.php'],
            ],
        ],
    ]);

    expect($rows['failed'])->toHaveCount(2);
    expect($rows['skipped'])->toHaveCount(1);
    expect($rows['passed'])->toHaveCount(1);
    expect(array_column($rows['failed'], 'raw_name'))->toContain('Feature failure', 'Unit failure');

    $merged = $method->invoke($controller, [
        'failed_json' => [
            ['name' => 'Top-level failure', 'file' => '/var/www/tests/Unit/TopTest.php', 'message' => 'top'],
            ['name' => 'Feature failure', 'file' => '/var/www/tests/Feature/FeatureTest.php', 'message' => 'feature'],
        ],
        'skipped_json' => [
            ['name' => 'Feature skip', 'file' => '/var/www/tests/Feature/FeatureTest.php', 'message' => ''],
        ],
        'passed_json' => [
            ['name' => 'Top-level pass', 'file' => '/var/www/tests/Unit/TopTest.php'],
        ],
        'feature' => [
            'failed_json' => [
                ['name' => 'Feature failure duplicate', 'file' => '/var/www/tests/Feature/FeatureTest.php', 'message' => 'ignored'],
            ],
        ],
    ]);

    expect($merged['failed'])->toHaveCount(2);
    expect($merged['skipped'])->toHaveCount(1);
    expect($merged['passed'])->toHaveCount(1);
    expect(array_column($merged['failed'], 'raw_name'))->not->toContain('Feature failure duplicate');
});

test('history export detail rows use stored json only', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('historyDetailRowsForExport');
    $method->setAccessible(true);

    $file = 'tests/Unit/Api/Account/AccountCompaniesPostNormalizationTest.php';
    $entry = array(
        'summary_json' => array('passed' => 2, 'failed' => 0, 'skipped' => 0),
        'failed_json' => array(),
        'skipped_json' => array(),
        'passed_json' => array(
            array(
                'name' => 'Account companies API - POST RO TIN is normalized to digits only with data set #0',
                'file' => '/var/www/' . $file,
            ),
            array(
                'name' => 'Account companies API - POST RO empty or non-digit TIN returns tin required with data set #1',
                'file' => '/var/www/' . $file,
            ),
        ),
        'unit' => array(
            'passed_json' => array(
                array(
                    'name' => 'Account companies API - POST RO TIN is normalized to digits only with data set #0',
                    'file' => '/var/www/' . $file,
                ),
            ),
        ),
    );

    $rows = $method->invoke($controller, $entry);

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'result'))->each->toBe('passed');
    expect(implode("\n", array_column($rows, 'test_name')))->toContain('with data set');
});

test('history export sorts detail rows with unit tests first then by file and test name', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('sortExportDetailRowsForExport');
    $method->setAccessible(true);

    $rows = $method->invoke($controller, array(
        array(
            'file' => 'tests/Feature/Api/Zebra/ZebraFlowTest.php',
            'test_name' => 'Feature Z',
            'result' => 'passed',
            'error_message' => '',
        ),
        array(
            'file' => 'tests/Unit/Api/Beta/BetaTest.php',
            'test_name' => 'Unit B second',
            'result' => 'failed',
            'error_message' => 'nope',
        ),
        array(
            'file' => 'tests/Unit/Api/Alpha/AlphaTest.php',
            'test_name' => 'Unit A',
            'result' => 'passed',
            'error_message' => '',
        ),
        array(
            'file' => 'tests/Unit/Api/Beta/BetaTest.php',
            'test_name' => 'Unit B first',
            'result' => 'skipped',
            'error_message' => 'Test skipped',
        ),
        array(
            'file' => 'tests/Feature/Api/Apple/AppleFlowTest.php',
            'test_name' => 'Feature A',
            'result' => 'passed',
            'error_message' => '',
        ),
    ));

    expect(array_column($rows, 'file'))->toBe(array(
        'tests/Unit/Api/Alpha/AlphaTest.php',
        'tests/Unit/Api/Beta/BetaTest.php',
        'tests/Unit/Api/Beta/BetaTest.php',
        'tests/Feature/Api/Apple/AppleFlowTest.php',
        'tests/Feature/Api/Zebra/ZebraFlowTest.php',
    ));
    expect(array_column($rows, 'test_name'))->toBe(array(
        'Unit A',
        'Unit B first',
        'Unit B second',
        'Feature A',
        'Feature Z',
    ));
});

test('history export resolveExportJsonSources prefers top-level arrays over nested branches', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('resolveExportJsonSources');
    $method->setAccessible(true);

    $entry = array(
        'passed_json' => array(array('name' => 'from top level', 'file' => 'tests/Unit/FooTest.php')),
        'unit' => array(
            'passed_json' => array(array('name' => 'from unit branch', 'file' => 'tests/Unit/FooTest.php')),
        ),
    );

    expect($method->invoke($controller, $entry, 'passed_json'))->toBe(array(
        array(array('name' => 'from top level', 'file' => 'tests/Unit/FooTest.php')),
    ));
});

test('history export skips legacy results_json failed rows but keeps passed and skipped', function () {
    if (!defined('DIR_SYSTEM')) {
        define('DIR_SYSTEM', __DIR__ . '/../../../public/system/');
    }

    if (!class_exists('Controller', false)) {
        class Controller {
        }
    }

    require_once __DIR__ . '/../../../public/admin/controller/tool/tests.php';

    $reflection = new ReflectionClass('ControllerToolTests');
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('historyDetailRowsForExport');
    $method->setAccessible(true);

    $rows = $method->invoke($controller, [
        'results_json' => [
            ['name' => 'Legacy failure', 'file' => '/var/www/tests/Unit/LegacyTest.php', 'message' => 'old format'],
        ],
        'skipped_json' => [
            ['name' => 'Still skipped', 'file' => '/var/www/tests/Unit/LegacyTest.php', 'message' => ''],
        ],
        'passed_json' => [
            ['name' => 'Still passed', 'file' => '/var/www/tests/Unit/LegacyTest.php'],
        ],
    ]);

    expect($rows)->toHaveCount(2);
    expect(array_count_values(array_column($rows, 'result')))->toMatchArray([
        'skipped' => 1,
        'passed' => 1,
    ]);
});
