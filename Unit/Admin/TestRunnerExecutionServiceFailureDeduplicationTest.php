<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../public/system/library/test/TestRunnerExecutionService.php';

test('runner parser deduplicates failed unit rows by identity and keeps completed details', function () {
    $service = new TestRunnerExecutionService('/tmp');
    $reflection = new ReflectionClass(TestRunnerExecutionService::class);
    $method = $reflection->getMethod('dedupeFailedResultsByIdentity');
    $method->setAccessible(true);

    $rows = array(
        array(
            'name' => 'Get document file returns payload on success',
            'message' => 'Test failed',
            'file' => '',
            'target_folder' => 'tests/Unit/Api/Signing'
        ),
        array(
            'name' => 'Get document file returns payload on success',
            'message' => 'Error: Call to undefined method MockObject_Document::foo()',
            'file' => '/var/www/project/tests/Unit/Api/Signing/GetDocumentFileTest.php',
            'target_folder' => 'tests/Unit/Api/Signing'
        ),
    );

    $deduped = $method->invoke($service, $rows);

    expect($deduped)->toHaveCount(1);
    expect($deduped[0]['message'] ?? null)->toBe('Error: Call to undefined method MockObject_Document::foo()');
    expect($deduped[0]['file'] ?? null)->toBe('/var/www/project/tests/Unit/Api/Signing/GetDocumentFileTest.php');
});
