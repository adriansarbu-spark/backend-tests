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
 * - Signed-in referrer; outbound email queue may accept or reject the job in this environment.
 *
 * Steps:
 * 1. **GET** `/referrals/invite` and read `data.code`.
 * 2. **POST** `/referrals/invite/send` to a unique recipient email.
 * 3. On **HTTP 200**, expect `email_sent` and an `invite_uuid`.
 * 4. If the server returns **HTTP 500** with `invite_email_queue_failed`, skip (infrastructure limitation).
 */
test('Referrals - referrer can read invite code and send an invite email', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();

    [$getStatus, $getJson, $getRaw] = ReferralsApiHelper::getJson(ReferralsApiHelper::inviteUrl(), $bearer);
    $getDebug = "GET Status={$getStatus}\n" . substr((string)$getRaw, 0, 600);
    expect($getStatus)->toBe(200, "GET invite failed.\n{$getDebug}");
    expect((string)($getJson['data']['code'] ?? ''))->not->toBe('');

    $email = 'alexandru.zamfir+ref-' . gmdate('YmdHis') . '@simplifi.ro';
    [$status, $json, $raw] = ReferralsApiHelper::postJson(
        ReferralsApiHelper::inviteSendUrl(),
        $bearer,
        ['email' => $email],
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 1200);
    if ($status === 500 && str_contains(ReferralsApiHelper::joinedErrors($json), 'invite_email_queue_failed')) {
        test()->markTestSkipped('Email queue did not accept referral invite in this environment: ' . ReferralsApiHelper::joinedErrors($json));
    }

    expect($status)->toBe(200, "POST invite send failed.\n{$debug}");
    expect($json['data']['email_sent'] ?? null)->toBeTrue();
    expect((string)($json['data']['invite_uuid'] ?? ''))->not->toBe('');
});

/**
 * Prerequisites:
 * - Signed-in referrer.
 *
 * Steps:
 * 1. **GET** `/referrals/invite` and read the current `code`.
 * 2. **POST** `/referrals/invite` with `{ "rotate": true }`.
 * 3. Expect **HTTP 200** and a non-empty `code` (may differ from the first code when rotation is enabled).
 */
test('Referrals - referrer can rotate invite code', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();

    [$getStatus, $getJson] = ReferralsApiHelper::getJson(ReferralsApiHelper::inviteUrl(), $bearer);
    expect($getStatus)->toBe(200);
    $firstCode = (string)($getJson['data']['code'] ?? '');

    [$postStatus, $postJson, $postRaw] = ReferralsApiHelper::postJson(
        ReferralsApiHelper::inviteUrl(),
        $bearer,
        ['rotate' => true],
    );

    $debug = "Rotate status={$postStatus}\n" . substr((string)$postRaw, 0, 600);
    expect($postStatus)->toBe(200, "POST rotate failed.\n{$debug}");
    expect((string)($postJson['data']['code'] ?? ''))->not->toBe('');
    if ($firstCode !== '') {
        expect($postJson['data']['code'] ?? null)->not->toBe($firstCode);
    }
});
