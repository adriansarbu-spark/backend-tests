<?php

declare(strict_types=1);

test('history export deduplicates skipped tests by stable identity and keeps file-less rows', function () {
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
    $method = $reflection->getMethod('dedupeDetailRowsForExport');
    $method->setAccessible(true);

    $rows = array(
        array(
            'file' => '',
            'test_name' => 'Skips when feature disabled',
            'result' => 'skipped',
            'error_message' => ''
        ),
        array(
            'file' => 'tests/Feature/Api/Templates/TemplatesPermissionsTest.php',
            'test_name' => 'Skips when feature disabled',
            'result' => 'skip',
            'error_message' => 'Test skipped'
        ),
        array(
            'file' => '',
            'test_name' => 'Skips without file metadata',
            'result' => 'skipped',
            'error_message' => ''
        ),
        array(
            'file' => '',
            'test_name' => 'Skips without file metadata',
            'result' => 'skipped',
            'error_message' => 'Test skipped'
        ),
        array(
            'file' => '',
            'test_name' => 'Documents flow: uncertified account qualified upload (behavior chec…',
            'result' => 'skipped',
            'error_message' => ''
        ),
        array(
            'file' => 'tests/Feature/Api/Documents/DocumentsFlowTest.php',
            'test_name' => 'Documents flow uncertified account qualified upload behavior check',
            'result' => 'skipped',
            'error_message' => 'Test skipped'
        ),
        array(
            'file' => '',
            'test_name' => 'tests/auth/Login test should skip when user is disabled',
            'result' => 'skip',
            'error_message' => 'Test skipped'
        ),
        array(
            'file' => 'tests/Feature/Api/Documents/DocumentsFlowTest.php',
            'test_name' => 'Fails when payload is invalid',
            'result' => 'failed',
            'error_message' => 'Assertion failed'
        ),
        array(
            'file' => 'tests/Feature/Api/Documents/DocumentsFlowTest.php',
            'test_name' => 'Fails when payload is invalid',
            'result' => 'failed',
            'error_message' => 'Assertion failed'
        )
    );

    $deduped = $method->invoke($controller, $rows);

    expect($deduped)->toHaveCount(5);
    expect($deduped)->toContainEqual(array(
        'file' => '',
        'test_name' => 'Skips when feature disabled',
        'result' => 'skipped',
        'error_message' => 'Test skipped'
    ));
    expect($deduped)->toContainEqual(array(
        'file' => '',
        'test_name' => 'Skips without file metadata',
        'result' => 'skipped',
        'error_message' => 'Test skipped'
    ));
    expect($deduped)->toContainEqual(array(
        'file' => 'tests/Feature/Api/Documents/DocumentsFlowTest.php',
        'test_name' => 'Fails when payload is invalid',
        'result' => 'failed',
        'error_message' => 'Assertion failed'
    ));
    expect($deduped)->toContainEqual(array(
        'file' => '',
        'test_name' => 'Documents flow: uncertified account qualified upload (behavior chec…',
        'result' => 'skipped',
        'error_message' => 'Test skipped'
    ));
    expect($deduped)->toContainEqual(array(
        'file' => '',
        'test_name' => 'tests/auth/Login test should skip when user is disabled',
        'result' => 'skipped',
        'error_message' => 'Test skipped'
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
