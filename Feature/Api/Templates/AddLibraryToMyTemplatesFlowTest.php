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
     * 1. Mark this placeholder as skipped so no templates or library API calls run.
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
 * - Signed-in owner (`TEST_USER_1_*`); file guard passed (`beforeAll`).
 * - At least one **published** library row exists (otherwise skip).
 *
 * Steps:
 * 1. Pick the first published library item’s id (and name when present).
 * 2. Add it to “my templates”; expect **HTTP 200**, a **new** template **`data.uuid`**, **`data.status`** **draft**, **`version_number`** **1**, and matching name when known.
 * 3. Open the personal template list; the **new** id must appear in **`data`**.
 * 4. Open that template by id; expect non-empty **`content`**, **`parties`** and **`smartfields`** arrays, and **`visibility`** **private**.
 */
test('Templates - add from library creates a private draft in my templates', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $templatesBase = TemplatesApiHelper::apiBase();

    [$listSt, $listJson, $listRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?status=published&page=1&per_page=50&sort=date_added&order=DESC',
        $bearer
    );
    expect($listSt)->toBe(200, "List library failed.\nStatus={$listSt}\n" . substr((string)$listRaw, 0, 1200));
    expect(is_array($listJson))->toBeTrue();

    $libraryUuid = '';
    $libraryName = '';
    foreach ((array)($listJson['data'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $u = (string)($row['uuid'] ?? '');
        if ($u !== '') {
            $libraryUuid = $u;
            $libraryName = (string)($row['name'] ?? '');
            break;
        }
    }

    if ($libraryUuid === '') {
        test()->markTestSkipped('No published library template available for add-to-my-templates flow');
    }

    [$addSt, $addJson, $addRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($libraryUuid) . '/add-to-my-templates',
        $bearer
    );
    $addDebug = "Status={$addSt}\n" . substr((string)$addRaw, 0, 1500);
    expect($addSt)->toBe(200, "add-to-my-templates failed.\n{$addDebug}");
    expect(is_array($addJson))->toBeTrue();

    $newUuid = (string)($addJson['data']['uuid'] ?? '');
    expect($newUuid)->not->toBe('', "Expected data.uuid in add-to-my-templates response.\n{$addDebug}");
    if ($libraryName !== '') {
        expect((string)($addJson['data']['name'] ?? ''))->toBe($libraryName);
    } else {
        expect((string)($addJson['data']['name'] ?? ''))->not->toBe('');
    }
    expect((string)($addJson['data']['status'] ?? ''))->toBe('draft');
    expect((int)($addJson['data']['version_number'] ?? 0))->toBe(1);

    [$templatesListSt, $templatesListJson, $templatesListRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $templatesBase . '?page=1&per_page=100&sort=date_added&order=DESC',
        $bearer
    );
    $tlDebug = "Status={$templatesListSt}\n" . substr((string)$templatesListRaw, 0, 1200);
    expect($templatesListSt)->toBe(200, "List templates after add failed.\n{$tlDebug}");

    $foundInList = false;
    foreach ((array)($templatesListJson['data'] ?? []) as $item) {
        if (is_array($item) && (string)($item['uuid'] ?? '') === $newUuid) {
            $foundInList = true;
            if ($libraryName !== '') {
                expect((string)($item['name'] ?? ''))->toBe($libraryName);
            }
            break;
        }
    }
    expect($foundInList)->toBeTrue("New template uuid not found in GET /templates.\n{$tlDebug}");

    [$getSt, $getJson, $getRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $templatesBase . '/' . rawurlencode($newUuid),
        $bearer
    );
    expect($getSt)->toBe(200, "GET template after add failed.\nStatus={$getSt}\n" . substr((string)$getRaw, 0, 1500));
    expect(is_array($getJson))->toBeTrue();
    expect((string)($getJson['data']['uuid'] ?? ''))->toBe($newUuid);
    if ($libraryName !== '') {
        expect((string)($getJson['data']['name'] ?? ''))->toBe($libraryName);
    } else {
        expect((string)($getJson['data']['name'] ?? ''))->not->toBe('');
    }
    expect((string)($getJson['data']['status'] ?? ''))->toBe('draft');
    expect((string)($getJson['data']['visibility'] ?? ''))->toBe('private');
    expect(isset($getJson['data']['content']))->toBeTrue();
    expect(strlen((string)($getJson['data']['content'] ?? '')))->toBeGreaterThan(0);
    expect(isset($getJson['data']['parties']))->toBeTrue();
    expect(is_array($getJson['data']['parties']))->toBeTrue();
    expect(isset($getJson['data']['smartfields']))->toBeTrue();
    expect(is_array($getJson['data']['smartfields']))->toBeTrue();
});

/**
 * Prerequisites:
 * - Normal owner session for templates/library.
 *
 * Steps:
 * 1. Try “add to my templates” with a well-formed library id that does not exist.
 * 2. Expect **not found** (**HTTP 404**); **`error`** non-empty when JSON is returned.
 */
test('Templates - add from library with unknown id returns not found', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $fakeUuid = '00000000-0000-4000-8000-000000000001';

    [$st, $json] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($fakeUuid) . '/add-to-my-templates',
        $bearer
    );

    expect($st)->toBe(404);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    }
});
