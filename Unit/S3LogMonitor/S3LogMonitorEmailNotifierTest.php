<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorEmailNotifier.php';

class TestableS3LogMonitorEmailNotifier extends S3LogMonitorEmailNotifier {
	public function prefixFor($status) {
		return $this->languagePrefixForRunStatus($status);
	}

	public function templateVars(array $summary, array $rows) {
		return $this->buildTemplateVars($summary, $rows, 'https://example.test/', 'OK WITH WARNINGS', 'warning.png');
	}
}

test('s3 log monitor email notifier routes only successful-with-warnings to warning template', function () {
	$notifier = new TestableS3LogMonitorEmailNotifier();

	expect($notifier->prefixFor('pass_with_warnings'))->toBe(S3LogMonitorEmailNotifier::WARNING_LANGUAGE_PREFIX);
	expect($notifier->prefixFor('pass'))->toBe(S3LogMonitorEmailNotifier::LANGUAGE_PREFIX);
	expect($notifier->prefixFor('fail'))->toBe(S3LogMonitorEmailNotifier::LANGUAGE_PREFIX);
});

test('s3 log monitor warning email variables identify warning folders and counts', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path' => 'qtsp-logs/dr/hsm/hsmdr',
		'label'     => 'HSM DR',
	));
	$rows = array(new S3LogMonitorResultRow($rule, 'warning', 'No logs found in the last 7 days'));
	$summary = array(
		'folders_checked'  => '1',
		'folders_passed'   => '0',
		'folders_warnings' => '1',
		'folders_failed'   => '0',
		'folders_errors'   => '0',
	);

	$vars = (new TestableS3LogMonitorEmailNotifier())->templateVars($summary, $rows);

	expect($vars['folders_warnings'])->toBe('1');
	expect($vars['folders_failed'])->toBe('0');
	expect($vars['warning_details'])->toContain('HSM DR');
	expect($vars['warning_details'])->toContain('qtsp-logs/dr/hsm/hsmdr');
	expect($vars['warning_details'])->toContain('No logs found');
});
