<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/LogFreshnessEvaluator.php';

test('log freshness evaluator passes when upload is within window', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'dr/hsm/hsmdr',
		'window_days' => 7,
		'timezone'    => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$result = $evaluator->evaluate('2026-06-11', $rule, $now);

	expect($result['status'])->toBe(LogFreshnessEvaluator::STATUS_PASS);
	expect($result['reason'])->toBe('');
});

test('log freshness evaluator fails when upload is too old for 7-day window', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'dr/hsm/hsmdr',
		'window_days' => 7,
		'timezone'    => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$result = $evaluator->evaluate('2026-06-09', $rule, $now);

	expect($result['status'])->toBe(LogFreshnessEvaluator::STATUS_FAIL);
	expect($result['reason'])->toContain('outside the required');
});

test('log freshness evaluator requires today for 1-day window without grace', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'prod/ejbca/ejbca01',
		'window_days' => 1,
		'grace_hours' => 0,
		'timezone'    => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$pass = $evaluator->evaluate('2026-06-17', $rule, $now);
	$fail = $evaluator->evaluate('2026-06-16', $rule, $now);

	expect($pass['status'])->toBe(LogFreshnessEvaluator::STATUS_PASS);
	expect($fail['status'])->toBe(LogFreshnessEvaluator::STATUS_FAIL);
});

test('log freshness evaluator applies default 1-day grace when grace_hours is omitted', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'prod/ejbca/ejbca01',
		'window_days' => 1,
		'timezone'    => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$result = $evaluator->evaluate('2026-06-16', $rule, $now);

	expect($rule->grace_hours)->toBe(24);
	expect($result['status'])->toBe(LogFreshnessEvaluator::STATUS_PASS);
});

test('log freshness evaluator fails when no upload date found', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'window_days' => 7));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$result = $evaluator->evaluate(null, $rule, $now, 7);

	expect($result['status'])->toBe(LogFreshnessEvaluator::STATUS_FAIL);
	expect($result['reason'])->toBe('No logs found in the last 7 days');
});

test('log freshness evaluator applies grace hours to oldest allowed day', function () {
	$evaluator = new LogFreshnessEvaluator();
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'prod/ejbca/ejbca01',
		'window_days' => 1,
		'grace_hours' => 24,
		'timezone'    => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 10:00:00', new DateTimeZone('UTC'));

	$result = $evaluator->evaluate('2026-06-16', $rule, $now);

	expect($result['status'])->toBe(LogFreshnessEvaluator::STATUS_PASS);
});
