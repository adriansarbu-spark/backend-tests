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
 * - Signed-in company admin (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/team/invitations` with a malformed **`email`**.
 * 2. Expect **HTTP 400** and **`invalid_email`** in **`error`** (no invitation row implied).
 */
test('Team invitations - POST rejects invalid email', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer, [
        'email' => 'not-an-email',
        'role_code' => 'employee',
    ]);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(400, $debug);
    expect(TeamApiHelper::joinedErrors($json))->toContain('invalid_email');
});

/**
 * Prerequisites:
 * - Signed-in company admin (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** with a syntactically valid email but a **`role_code`** this API does not allow (only **`employee`** is permitted for invites today).
 * 2. Expect **HTTP 400** and **`invalid_role_code`**.
 */
test('Team invitations - POST rejects disallowed role_code', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer, [
        'email' => 'valid-role-test+' . gmdate('YmdHis') . '@example.com',
        'role_code' => 'admin',
    ]);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(400, $debug);
    expect(TeamApiHelper::joinedErrors($json))->toContain('invalid_role_code');
});
