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
