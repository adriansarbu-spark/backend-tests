<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/Support/S3LogMonitorTestSupport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3ObjectListerInterface.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogScanner.php';

test('s3 log scanner builds zero-padded date prefixes', function () {
	$day = new DateTimeImmutable('2026-06-07 12:00:00', new DateTimeZone('UTC'));

	expect(S3LogScanner::buildDatePrefix('qtsp_logs/prod/ejbca/ejbca01', $day))
		->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/07/');
});

test('s3 log scanner finds logs for today', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path' => 'qtsp_logs/prod/ejbca/ejbca01',
		'timezone'  => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array(
		'qtsp_logs/prod/ejbca/ejbca01/2026/06/17/' => array(
			'KeyCount' => 1,
			'Contents' => array(
				array(
					'Key'          => 'qtsp_logs/prod/ejbca/ejbca01/2026/06/17/server.log',
					'LastModified' => '2026-06-17T01:00:00+00:00',
				),
			),
		),
	));
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 2);

	expect($result['found'])->toBeTrue();
	expect($result['last_upload_date'])->toBe('2026-06-17');
	expect($result['matched_prefix'])->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/17/');
	expect($result['last_key'])->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/17/server.log');
	expect($result['days_scanned'])->toBe(1);
	expect($result['checked_prefixes'])->toBe(array('qtsp_logs/prod/ejbca/ejbca01/2026/06/17/'));
	expect($lister->calls)->toHaveCount(1);
	expect($lister->calls[0]['MaxKeys'])->toBe(1);
});

test('s3 log scanner uses yesterday when today is empty', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path' => 'qtsp_logs/prod/ejbca/ejbca01',
		'timezone'  => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array(
		'qtsp_logs/prod/ejbca/ejbca01/2026/06/17/' => array('Contents' => array()),
		'qtsp_logs/prod/ejbca/ejbca01/2026/06/16/' => array(
			'KeyCount' => 1,
			'Contents' => array(
				array(
					'Key'          => 'qtsp_logs/prod/ejbca/ejbca01/2026/06/16/app.log',
					'LastModified' => '2026-06-16T23:10:00+00:00',
				),
			),
		),
	));
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 2);

	expect($result['found'])->toBeTrue();
	expect($result['last_upload_date'])->toBe('2026-06-16');
	expect($result['matched_prefix'])->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/16/');
	expect($result['days_scanned'])->toBe(2);
	expect($lister->calls[0]['Prefix'])->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/17/');
	expect($lister->calls[1]['Prefix'])->toBe('qtsp_logs/prod/ejbca/ejbca01/2026/06/16/');
});

test('s3 log scanner stops after first match and does not scan older prefixes', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'timezone' => 'UTC'));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array(
		'dr/hsm/hsmdr/2026/06/17/' => array(
			'Contents' => array(
				array('Key' => 'dr/hsm/hsmdr/2026/06/17/app.log'),
			),
		),
		'dr/hsm/hsmdr/2026/06/16/' => array(
			'Contents' => array(
				array('Key' => 'dr/hsm/hsmdr/2026/06/16/older.log'),
			),
		),
	));
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 3);

	expect($result['found'])->toBeTrue();
	expect($result['days_scanned'])->toBe(1);
	expect($lister->calls)->toHaveCount(1);
});

test('s3 log scanner returns failure details when lookback window is exhausted', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'timezone' => 'UTC'));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array());
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 2);

	expect($result['found'])->toBeFalse();
	expect($result['last_upload_date'])->toBeNull();
	expect($result['matched_prefix'])->toBeNull();
	expect($result['days_scanned'])->toBe(2);
	expect($result['scan_to'])->toBe('2026-06-17');
	expect($result['scan_from'])->toBe('2026-06-16');
	expect($result['checked_prefixes'])->toBe(array(
		'dr/hsm/hsmdr/2026/06/17/',
		'dr/hsm/hsmdr/2026/06/16/',
	));
	expect($lister->calls)->toHaveCount(2);
});

test('s3 log scanner generates prefixes across month and year boundaries', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path' => 'qtsp_logs/prod/ejbca/ejbca01',
		'timezone'  => 'UTC',
	));
	$now = new DateTimeImmutable('2026-03-02 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array());
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 3);

	expect($result['checked_prefixes'])->toBe(array(
		'qtsp_logs/prod/ejbca/ejbca01/2026/03/02/',
		'qtsp_logs/prod/ejbca/ejbca01/2026/03/01/',
		'qtsp_logs/prod/ejbca/ejbca01/2026/02/28/',
	));
	expect($result['scan_to'])->toBe('2026-03-02');
	expect($result['scan_from'])->toBe('2026-02-28');
});

test('s3 log scanner ignores empty folder marker objects', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'timezone' => 'UTC'));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array(
		'dr/hsm/hsmdr/2026/06/17/' => array(
			'Contents' => array(
				array('Key' => 'dr/hsm/hsmdr/2026/06/17/'),
			),
		),
		'dr/hsm/hsmdr/2026/06/16/' => array(
			'Contents' => array(
				array('Key' => 'dr/hsm/hsmdr/2026/06/16/app.log'),
			),
		),
	));
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 2);

	expect($result['found'])->toBeTrue();
	expect($result['last_upload_date'])->toBe('2026-06-16');
	expect($result['days_scanned'])->toBe(2);
});

test('s3 log scanner filters by filename pattern when configured', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path'        => 'prod/ejbca/ejbca01',
		'filename_pattern' => '/\.log$/',
		'timezone'         => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$lister = new FakeS3ObjectLister(array(
		'prod/ejbca/ejbca01/2026/06/17/' => array(
			'Contents' => array(
				array('Key' => 'prod/ejbca/ejbca01/2026/06/17/readme.txt'),
				array(
					'Key'          => 'prod/ejbca/ejbca01/2026/06/17/server.log',
					'LastModified' => '2026-06-17T01:00:00+00:00',
				),
			),
		),
	));
	$scanner = new S3LogScanner($lister);

	$result = $scanner->findLastUpload($rule, 'logs-bucket', $now, 1);

	expect($result['found'])->toBeTrue();
	expect($result['last_upload_date'])->toBe('2026-06-17');
	expect($result['last_key'])->toBe('prod/ejbca/ejbca01/2026/06/17/server.log');
	expect($lister->calls[0]['MaxKeys'])->toBe(50);
});

test('s3 log monitor config uses rule lookback_days when set', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path'     => 'dr/hsm/hsmdr',
		'window_days'   => 7,
		'lookback_days' => 14,
	));

	expect(S3LogMonitorConfig::lookbackDaysForRule($rule))->toBe(14);
});

test('s3 log monitor config falls back to window_days when lookback_days is not set', function () {
	$rule = new S3LogMonitorRule(array(
		'base_path'   => 'dr/hsm/hsmdr',
		'window_days' => 7,
	));

	expect(S3LogMonitorConfig::lookbackDaysForRule($rule))->toBe(7);
});
