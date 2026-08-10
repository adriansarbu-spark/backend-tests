<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/cron/jobs/S3LogMonitorJob.php';

test('s3 log monitor cron treats warnings as successful outcomes', function () {
	$method = new ReflectionMethod(S3LogMonitorJob::class, 'isFailureStatus');
	$method->setAccessible(true);

	expect($method->invoke(null, 'warning'))->toBeFalse();
	expect($method->invoke(null, 'pass'))->toBeFalse();
	expect($method->invoke(null, 'fail'))->toBeTrue();
	expect($method->invoke(null, 'error'))->toBeTrue();
});
