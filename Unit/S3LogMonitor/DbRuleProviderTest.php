<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/Support/S3LogMonitorTestSupport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/DbRuleProvider.php';

test('db rule provider maps enabled folder rows to rule dtos', function () {
	$db = new S3LogMonitorFakeDb(array(
		array(
			's3_log_monitor_folder_id' => 7,
			'base_path'                => 'qtsp-logs/prod/ejbca/ejbca01',
			'label'                    => 'EJBCA prod',
			'bucket'                   => null,
			'timezone'                 => 'UTC',
			'filename_pattern'         => null,
			'allowed_days'             => 1,
			'should_send_warnings'     => 1,
			'enabled'                  => 1,
		),
	));
	$registry = new S3LogMonitorFakeRegistry($db);
	$provider = new DbRuleProvider($registry);

	$rules = $provider->getRules();

	expect($rules)->toHaveCount(1);
	expect($rules[0])->toBeInstanceOf(S3LogMonitorRule::class);
	expect($rules[0]->folder_id)->toBe(7);
	expect($rules[0]->base_path)->toBe('qtsp-logs/prod/ejbca/ejbca01');
	expect($rules[0]->allowed_days)->toBe(1);
	expect($rules[0]->should_send_warnings)->toBeTrue();
	expect($rules[0]->grace_hours)->toBe(24);
	expect($db->hasInsertQuery())->toBeFalse();
});

test('db rule provider returns empty list when folders table is missing', function () {
	$db = new S3LogMonitorFakeDb(array(), false);
	$registry = new S3LogMonitorFakeRegistry($db);
	$provider = new DbRuleProvider($registry);

	$rules = $provider->getRules();

	expect($rules)->toBe(array());
	expect($db->hasInsertQuery())->toBeFalse();
});
