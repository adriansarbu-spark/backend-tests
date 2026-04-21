<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping add-library-to-my-templates integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * POST /library/{uuid}/add-to-my-templates → user template with copied content/parties/smartfields.
 *
 * @see templates-api-testing-strategy §3.3 — uses ApiAuthHelper; skips when no published library rows exist.
 */

test('add library to my templates: POST creates draft and appears on GET /templates and GET /templates/{uuid}', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $templatesBase = TemplatesApiHelper::apiBase();

    [$listSt, $listJson, $listRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?status=published&page=1&per_page=50&sort=date_added&order=DESC',
        $bearer
    );
    $listDebug = "Status={$listSt}\n" . substr((string)$listRaw, 0, 1200);
    expect($listSt, "List library failed.\n{$listDebug}")->toBe(200);
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
    expect($addSt, "add-to-my-templates failed.\n{$addDebug}")->toBe(200);
    expect(is_array($addJson))->toBeTrue();

    $newUuid = (string)($addJson['data']['uuid'] ?? '');
    expect($newUuid, "Expected data.uuid in add-to-my-templates response.\n{$addDebug}")->not->toBe('');
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
    expect($templatesListSt, "List templates after add failed.\n{$tlDebug}")->toBe(200);

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
    expect($foundInList, "New template uuid not found in GET /templates.\n{$tlDebug}")->toBeTrue();

    [$getSt, $getJson, $getRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $templatesBase . '/' . rawurlencode($newUuid),
        $bearer
    );
    $getDebug = "Status={$getSt}\n" . substr((string)$getRaw, 0, 1500);
    expect($getSt, "GET template after add failed.\n{$getDebug}")->toBe(200);
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

test('add library to my templates: unknown library uuid returns 404', function () {
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
