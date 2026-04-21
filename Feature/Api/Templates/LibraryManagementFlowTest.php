<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping library management integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * @simplifi.ro write lifecycle (strategy §3.3): create draft, read, update, publish, archive, delete.
 * Requires POST/PUT on publicapi/v1/esign/tpl/library for the test user.
 */

test('library management: create draft, GET, PUT update, publish, archive, delete', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $suffix = gmdate('YmdHis');
    $name = 'Mgmt flow library ' . $suffix;

    [$cSt, $cJson, $cRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase,
        $bearer,
        [
            'json' => [
                'name' => $name,
                'language_id' => 1,
                'content' => '<p>Library draft content</p>',
                'category_code' => 'general',
                'description' => 'Integration test draft',
            ],
        ]
    );
    $cDebug = "Status={$cSt}\n" . substr((string)$cRaw, 0, 1200);
    if ($cSt === 403) {
        test()->markTestSkipped('Library POST not allowed for test user (403).');
    }
    expect($cSt, "Create library failed.\n{$cDebug}")->toBe(200);
    expect(is_array($cJson))->toBeTrue();
    $uuid = (string)($cJson['data']['uuid'] ?? '');
    expect($uuid, "Missing data.uuid.\n{$cDebug}")->not->toBe('');

    [$gSt, $gJson, $gRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    $gDebug = "Status={$gSt}\n" . substr((string)$gRaw, 0, 1200);
    expect($gSt, "GET library failed.\n{$gDebug}")->toBe(200);
    expect((string)($gJson['data']['name'] ?? ''))->toBe($name);
    expect((string)($gJson['data']['status'] ?? ''))->toBe('draft');

    [$uSt, $uJson, $uRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer,
        [
            'json' => [
                'content' => '<p>Updated library body</p>',
            ],
        ]
    );
    $uDebug = "Status={$uSt}\n" . substr((string)$uRaw, 0, 1200);
    expect($uSt, "PUT library failed.\n{$uDebug}")->toBe(200);

    [$pSt, $pJson, $pRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );
    $pDebug = "Status={$pSt}\n" . substr((string)$pRaw, 0, 1200);
    expect($pSt, "Publish library failed.\n{$pDebug}")->toBe(200);
    expect(is_array($pJson))->toBeTrue();
    expect((string)($pJson['data']['status'] ?? ''))->toBe('published');

    [$aSt, $aJson, $aRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($uuid) . '/archive',
        $bearer
    );
    $aDebug = "Status={$aSt}\n" . substr((string)$aRaw, 0, 1200);
    expect($aSt, "Archive library failed.\n{$aDebug}")->toBe(200);
    expect((bool)($aJson['data']['archived'] ?? false))->toBeTrue();

    [$dSt, $dJson, $dRaw] = ApiAuthHelper::apiRequest(
        'DELETE',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    $dDebug = "Status={$dSt}\n" . substr((string)$dRaw, 0, 1200);
    expect($dSt, "DELETE library failed.\n{$dDebug}")->toBe(200);
    expect(is_array($dJson))->toBeTrue();
    expect((bool)($dJson['data']['deleted'] ?? false))->toBeTrue();

    [$goneSt] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($goneSt)->toBe(404);
});

test('library management: create validation missing name returns 422 with field', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase,
        $bearer,
        [
            'json' => [
                'language_id' => 1,
                'content' => '<p>x</p>',
            ],
        ]
    );
    if ($st === 403) {
        test()->markTestSkipped('Library POST not allowed for test user (403).');
    }
    expect($st)->toBe(422);
    $err = $json['error'] ?? null;
    if (is_array($err) && array_key_exists('code', $err)) {
        expect($err['code'] ?? null)->toBe('VALIDATION_ERROR');
        expect($err['field'] ?? null)->toBe('name');
    } else {
        expect(implode(' ', array_map('strval', (array)$err)))->not->toBe('');
    }
});
