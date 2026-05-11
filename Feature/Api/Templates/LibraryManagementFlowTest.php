<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - `SKIP_INTEGRATION_TESTS` is true in `tests_config.php`.
     *
     * Steps:
     * 1. Mark skipped so the file still lists a placeholder when integration is off.
     */
    test('Skipping library management integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests enabled; `TemplatesApiHelper::assertRequiredConfigOrSkip()` passed in `beforeAll`.
 * - Bearer for `TEST_USER_1_*` with permission to POST/PUT/PUBLISH/ARCHIVE/DELETE on library (otherwise 403 skip on create).
 *
 * Steps:
 * 1. POST create library draft with name, language, content, category; assert 200 and `data.uuid`.
 * 2. GET library by uuid; assert name and `status` draft.
 * 3. PUT update `content`; assert 200.
 * 4. POST publish; assert `data.status` published.
 * 5. POST archive; assert `data.archived` true.
 * 6. DELETE; assert `data.deleted` true; GET same uuid returns 404.
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
 * - Integration tests enabled; library API config OK; bearer for `TEST_USER_1_*`.
 * - POST library allowed for user (403 → skip).
 *
 * Steps:
 * 1. POST create with `language_id` and `content` but omit `name`.
 * 2. Assert 422; if structured `error`, assert `VALIDATION_ERROR` and `field` `name`; else assert non-empty error payload.
 */
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
