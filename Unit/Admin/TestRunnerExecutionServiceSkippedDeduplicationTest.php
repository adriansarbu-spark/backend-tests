<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../public/system/library/test/TestRunnerExecutionService.php';

test('runner parser normalizes and deduplicates skipped tests before export pipeline', function () {
    $service = new TestRunnerExecutionService('/tmp');
    $reflection = new ReflectionClass(TestRunnerExecutionService::class);
    $method = $reflection->getMethod('parseSkippedTestLines');
    $method->setAccessible(true);

    $lines = array(
        '/var/www/project/tests/auth/LoginTest.php',
        '↩ Login test should skip when user is disabled',
        '- login test should skip when user is disabled',
        '- Login test should skip when user is disabled ',
        '- tests/auth/Login test should skip when user is disabled',
        '- Different skipped test'
    );

    $parsed = $method->invoke($service, $lines, 'tests/auth');

    expect($parsed)->toHaveCount(2);
    expect($parsed)->toContainEqual(array(
        'name' => 'Login test should skip when user is disabled',
        'message' => 'Test skipped',
        'file' => '/var/www/project/tests/auth/LoginTest.php',
        'target_folder' => 'tests/auth'
    ));
    expect($parsed)->toContainEqual(array(
        'name' => 'Different skipped test',
        'message' => 'Test skipped',
        'file' => '/var/www/project/tests/auth/LoginTest.php',
        'target_folder' => 'tests/auth'
    ));
});
