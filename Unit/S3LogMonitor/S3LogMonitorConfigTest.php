<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/Support/S3LogMonitorTestSupport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';

afterEach(function () {
	s3LogMonitorResetConfigCache();
	putenv('S3_LOG_MONITOR_EMAIL');
});

test('s3 log monitor config reads notification emails from emails array', function () {
	s3LogMonitorSetConfigValues(array(
		's3_log_monitor_emails' => array(
			'alexandru.zamfir@simplifi.ro',
			'alexandru.zamfir+1@simplifi.ro',
		),
	));

	expect(S3LogMonitorConfig::getNotificationEmails())->toBe(array(
		'alexandru.zamfir@simplifi.ro',
		'alexandru.zamfir+1@simplifi.ro',
	));
	expect(S3LogMonitorConfig::getNotificationEmail())->toBe('alexandru.zamfir@simplifi.ro');
});

test('s3 log monitor config falls back to single email key when emails array is empty', function () {
	s3LogMonitorSetConfigValues(array(
		's3_log_monitor_email' => 'alexandru.zamfir@simplifi.ro',
	));

	expect(S3LogMonitorConfig::getNotificationEmails())->toBe(array('alexandru.zamfir@simplifi.ro'));
});

test('s3 log monitor config default grace hours is one day', function () {
	s3LogMonitorSetConfigValues(array());

	expect(S3LogMonitorConfig::getDefaultGraceHours())->toBe(24);
});
