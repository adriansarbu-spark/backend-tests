<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TeamApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no team members API calls run.
     */
    test('Team members - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak and `TEST_USER_1_*` are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    TeamApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - File guard passed; anonymous HTTP client.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/members` without Authorization.
 * 2. Expect **HTTP 401** or **HTTP 404** (`user_not_found`) and non-empty **`error`** when JSON is returned.
 */
test('Team members - listing without a token is refused', function () {
    [$status, $json, $raw] = TeamApiHelper::requestWithoutAuth('GET', TeamApiHelper::membersUrl());
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 400);

    expect(in_array($status, [401, 404], true))->toBeTrue(
        'Expected refusal for GET without token. ' . $debug
    );
    if (is_array($json)) {
        expect(TeamApiHelper::joinedErrors($json))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in user with an active company context (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/members?member_status=active&page=1&per_page=50`.
 * 2. Expect **HTTP 200**, **`data.company_uuid`**, **`data.members`** as a list, and **`pagination.total`**.
 * 3. For each member row, assert **`role_uuid`**, **`role_code`**, **`email`**, **`member_status`**, and nested **`representative`** with a **`status`** key (UUID identity on the wire; no internal numeric ids).
 */
test('Team members - signed-in user can list active members with role UUIDs', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = TeamApiHelper::get(
        TeamApiHelper::membersUrl() . '?member_status=active&page=1&per_page=50',
        $bearer
    );

    $jsonText = is_array($json) ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'null';
    $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, "GET members failed.\n{$debug}");
    expect(is_array($json))->toBeTrue($debug);
    expect((string)($json['data']['company_uuid'] ?? ''))->not->toBe('', $debug);

    $members = (array)($json['data']['members'] ?? []);
    expect($members)->toBeArray();
    expect($json['pagination'] ?? null)->toBeArray();
    expect(isset($json['pagination']['total']))->toBeTrue($debug);

    foreach ($members as $idx => $m) {
        expect(is_array($m))->toBeTrue("Member {$idx} must be an object.\n{$debug}");
        expect((string)($m['role_uuid'] ?? ''))->not->toBe('');
        expect((string)($m['role_code'] ?? ''))->not->toBe('');
        expect($m)->toHaveKey('email');
        expect((string)($m['member_status'] ?? ''))->not->toBe('');
        expect(isset($m['representative']) && is_array($m['representative']))->toBeTrue($debug);
        expect(array_key_exists('status', (array)$m['representative']))->toBeTrue($debug);
    }
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. Send **PUT** to `/publicapi/v1/team/members` (unsupported for the list route).
 * 2. Expect **HTTP 405** and **`method_not_allowed`** when JSON carries **`error`**.
 */
test('Team members - unsupported HTTP method on list route returns 405', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = ApiAuthHelper::apiRequest('PUT', TeamApiHelper::membersUrl(), $bearer);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(405, $debug);
    if (is_array($json)) {
        expect(TeamApiHelper::joinedErrors($json))->toContain('method_not_allowed');
    }
});

/**
 * Prerequisites:
 * - Signed-in company admin (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/members/{role_uuid}/terminate` (terminate must use **POST**).
 * 2. Expect **HTTP 405** so the terminate action cannot be triggered via a browser-style **GET** alone.
 */
test('Team members - terminate URL with GET returns 405', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $dummyUuid = '00000000-0000-4000-a000-000000000001';
    [$status, , $raw] = TeamApiHelper::get(TeamApiHelper::membersTerminateUrl($dummyUuid), $bearer);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(405, $debug);
});
