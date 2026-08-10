<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/Support/S3LogMonitorTestSupport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3ObjectListerInterface.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogScanner.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorService.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorEmailNotifier.php';

afterEach(function () {
	s3LogMonitorResetConfigCache();
});

test('s3 log monitor service queues email to each configured recipient', function () {
	s3LogMonitorSetConfigValues(array(
		's3_log_monitor_emails' => array(
			'alexandru.zamfir@simplifi.ro',
			'alexandru.zamfir+1@simplifi.ro',
		),
	));

	$rule = new S3LogMonitorRule(array(
		's3_log_monitor_folder_id' => 1,
		'base_path'                => 'qtsp-logs/prod/ejbca/ejbca01',
		'allowed_days'             => 1,
		'timezone'                 => 'UTC',
	));
	$now = new DateTimeImmutable('2026-06-17 08:00:00', new DateTimeZone('UTC'));
	$prefix = S3LogScanner::buildDatePrefix($rule->base_path, $now);
	$lister = new FakeS3ObjectLister(array(
		$prefix => array(
			'KeyCount' => 1,
			'Contents' => array(
				array(
					'Key'          => $prefix . 'server.log',
					'LastModified' => '2026-06-17T01:00:00+00:00',
				),
			),
		),
	));
	$runStore = new S3LogMonitorRecordingRunStore();
	$notifier = new S3LogMonitorRecordingEmailNotifier();
	$registry = new Registry();
	$service = new S3LogMonitorService(
		new S3LogMonitorStaticRuleProvider(array($rule)),
		$lister,
		$notifier,
		$registry,
		$runStore
	);

	$result = $service->run(false);

	expect($result['email_sent'])->toBeTrue();
	expect($notifier->recipients)->toBe(array(
		'alexandru.zamfir@simplifi.ro',
		'alexandru.zamfir+1@simplifi.ro',
	));
	expect($runStore->started)->toBeTrue();
	expect($runStore->startEmail)->toBe('alexandru.zamfir@simplifi.ro, alexandru.zamfir+1@simplifi.ro');
	expect($runStore->checks)->toHaveCount(1);
	expect($runStore->finished)->toBeTrue();
	expect($runStore->emailSent)->toBeTrue();
});

test('s3 log monitor service records per-folder checks without touching a real database', function () {
	s3LogMonitorSetConfigValues(array(
		's3_log_monitor_emails' => array('ops@example.com'),
	));

	$rule = new S3LogMonitorRule(array(
		's3_log_monitor_folder_id' => 2,
		'base_path'                => 'qtsp-logs/prod/hsm/hsm01',
		'allowed_days'             => 7,
		'timezone'                 => 'UTC',
	));
	$lister = new FakeS3ObjectLister(array());
	$runStore = new S3LogMonitorRecordingRunStore();
	$notifier = new S3LogMonitorRecordingEmailNotifier();
	$registry = new Registry();
	$service = new S3LogMonitorService(
		new S3LogMonitorStaticRuleProvider(array($rule)),
		$lister,
		$notifier,
		$registry,
		$runStore
	);

	$result = $service->run(true);

	expect($result['rows'])->toHaveCount(1);
	expect($result['rows'][0]->status)->toBe('fail');
	expect($runStore->checks[0]['folder_id'])->toBe(2);
	expect($runStore->checks[0]['allowed_days'])->toBe(7);
	expect($notifier->recipients)->toBe(array());
});

test('s3 log monitor service downgrades freshness failure to warning for opted-in folder', function () {
	$rule = new S3LogMonitorRule(array(
		's3_log_monitor_folder_id' => 3,
		'base_path'                => 'qtsp-logs/dr/hsm/hsmdr',
		'allowed_days'             => 7,
		'timezone'                 => 'UTC',
		'should_send_warnings'     => 1,
	));
	$runStore = new S3LogMonitorRecordingRunStore();
	$service = new S3LogMonitorService(
		new S3LogMonitorStaticRuleProvider(array($rule)),
		new FakeS3ObjectLister(array()),
		new S3LogMonitorRecordingEmailNotifier(),
		new Registry(),
		$runStore
	);

	$result = $service->run(true);

	expect($result['rows'][0]->status)->toBe('warning');
	expect($result['summary']['run_status'])->toBe('pass_with_warnings');
	expect($result['summary']['folders_warnings'])->toBe('1');
	expect($result['summary']['folders_failed'])->toBe('0');
	expect($runStore->checks[0]['status'])->toBe('warning');
});

test('s3 log monitor service never downgrades s3 errors to warnings', function () {
	$rule = new S3LogMonitorRule(array(
		's3_log_monitor_folder_id' => 4,
		'base_path'                => 'qtsp-logs/dr/sam/samdr',
		'should_send_warnings'     => 1,
	));
	$lister = new class implements S3ObjectListerInterface {
		public function listObjectsV2(array $params) {
			throw new RuntimeException('S3 unavailable');
		}
	};
	$service = new S3LogMonitorService(
		new S3LogMonitorStaticRuleProvider(array($rule)),
		$lister,
		new S3LogMonitorRecordingEmailNotifier(),
		new Registry(),
		new S3LogMonitorRecordingRunStore()
	);

	$result = $service->run(true);

	expect($result['rows'][0]->status)->toBe('error');
	expect($result['summary']['run_status'])->toBe('fail');
	expect($result['summary']['folders_errors'])->toBe('1');
});
