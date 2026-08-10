<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/test/TestRunStore.php';
require_once DIR_SYSTEM . 'library/test/TestRunStorageConfig.php';

test('filterStateForStorage drops both branches when feature executor is not remote', function () {
    $store = new TestRunStore(null);

    $state = array(
        'source' => 'dashboard',
        'suite_type' => 'all',
        'unit_executor' => 'local',
        'feature_executor' => 'local',
        'summary_json' => array('passed' => 120, 'failed' => 3, 'skipped' => 2),
        'passed_json' => array(
            array('name' => 'unit pass', 'file' => 'tests/Unit/Admin/ATest.php'),
            array('name' => 'feature pass', 'file' => 'tests/Feature/Api/BTest.php'),
        ),
        'failed_json' => array(),
        'skipped_json' => array(),
        'unit' => array(
            'summary_json' => array('passed' => 40, 'failed' => 1, 'skipped' => 0),
            'passed_json' => array(array('name' => 'unit pass', 'file' => 'tests/Unit/Admin/ATest.php')),
        ),
        'feature' => array(
            'summary_json' => array('passed' => 80, 'failed' => 2, 'skipped' => 2),
            'passed_json' => array(array('name' => 'feature pass', 'file' => 'tests/Feature/Api/BTest.php')),
        ),
    );

    $filtered = $store->filterStateForStorage($state);

    expect($filtered['summary_json'])->toBe(array('passed' => 120, 'failed' => 3, 'skipped' => 2));
    expect($filtered['passed_json'])->toHaveCount(2);
    expect($filtered)->not->toHaveKey('feature');
    expect($filtered)->not->toHaveKey('unit');
});

test('filterStateForStorage keeps both branches when unit is local and feature is remote', function () {
    $store = new TestRunStore(null);

    $state = array(
        'source' => 'dashboard',
        'suite_type' => 'all',
        'unit_executor' => 'local',
        'feature_executor' => 'remote',
        'summary_json' => array('passed' => 120, 'failed' => 3, 'skipped' => 2),
        'unit' => array(
            'summary_json' => array('passed' => 40, 'failed' => 1, 'skipped' => 0),
        ),
        'feature' => array(
            'summary_json' => array('passed' => 80, 'failed' => 2, 'skipped' => 2),
        ),
    );

    $filtered = $store->filterStateForStorage($state);

    expect($filtered)->toHaveKey('unit');
    expect($filtered)->toHaveKey('feature');
    expect($filtered['unit']['summary_json']['passed'] ?? null)->toBe(40);
    expect($filtered['feature']['summary_json']['passed'] ?? null)->toBe(80);
});

test('isRunStorable requires both local unit and remote feature for dashboard', function () {
    expect(TestRunStorageConfig::isRunStorable('dashboard', 'all', true, 'local', 'remote'))->toBeTrue();
    expect(TestRunStorageConfig::isRunStorable('dashboard', 'all', true, 'local', 'local'))->toBeFalse();
    expect(TestRunStorageConfig::isRunStorable('dashboard', 'all', true, 'remote', 'remote'))->toBeFalse();
    expect(TestRunStorageConfig::isRunStorable('dashboard', 'unit', true, 'local', 'remote'))->toBeFalse();
});
