<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once DIR_SYSTEM . 'library/checkin.php';
require_once __DIR__ . '/_support/CheckinConsiderCatalogTestDoubles.php';

/**
 * Unit tests: CheckinService::buildVerificationDeclinePayload structured
 * concern capture (params extracted from payload fields, not vendor prose).
 */

function checkin_service_with_db_double(): CheckinService
{
    $registry = new CheckinCatalogRegistryDouble(['db' => new CheckinCatalogDbDouble()]);

    return new CheckinService($registry);
}

test('buildVerificationDeclinePayload attaches structured params to DC047 from documentDataChecking', function () {
    $service = checkin_service_with_db_double();

    $json = $service->buildVerificationDeclinePayload([
        'overallResult' => [
            'status' => 'declined',
            'validationDate' => '2026-07-20T10:00:00Z',
            'concerns' => [
                ['id' => 'DC045', 'service' => 'doc-check', 'status' => 'declined', 'message' => 'Found 1 issue(s)'],
                ['id' => 'DC047', 'service' => 'doc-check', 'status' => 'declined', 'message' => 'Fields from ocr and mrz have conflict: 2031-08-03, 2031-12-03'],
            ],
        ],
        'servicesResults' => [
            'docCheck' => [
                'documentDataChecking' => [
                    ['category' => 'Document number', 'equal' => true, 'conflicts' => []],
                    ['category' => 'Date of expiry', 'equal' => false, 'conflicts' => ['ocr', 'mrz'], 'ocr' => '2031-08-03', 'mrz' => '2031-12-03'],
                ],
            ],
        ],
    ]);

    $payload = json_decode((string) $json, true);
    $byId = array_column($payload['concerns'], null, 'id');

    expect($payload['overall_status'])->toBe('declined')
        ->and($byId['DC047']['params'])->toBe([
            'field' => 'Date of expiry',
            'sources' => 'ocr/mrz',
            'value_ocr' => '2031-08-03',
            'value_mrz' => '2031-12-03',
        ])
        ->and($byId['DC045'])->not->toHaveKey('params');
});

test('buildVerificationDeclinePayload returns null without an overallResult', function () {
    $service = checkin_service_with_db_double();

    expect($service->buildVerificationDeclinePayload(['someKey' => 'x']))->toBeNull();
});
