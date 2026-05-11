<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/test/TestPassHistoryService.php';

test('test pass history service creates history file and first entry', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    $service = new TestPassHistoryService($path);

    $warning = '';
    $history = $service->upsertFromSummary([
        'run_id' => 'run-1',
        'started_at' => '2026-05-06T14:30:00Z',
        'finished_at' => '2026-05-06T14:30:01Z',
        'summary_json' => ['passed' => 9, 'failed' => 1, 'skipped' => 0],
    ], $warning);

    expect($warning)->toBe('');
    expect(is_file($path))->toBeTrue();
    expect($history)->toHaveCount(1);
    expect($history[0]['timestamp'] ?? null)->toBe('2026-05-06T14:30:01Z');
    expect($history[0]['pass_percentage'] ?? null)->toBe(90.0);
    expect($history[0]['total'] ?? null)->toBe(10);
    expect($history[0]['passed'] ?? null)->toBe(9);
    expect($history[0]['failed'] ?? null)->toBe(1);
});

test('test pass history service appends new entries and preserves old ones', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    $service = new TestPassHistoryService($path);

    $warning = '';
    $first = $service->upsertFromSummary([
        'run_id' => 'run-1',
        'finished_at' => '2026-05-06T14:30:00Z',
        'summary_json' => ['passed' => 8, 'failed' => 2, 'skipped' => 0],
    ], $warning);
    expect($warning)->toBe('');
    expect($first)->toHaveCount(1);

    $second = $service->upsertFromSummary([
        'run_id' => 'run-2',
        'finished_at' => '2026-05-06T14:40:00Z',
        'summary_json' => ['passed' => 10, 'failed' => 0, 'skipped' => 0],
    ], $warning);

    expect($warning)->toBe('');
    expect($second)->toHaveCount(2);
    expect($second[0]['run_id'] ?? null)->toBe('run-1');
    expect($second[1]['run_id'] ?? null)->toBe('run-2');
});

test('test pass history service updates duplicate run instead of appending', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    $service = new TestPassHistoryService($path);

    $warning = '';
    $service->upsertFromSummary([
        'run_id' => 'run-duplicate',
        'finished_at' => '2026-05-06T14:50:00Z',
        'summary_json' => ['passed' => 3, 'failed' => 1, 'skipped' => 0],
    ], $warning);

    $history = $service->upsertFromSummary([
        'run_id' => 'run-duplicate',
        'finished_at' => '2026-05-06T14:50:00Z',
        'summary_json' => ['passed' => 4, 'failed' => 0, 'skipped' => 0],
    ], $warning);

    expect($warning)->toBe('');
    expect($history)->toHaveCount(1);
    expect($history[0]['passed'] ?? null)->toBe(4);
    expect($history[0]['failed'] ?? null)->toBe(0);
    expect($history[0]['pass_percentage'] ?? null)->toBe(100.0);
});

test('test pass history service resets invalid json before appending', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    @mkdir($dir, 0770, true);
    file_put_contents($path, '{invalid');

    $service = new TestPassHistoryService($path);
    $warning = '';
    $history = $service->upsertFromSummary([
        'run_id' => 'run-after-invalid',
        'finished_at' => '2026-05-06T15:00:00Z',
        'summary_json' => ['passed' => 7, 'failed' => 3, 'skipped' => 0],
    ], $warning);

    expect($warning)->toBe('');
    expect($history)->toHaveCount(1);
    expect($history[0]['run_id'] ?? null)->toBe('run-after-invalid');
});

test('test pass history service persists unit feature and overall result metadata', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    $service = new TestPassHistoryService($path);

    $warning = '';
    $history = $service->upsertFromSummary([
        'run_id' => 'run-all-1',
        'runId' => 'run-all-1',
        'suite' => 'ALL',
        'status' => 'partial_failed',
        'started_at' => '2026-05-06T15:59:00Z',
        'finished_at' => '2026-05-06T16:00:00Z',
        'summary_json' => ['passed' => 18, 'failed' => 2, 'skipped' => 0],
        'unit' => ['status' => 'passed'],
        'feature' => ['status' => 'failed'],
        'unit_result' => ['run_id' => 'u1', 'status' => 'passed'],
        'feature_result' => ['run_id' => 'f1', 'status' => 'failed'],
        'overall_result' => ['status' => 'failed'],
    ], $warning);

    expect($warning)->toBe('');
    expect($history)->toHaveCount(1);
    expect($history[0]['unit_result']['run_id'] ?? null)->toBe('u1');
    expect($history[0]['feature_result']['run_id'] ?? null)->toBe('f1');
    expect($history[0]['overall_result']['status'] ?? null)->toBe('failed');
    expect($history[0]['suite'] ?? null)->toBe('ALL');
    expect($history[0]['runId'] ?? null)->toBe('run-all-1');
    expect($history[0]['status'] ?? null)->toBe('partial_failed');
    expect($history[0]['startedAt'] ?? null)->toBe('2026-05-06T15:59:00Z');
    expect($history[0]['finishedAt'] ?? null)->toBe('2026-05-06T16:00:00Z');
    expect($history[0]['unit']['status'] ?? null)->toBe('passed');
    expect($history[0]['feature']['status'] ?? null)->toBe('failed');
});

test('filterHistoryToLastDays keeps entries within the cutoff and drops older rows', function () {
    $dir = sys_get_temp_dir() . '/test-pass-history-' . uniqid('', true);
    $path = $dir . '/test-pass-history.json';
    $service = new TestPassHistoryService($path);

    $now = time();
    $history = array(
        array('timestamp' => gmdate('c', $now - 35 * 86400), 'passed' => 1),
        array('timestamp' => gmdate('c', $now - 10 * 86400), 'passed' => 2),
        array('timestamp' => gmdate('c', $now - 2 * 86400), 'passed' => 3),
    );

    $filtered = $service->filterHistoryToLastDays($history, 30);

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]['passed'] ?? null)->toBe(2);
    expect($filtered[1]['passed'] ?? null)->toBe(3);
});

test('filterHistoryToLastDays falls back to finished_at for ordering cutoff', function () {
    $path = sys_get_temp_dir() . '/test-pass-history-filter-' . uniqid('', true) . '.json';
    $service = new TestPassHistoryService($path);

    $now = time();
    $history = array(
        array('finished_at' => gmdate('c', $now - 1 * 86400), 'passed' => 9),
    );

    $filtered = $service->filterHistoryToLastDays($history, 7);

    expect($filtered)->toHaveCount(1);
    expect($filtered[0]['passed'] ?? null)->toBe(9);
});
