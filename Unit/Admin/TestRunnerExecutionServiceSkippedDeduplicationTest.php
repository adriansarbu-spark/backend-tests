<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../public/system/library/test/TestRunnerExecutionService.php';
require_once __DIR__ . '/../../../public/system/library/test/TestJUnitXmlParser.php';

test('junit parser provides file paths for skipped tests used by export dedupe', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Tests\Feature\Api\Auth\LoginTest" file="tests/auth/LoginTest.php" tests="2" skipped="2">
    <testcase name="Login test should skip when user is disabled" file="tests/auth/LoginTest.php::Login test should skip when user is disabled">
      <skipped/>
    </testcase>
    <testcase name="Different skipped test" file="tests/auth/LoginTest.php::Different skipped test">
      <skipped/>
    </testcase>
  </testsuite>
</testsuites>
XML;

    $parser = new TestJUnitXmlParser();
    $parsed = $parser->parse($xml, 'tests/auth', '/var/www/project');
    $service = new TestRunnerExecutionService('/tmp');
    $deduped = $service->dedupeSkippedJson($parsed['skipped_results']);

    expect($deduped)->toHaveCount(2);
    expect($deduped[0]['file'] ?? null)->toBe('/var/www/project/tests/auth/LoginTest.php');
});

test('dedupeSkippedJson collapses duplicate rows from persisted skipped_json shape', function () {
    $service = new TestRunnerExecutionService('/tmp');
    $rows = array(
        array(
            'name' => 'Documents — uncertified account may upload or be blocked (environment…',
            'message' => 'Test skipped',
            'file' => '',
            'target_folder' => 'tests/Feature/Api/Documents',
        ),
        array(
            'name' => 'Documents — uncertified account may upload or be blocked (environment-specific)',
            'message' => 'Test skipped',
            'file' => '/var/www/api01.dev.simplifi.ro/tests/Feature/Api/Documents/DocumentsFlowTest.php',
            'target_folder' => 'tests/Feature/Api/Documents',
        ),
    );

    $deduped = $service->dedupeSkippedJson($rows);

    expect($deduped)->toHaveCount(1);
    expect($deduped[0]['name'] ?? null)->toBe('Documents — uncertified account may upload or be blocked (environment-specific)');
    expect($deduped[0]['file'] ?? null)->toBe('/var/www/api01.dev.simplifi.ro/tests/Feature/Api/Documents/DocumentsFlowTest.php');
});

test('parseSkipReasonsFromOutput recovers Pest arrow and display-skipped reasons', function () {
    $service = new TestRunnerExecutionService('/tmp');
    $output = <<<'OUT'
   WARN  Tests\Feature\Api\Account\AccountCertificatesCrlValidationFlowTest
  - short skip → Missing required test config constant: AUTH_URL
  - Certificates CRL - TEST_USER_1 valid certificate is not revoked in…
  ────────────────────────────────────────────────────────────────────────────
   SKIPPED  Tests\Feature\Api\Account\AccountCertificatesCrlValidationFlowTest > Certificates CRL - TEST_USER_1 valid certific…
  CRL validation flow is unfinished / intentionally disabled (SKIP_CRL_VALIDATION_TESTS=true).

  Tests:    2 skipped (0 assertions)
OUT;

    $reasons = $service->parseSkipReasonsFromOutput($output);

    expect($reasons)->toHaveCount(2);
    expect($reasons[0]['message'] ?? null)->toBe('Missing required test config constant: AUTH_URL');
    expect($reasons[1]['message'] ?? null)->toBe(
        'CRL validation flow is unfinished / intentionally disabled (SKIP_CRL_VALIDATION_TESTS=true).'
    );
});

test('enrichSkippedResultsFromOutput fills generic skipped messages for Excel export', function () {
    $service = new TestRunnerExecutionService('/tmp');
    $skipped = array(
        array(
            'name' => 'Certificates CRL - TEST_USER_1 valid certificate is not revoked in a valid signed CRL',
            'message' => 'Test skipped',
            'file' => '/var/www/api01.dev.simplifi.ro/tests/Feature/Api/Account/AccountCertificatesCrlValidationFlowTest.php',
            'target_folder' => 'tests/Feature/Api/Account',
        ),
    );
    $output = <<<'OUT'
   SKIPPED  Tests\Feature\Api\Account\AccountCertificatesCrlValidationFlowTest > Certificates CRL - TEST_USER_1 valid certific…
  CRL validation flow is unfinished / intentionally disabled (SKIP_CRL_VALIDATION_TESTS=true).
OUT;

    $enriched = $service->enrichSkippedResultsFromOutput($skipped, $output);

    expect($enriched[0]['message'] ?? null)->toBe(
        'CRL validation flow is unfinished / intentionally disabled (SKIP_CRL_VALIDATION_TESTS=true).'
    );
});

test('junit parser reads skipped message attribute when present', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Example" tests="1" skipped="1">
    <testcase name="Example skip" file="tests/ExampleTest.php::Example skip">
      <skipped message="Missing required test config constant: AUTH_URL"/>
    </testcase>
  </testsuite>
</testsuites>
XML;

    $parser = new TestJUnitXmlParser();
    $parsed = $parser->parse($xml, 'tests', '/var/www/project');

    expect($parsed['skipped_results'][0]['message'] ?? null)->toBe('Missing required test config constant: AUTH_URL');
});
