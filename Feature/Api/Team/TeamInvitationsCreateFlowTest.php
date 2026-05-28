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
 * - Signed-in company admin (`TEST_USER_1_*`); outbound email queue is healthy in this environment.
 *
 * Steps:
 * 1. **POST** a new pending invitation for a unique **`email`** with **`role_code`** **`employee`** (the supported invite role).
 * 2. On **HTTP 200**, assert **`data.invitation.invitation_uuid`** is non-empty and the JSON body does **not** contain a raw **`token`** field (accept link stays server-side).
 * 3. If the server returns **HTTP 500** with **`invitation_email_queue_failed`**, skip this run with a clear message (known infrastructure limitation, not a product regression).
 */
test('Team invitations - admin can create an employee invitation when email queue accepts the job', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $email = 'alexandru.zamfir+team-inv-' . gmdate('YmdHis') . '@simplifi.ro';

    [$status, $json, $raw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer, [
        'email' => $email,
        'role_code' => 'employee',
    ]);

    $jsonText = is_array($json) ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'null';
    $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 1200);

    if ($status === 500 && is_array($json) && str_contains(TeamApiHelper::joinedErrors($json), 'invitation_email_queue_failed')) {
        test()->markTestSkipped('Email queue did not accept invitation mail in this environment: ' . TeamApiHelper::joinedErrors($json));
    }

    expect($status)->toBe(200, "POST create invitation failed.\n{$debug}");
    expect(is_array($json))->toBeTrue($debug);

    $inv = (array)($json['data']['invitation'] ?? []);
    expect((string)($inv['invitation_uuid'] ?? ''))->not->toBe('', $debug);
    expect((string)($inv['invited_email'] ?? ''))->toBe(mb_strtolower($email), $debug);
    expect(array_key_exists('token', $inv))->toBeFalse('Response must not include raw invitation token.');
});
