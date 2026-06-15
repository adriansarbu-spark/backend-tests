<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ReferralsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Referrals - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    ReferralsApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in referrer; invite send succeeds; remind cooldown may be configured in the environment.
 *
 * Steps:
 * 1. **POST** `/referrals/invite/send` to a unique email and capture `invite_uuid`.
 * 2. **POST** `/referrals/remind` with that `invite_uuid`; expect **HTTP 200** when the email queue accepts the job.
 * 3. **POST** remind again immediately; expect **HTTP 429** `referrals_remind_cooldown` when cooldown seconds are greater than zero.
 * 4. Skip when invite send or first remind fails with queue errors (infrastructure limitation).
 */
test('Referrals - referrer can remind after invite send and hits cooldown on repeat', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();
    $email = 'alexandru.zamfir+ref-remind-' . gmdate('YmdHis') . '@simplifi.ro';

    [$sendStatus, $sendJson, $sendRaw] = ReferralsApiHelper::postJson(
        ReferralsApiHelper::inviteSendUrl(),
        $bearer,
        ['email' => $email],
    );

    $sendDebug = "Invite send status={$sendStatus}\n" . substr((string)$sendRaw, 0, 1000);
    if ($sendStatus === 500 && str_contains(ReferralsApiHelper::joinedErrors($sendJson), 'invite_email_queue_failed')) {
        test()->markTestSkipped('Email queue did not accept referral invite in this environment.');
    }
    expect($sendStatus)->toBe(200, "Invite send failed.\n{$sendDebug}");

    $inviteUuid = (string)($sendJson['data']['invite_uuid'] ?? '');
    expect($inviteUuid)->not->toBe('');

    [$remindStatus, $remindJson, $remindRaw] = ReferralsApiHelper::postJson(
        ReferralsApiHelper::remindUrl(),
        $bearer,
        ['invite_uuid' => $inviteUuid],
    );

    $remindDebug = "Remind status={$remindStatus}\n" . substr((string)$remindRaw, 0, 1000);
    if ($remindStatus === 500 && str_contains(ReferralsApiHelper::joinedErrors($remindJson), 'referrals_remind_queue_failed')) {
        test()->markTestSkipped('Email queue did not accept referral remind in this environment.');
    }
    expect($remindStatus)->toBe(200, "First remind failed.\n{$remindDebug}");
    expect($remindJson['data']['email_sent'] ?? null)->toBeTrue();

    [$againStatus, $againJson] = ReferralsApiHelper::postJson(
        ReferralsApiHelper::remindUrl(),
        $bearer,
        ['invite_uuid' => $inviteUuid],
    );

    if ($againStatus === 429) {
        expect(ReferralsApiHelper::joinedErrors($againJson))->toContain('referrals_remind_cooldown');
        expect(isset($againJson['data']['retry_after_seconds']))->toBeTrue();

        return;
    }

    if ($againStatus === 200) {
        test()->markTestSkipped('referral_remind_cooldown_seconds is zero in this environment; cooldown not testable.');
    }

    expect(in_array($againStatus, [200, 429], true))->toBeTrue("Unexpected second remind status.\n{$remindDebug}");
});
