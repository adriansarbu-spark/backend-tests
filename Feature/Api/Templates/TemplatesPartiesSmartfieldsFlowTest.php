<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no templates API calls run.
     */
    test('Templates - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; templates API env matches `tests_config.php`.
 *
 * Steps:
 * 1. Ask `TemplatesApiHelper` to confirm required configuration; if missing, skip the whole file with a clear reason.
 */
beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in owner (`TEST_USER_1_*`); file guard passed (`beforeAll`).
 *
 * Steps:
 * 1. Create a template with signing parties and smartfields; expect **HTTP 200** and **`data.uuid`**.
 * 2. Open the template; check party codes, labels, signing order, and smartfield keys/types.
 * 3. Replace parties and smartfields with a save (helper).
 * 4. Open again; counts, order, and **`field_key`** should match the update.
 * 5. Delete the template; expect **`data.deleted`** true.
 */
test('Templates - parties and smartfields round-trip on create, update, and read', function () {
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
            'language_id' => 1,
            'category_code' => 'contracts',
            'visibility' => 'private',
            'parties' => $partiesCreate,
            'smartfields' => $smartfieldsCreate,
        ],
        $apiBase
    );
    expect($cSt)->toBe(200, "Create with parties/smartfields failed.\nStatus={$cSt}\n" . substr((string)$cRaw, 0, 1200));
    expect(is_array($cJson))->toBeTrue();
    $uuid = (string)($cJson['data']['uuid'] ?? '');
    expect($uuid)->not->toBe('');

    [$g1St, $g1Json, $g1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($g1St)->toBe(200, "GET after create failed.\nStatus={$g1St}\n" . substr((string)$g1Raw, 0, 1500));
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
    expect($g2St)->toBe(200, "GET after PUT failed.\nStatus={$g2St}\n" . substr((string)$g2Raw, 0, 1500));

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
    expect($dSt)->toBe(200, substr((string)$dRaw, 0, 600));
    expect((bool)($dJson['data']['deleted'] ?? false))->toBeTrue();
});
