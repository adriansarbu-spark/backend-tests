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
     * 1. Mark this placeholder as skipped so no library API calls run.
     */
    test('Library - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; templates/library API env matches `tests_config.php`.
 *
 * Steps:
 * 1. Ask `TemplatesApiHelper` to confirm required configuration; if missing, skip the whole file with a clear reason.
 */
beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - User can create and manage library rows (**HTTP 403** on create means skip); `TEST_USER_1_*`.
 *
 * Steps:
 * 1. Create a draft with name, language, body, and category; expect **HTTP 200** and **`data.uuid`**.
 * 2. Open by id; name matches and **`data.status`** is **draft**.
 * 3. Save a new body; expect **HTTP 200**.
 * 4. Publish; **`data.status`** becomes **published**.
 * 5. Archive; expect **`data.archived`** true.
 * 6. Delete; expect **`data.deleted`** true; opening the same id afterward returns **HTTP 404**.
 */
test('Library - full lifecycle draft → publish → archive → delete', function () {
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
    expect($cSt)->toBe(200, "Create library failed.\n{$cDebug}");
    expect(is_array($cJson))->toBeTrue();
    $uuid = (string)($cJson['data']['uuid'] ?? '');
    expect($uuid)->not->toBe('', "Missing data.uuid.\n{$cDebug}");

    [$gSt, $gJson, $gRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($gSt)->toBe(200, "GET library failed.\nStatus={$gSt}\n" . substr((string)$gRaw, 0, 1200));
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
    expect($uSt)->toBe(200, "PUT library failed.\nStatus={$uSt}\n" . substr((string)$uRaw, 0, 1200));

    [$pSt, $pJson, $pRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );
    expect($pSt)->toBe(200, "Publish library failed.\nStatus={$pSt}\n" . substr((string)$pRaw, 0, 1200));
    expect(is_array($pJson))->toBeTrue();
    expect((string)($pJson['data']['status'] ?? ''))->toBe('published');

    [$aSt, $aJson, $aRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($uuid) . '/archive',
        $bearer
    );
    expect($aSt)->toBe(200, "Archive library failed.\nStatus={$aSt}\n" . substr((string)$aRaw, 0, 1200));
    expect((bool)($aJson['data']['archived'] ?? false))->toBeTrue();

    [$dSt, $dJson, $dRaw] = ApiAuthHelper::apiRequest(
        'DELETE',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($dSt)->toBe(200, "DELETE library failed.\nStatus={$dSt}\n" . substr((string)$dRaw, 0, 1200));
    expect(is_array($dJson))->toBeTrue();
    expect((bool)($dJson['data']['deleted'] ?? false))->toBeTrue();

    [$goneSt] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($goneSt)->toBe(404);
});

/**
 * Prerequisites:
 * - User may POST library rows (**HTTP 403** means skip).
 *
 * Steps:
 * 1. Try to create with language and body but **no** name.
 * 2. Expect **HTTP 422**; structured errors should use **`VALIDATION_ERROR`** on **`name`**, otherwise any non-empty **`error`**.
 */
test('Library - create without name is rejected', function () {
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
