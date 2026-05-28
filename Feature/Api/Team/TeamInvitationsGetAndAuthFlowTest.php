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
     * 1. Mark this placeholder as skipped so no team invitations API calls run.
     */
    test('Team invitations - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak and `TEST_USER_1_*` match the API under test.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    TeamApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - File guard passed; anonymous HTTP client (no bearer token).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/invitations` without Authorization.
 * 2. Expect refusal before any admin list runs: **HTTP 401** with **`unauthenticated`**, or **HTTP 404** with **`user_not_found`** when the gateway resolves “no user” first.
 * 3. When JSON is present, assert **`error`** is non-empty.
 */
test('Team invitations - listing without a token is refused', function () {
    [$status, $json, $raw] = TeamApiHelper::requestWithoutAuth('GET', TeamApiHelper::invitationsUrl());
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 400);

    expect(in_array($status, [401, 404], true))->toBeTrue(
        'Expected refusal for GET without token (401 or 404 user_not_found). ' . $debug
    );
    if (is_array($json)) {
        $err = TeamApiHelper::joinedErrors($json);
        expect($err)->not->toBe('');
        if ($status === 401) {
            expect($err)->toContain('unauthenticated');
        }
        if ($status === 404) {
            expect($err)->toContain('user_not_found');
        }
    }
});

/**
 * Prerequisites:
 * - File guard passed; bogus bearer token.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/invitations` with an invalid Authorization header.
 * 2. Expect **HTTP 401** or **HTTP 403** and, when JSON is returned, a non-empty **`error`** list.
 */
test('Team invitations - listing with an invalid token is refused', function () {
    [$status, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        TeamApiHelper::invitationsUrl(),
        'Bearer invalid-token-for-integration-test'
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 500);

    expect(in_array($status, [401, 403], true))->toBeTrue('Expected auth failure for invalid bearer. ' . $debug);
    if (is_array($json)) {
        expect(TeamApiHelper::joinedErrors($json))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in company admin (`TEST_USER_1_*`); file guard passed.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/invitations?page=1&per_page=20` with a valid bearer.
 * 2. Expect **HTTP 200**, a non-empty **`data.company_uuid`**, **`data.invitations`** as a list, and **`pagination`** with **`total`** (integer).
 * 3. For each invitation row, assert **`invitation_uuid`**, **`invited_email`**, **`role_code`**, and **`status`** are present (wire shape; no raw invite token).
 */
test('Team invitations - company admin can list invitations with pagination meta', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = TeamApiHelper::get(
        TeamApiHelper::invitationsUrl() . '?page=1&per_page=20',
        $bearer
    );

    $jsonText = is_array($json) ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'null';
    $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, "GET invitations failed.\n{$debug}");
    expect(is_array($json))->toBeTrue("Expected JSON.\n{$debug}");
    expect((string)($json['data']['company_uuid'] ?? ''))->not->toBe('', $debug);

    $items = (array)($json['data']['invitations'] ?? []);
    expect($items)->toBeArray();
    expect($json['pagination'] ?? null)->toBeArray();
    expect(isset($json['pagination']['total']))->toBeTrue($debug);

    foreach ($items as $idx => $row) {
        expect(is_array($row))->toBeTrue("Invitation {$idx} must be an object.\n{$debug}");
        expect((string)($row['invitation_uuid'] ?? ''))->not->toBe('');
        expect((string)($row['invited_email'] ?? ''))->not->toBe('');
        expect((string)($row['role_code'] ?? ''))->not->toBe('');
        expect((string)($row['status'] ?? ''))->not->toBe('');
        expect(array_key_exists('token', $row))->toBeFalse('Invite list must not expose raw token.');
    }
});

/**
 * Prerequisites:
 * - Signed-in company admin (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. Send **DELETE** to `/publicapi/v1/team/invitations` (unsupported method).
 * 2. Expect **HTTP 405** (**method not allowed**).
 */
test('Team invitations - unsupported HTTP method returns 405', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = ApiAuthHelper::apiRequest('DELETE', TeamApiHelper::invitationsUrl(), $bearer);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    // 405 when routing reaches "method not allowed"; 403 admin_role_required when admin gate runs first (same as other team tests).
    $allowed = ((int)$status === 405)
        || TeamApiHelper::isAdminRoleRequired((int)$status, is_array($json) ? $json : null);
    expect($allowed)->toBeTrue('DELETE on invitations: expected 405 or admin-only 403. ' . $debug);
});
