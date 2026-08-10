<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorReport.php';

test('s3 log monitor report builds alert summary text for email body', function () {
	$rulePass = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'window_days' => 7, 'label' => 'HSM DR'));
	$ruleFail = new S3LogMonitorRule(array('base_path' => 'prod/ejbca/ejbca01', 'window_days' => 1, 'label' => 'EJBCA Prod'));
	$rows = array(
		new S3LogMonitorResultRow($rulePass, 'pass', '', '2026-06-17'),
		new S3LogMonitorResultRow($ruleFail, 'fail', 'No log uploaded within the expected freshness window.', null),
	);
	$report = new S3LogMonitorReport();
	$checkedAt = new DateTimeImmutable('2026-06-17 07:00:00', new DateTimeZone('UTC'));

	$summary = $report->buildSummary($rows, $checkedAt);

	expect($summary['overall_status'])->toBe('ALERT');
	expect($summary['folders_checked'])->toBe('2');
	expect($summary['folders_passed'])->toBe('1');
	expect($summary['folders_failed'])->toBe('1');
	expect($summary['date'])->toBe('2026-06-17');
	expect($summary['summary_section'])->toContain("Processed:            2");
	expect($summary['summary_section'])->toContain("Overall Status:       ALERT");
	expect($summary)->not->toHaveKey('detail_section');
});

test('s3 log monitor report marks all-pass run as ok', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'window_days' => 7));
	$rows = array(new S3LogMonitorResultRow($rule, 'pass', '', '2026-06-17'));
	$report = new S3LogMonitorReport();
	$checkedAt = new DateTimeImmutable('2026-06-17 07:00:00', new DateTimeZone('UTC'));

	$summary = $report->buildSummary($rows, $checkedAt);

	expect($summary['overall_status'])->toBe('OK');
	expect($summary['problem_count'])->toBe('0');
	expect($summary['summary_section'])->toContain("Overall Status:       OK");
});

test('s3 log monitor report applies warning aggregation precedence and separate counts', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'logs/example'));
	$report = new S3LogMonitorReport();
	$checkedAt = new DateTimeImmutable('2026-07-21 07:00:00', new DateTimeZone('UTC'));
	$build = function (array $statuses) use ($rule, $report, $checkedAt) {
		$rows = array_map(function ($status) use ($rule) {
			return new S3LogMonitorResultRow($rule, $status);
		}, $statuses);

		return $report->buildSummary($rows, $checkedAt);
	};

	$pass = $build(array());
	$warnings = $build(array('pass', 'warning'));
	$warningAndFail = $build(array('pass', 'warning', 'fail'));
	$warningAndError = $build(array('warning', 'error'));

	expect($pass['run_status'])->toBe('pass');
	expect($warnings['run_status'])->toBe('pass_with_warnings');
	expect($warnings['overall_status'])->toBe('OK WITH WARNINGS');
	expect($warnings['folders_checked'])->toBe('2');
	expect($warnings['folders_passed'])->toBe('1');
	expect($warnings['folders_warnings'])->toBe('1');
	expect($warnings['folders_failed'])->toBe('0');
	expect($warnings['problem_count'])->toBe('0');
	expect($warnings['summary_section'])->toContain('Warnings:');
	expect($warningAndFail['run_status'])->toBe('fail');
	expect($warningAndFail['folders_warnings'])->toBe('1');
	expect($warningAndFail['folders_failed'])->toBe('1');
	expect($warningAndError['run_status'])->toBe('fail');
	expect($warningAndError['folders_errors'])->toBe('1');
});
