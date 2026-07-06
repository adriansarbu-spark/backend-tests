<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/Support/S3LogMonitorTestSupport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRunStore.php';

test('s3 log monitor run store skips persistence when runs table is missing', function () {
	$db = new S3LogMonitorFakeDb(array(), true, false);
	$registry = new S3LogMonitorFakeRegistry($db);
	$store = new S3LogMonitorRunStore($registry);

	$runId = $store->startRun('ops@example.com');

	expect($runId)->toBe(0);
	expect($db->hasInsertQuery())->toBeFalse();
});

test('s3 log monitor run store records insert queries on fake db without persisting', function () {
	$db = new S3LogMonitorFakeDb();
	$registry = new S3LogMonitorFakeRegistry($db);
	$store = new S3LogMonitorRunStore($registry);
	$rule = new S3LogMonitorRule(array(
		's3_log_monitor_folder_id' => 5,
		'base_path'                => 'qtsp-logs/prod/hsm/hsm01',
		'allowed_days'             => 7,
	));
	$row = new S3LogMonitorResultRow($rule, 'pass', '', '2026-06-17');

	$runId = $store->startRun('a@example.com, b@example.com');
	$store->insertCheck($runId, $row, '2026-06-17 08:00:00');
	$store->finishRun($runId, array(
		'folders_checked' => 1,
		'folders_passed'  => 1,
		'folders_failed'  => 0,
		'folders_errors'  => 0,
	), true);

	expect($runId)->toBe(99);
	$sql = implode("\n", $db->queries);
	expect($sql)->toContain('INSERT INTO `s3_log_monitor_runs`');
	expect($sql)->toContain('INSERT INTO `s3_log_monitor_checks`');
	expect($sql)->toContain('UPDATE `s3_log_monitor_runs`');
});
