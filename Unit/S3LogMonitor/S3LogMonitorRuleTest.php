<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';

test('s3 log monitor rule applies default grace hours from db row', function () {
	$rule = S3LogMonitorRule::fromDbRow(array(
		's3_log_monitor_folder_id' => 3,
		'base_path'                => 'qtsp-logs/dr/hsm/hsmdr',
		'label'                    => 'HSM DR',
		'allowed_days'             => 7,
		'timezone'                 => 'UTC',
	));

	expect($rule->folder_id)->toBe(3);
	expect($rule->window_days)->toBe(7);
	expect($rule->grace_hours)->toBe(24);
});

test('s3 log monitor rule keeps explicit zero grace hours', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'qtsp-logs/prod/ejbca/ejbca01',
		'window_days' => 1,
		'grace_hours' => 0,
	));

	expect($rule->grace_hours)->toBe(0);
});
