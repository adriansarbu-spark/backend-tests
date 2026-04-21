<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping templates parties/smartfields integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * End-to-end CRUD for signers (parties) and smartfields on user templates:
 * POST create with parties + smartfields, GET, PUT replace both, GET, DELETE.
 */

test('templates flow: create with parties and smartfields, update via PUT, verify on GET', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();
    $name = 'Parties/SF flow ' . gmdate('YmdHis');

    $partiesCreate = [
        [
            'code' => 'customer',
            'label' => 'Customer',
            'signing_order' => 1,
        ],
    ];
    $smartfieldsCreate = [
        [
            'field_key' => 'full_name',
            'label' => 'Full name',
            'type' => 'text',
            'required' => true,
        ],
    ];

    [$cSt, $cJson, $cRaw] = TemplatesApiHelper::rawCreateTemplate(
        $bearer,
        [
            'name' => $name,
            'content' => '<p>Contract body</p>',
            'category_code' => 'contracts',
            'visibility' => 'private',
            'parties' => $partiesCreate,
            'smartfields' => $smartfieldsCreate,
        ],
        $apiBase
    );
    $cDebug = "Status={$cSt}\n" . substr((string)$cRaw, 0, 1200);
    expect($cSt, "Create with parties/smartfields failed.\n{$cDebug}")->toBe(200);
    expect(is_array($cJson))->toBeTrue();
    $uuid = (string)($cJson['data']['uuid'] ?? '');
    expect($uuid)->not->toBe('');

    [$g1St, $g1Json, $g1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $bearer
    );
    $g1Debug = "Status={$g1St}\n" . substr((string)$g1Raw, 0, 1500);
    expect($g1St, "GET after create failed.\n{$g1Debug}")->toBe(200);
    $p1 = (array)($g1Json['data']['parties'] ?? []);
    expect(count($p1))->toBe(1);
    expect((string)($p1[0]['code'] ?? ''))->toBe('customer');
    expect((string)($p1[0]['label'] ?? ''))->toBe('Customer');
    expect((int)($p1[0]['signing_order'] ?? 0))->toBe(1);

    $s1 = (array)($g1Json['data']['smartfields'] ?? []);
    expect(count($s1))->toBe(1);
    expect((string)($s1[0]['field_key'] ?? ''))->toBe('full_name');
    expect((string)($s1[0]['type'] ?? ''))->toBe('text');

    $partiesUpdate = [
        [
            'code' => 'vendor',
            'label' => 'Vendor',
            'signing_order' => 1,
        ],
        [
            'code' => 'customer',
            'label' => 'Customer',
            'signing_order' => 2,
        ],
    ];
    $smartfieldsUpdate = [
        [
            'field_key' => 'amount',
            'label' => 'Amount',
            'type' => 'text',
            'required' => false,
        ],
    ];

    TemplatesApiHelper::updateTemplateForFlow(
        $bearer,
        $uuid,
        [
            'parties' => $partiesUpdate,
            'smartfields' => $smartfieldsUpdate,
        ],
        $apiBase
    );

    [$g2St, $g2Json, $g2Raw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $bearer
    );
    $g2Debug = "Status={$g2St}\n" . substr((string)$g2Raw, 0, 1500);
    expect($g2St, "GET after PUT failed.\n{$g2Debug}")->toBe(200);

    $p2 = (array)($g2Json['data']['parties'] ?? []);
    expect(count($p2))->toBe(2);
    expect((string)($p2[0]['code'] ?? ''))->toBe('vendor');
    expect((int)($p2[0]['signing_order'] ?? 0))->toBe(1);
    expect((string)($p2[1]['code'] ?? ''))->toBe('customer');

    $s2 = (array)($g2Json['data']['smartfields'] ?? []);
    expect(count($s2))->toBe(1);
    expect((string)($s2[0]['field_key'] ?? ''))->toBe('amount');

    [$dSt, $dJson, $dRaw] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($dSt, substr((string)$dRaw, 0, 600))->toBe(200);
    expect((bool)($dJson['data']['deleted'] ?? false))->toBeTrue();
});
