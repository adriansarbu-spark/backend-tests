<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/SponsorshipsTestDoubles.php';

beforeEach(function () {
    $this->spHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->spSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    sponsorships_set_method('POST');
});

afterEach(function () {
    if ($this->spHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->spSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * @param array<string, object> $overrides
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: array<string, mixed>, 3: TestableControllerPublicAPIV1SponsorshipsInvitationsResend}
 */
function sp_resend_ready(array $overrides = [], ?array $program = null): array
{
    [$registry, $load, $models] = sponsorships_console_fixture(
        query: ['route' => 'publicapi/v1/sponsorships/invitations/resend'],
        overrides: $overrides,
        program: $program,
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsInvitationsResend::class,
        $registry,
        sponsorships_all_permissions(),
    );

    return [$registry, $load, $models, $controller];
}

/**
 * Prerequisites:
 * - Company admin; link_uuid missing or blank after trim.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert HTTP 400 `link_uuid_required`.
 */
test('Sponsorships — resend requires link_uuid', function (array $payload) {
    $link = new SponsorshipsModelStub([]);
    [, , $models, $c] = sp_resend_ready(['partner/customer_link' => $link]);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['link_uuid_required'])
        ->and(sponsorships_calls($link, 'getLinkForProgramByUuid'))->toBe([]);
})->with([
    'missing key' => [[]],
    'blank after trim' => [['link_uuid' => '  ']],
]);

/**
 * Prerequisites:
 * - Link exists but status is not invited (onboarding/active/revoked).
 *
 * Steps:
 * 1. POST resend for that UUID.
 * 2. Assert HTTP 400 `link_not_invited`.
 * 3. Prove stageTokenRotation was never called.
 */
test('Sponsorships — resend refuses links that are not in invited status', function (string $status) {
    $row = sponsorships_sample_link(['status' => $status]);
    $link = new SponsorshipsModelStub([
        'getLinkForProgramByUuid' => $row,
        'stageTokenRotation' => ['ok' => true, 'token' => 'x', 'link' => $row],
    ]);
    [, , $models, $c] = sp_resend_ready(['partner/customer_link' => $link]);
    $c->setPostPayload(['link_uuid' => $row['uuid']]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['link_not_invited'])
        ->and(sponsorships_calls($link, 'stageTokenRotation'))->toBe([]);
})->with([
    ModelPartnerCustomerLink::STATUS_ONBOARDING,
    ModelPartnerCustomerLink::STATUS_ACTIVE,
    ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER,
]);

/**
 * Prerequisites:
 * - Invited simplifi_email link; staged rotation returns an empty token so
 *   PartnerInvitationEmail::queue fails without touching EmailQueue/DB.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert HTTP 500 `invitation_email_queue_failed`.
 * 3. Prove commitTokenRotation was not called and the reservation was released.
 */
test('Sponsorships — resend does not commit token rotation when email queue fails', function () {
    $row = sponsorships_sample_link([
        'distribution' => 'simplifi_email',
        'invited_email' => 'beneficiary@example.com',
    ]);
    // Empty token → PartnerInvitationEmail::queue returns false immediately.
    $staged = [
        'ok' => true,
        'token' => '',
        'link' => $row,
    ];
    $link = new SponsorshipsModelStub([
        'getLinkForProgramByUuid' => $row,
        'stageTokenRotation' => $staged,
        'recordEmailSend' => null,
        'commitTokenRotation' => null,
        'formatLink' => $row,
        'getLinkById' => $row,
    ]);
    [, , $models, $c] = sp_resend_ready(['partner/customer_link' => $link]);
    $c->setPostPayload(['link_uuid' => '  ' . $row['uuid'] . '  ']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_email_queue_failed'])
        ->and($c->releasedReservations)->toHaveCount(1)
        ->and($c->committedReservations)->toBe([])
        ->and(sponsorships_calls($link, 'stageTokenRotation')[0]['args'])->toBe([(int) $row['link_id']])
        ->and(sponsorships_calls($link, 'recordEmailSend')[0]['args'])->toBe([(int) $row['link_id'], false, 'queue_failed'])
        ->and(sponsorships_calls($link, 'commitTokenRotation'))->toBe([]);
});

/**
 * Prerequisites:
 * - Invited partner_delivered link; staged rotation succeeds.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert success with a fresh `hosted_url` and no email send recording.
 * 3. Prove commitTokenRotation ran (partner-delivered commits immediately).
 */
test('Sponsorships — partner_delivered resend returns hosted_url without email queue', function () {
    $row = sponsorships_sample_link([
        'distribution' => 'partner_delivered',
        'invited_email' => '',
        'external_ref' => 'CRM-9',
    ]);
    $staged = [
        'ok' => true,
        'token' => 'fresh-partner-token',
        'link' => $row,
    ];
    $link = new SponsorshipsModelStub([
        'getLinkForProgramByUuid' => $row,
        'stageTokenRotation' => $staged,
        'commitTokenRotation' => null,
        'formatLink' => $row,
        'getLinkById' => $row,
        'recordEmailSend' => null,
    ]);
    [, , $models, $c] = sp_resend_ready(['partner/customer_link' => $link]);
    $c->hostedOnboardUrlResult = 'https://app.example.test/partner/onboard/fresh-partner-token';
    $c->setPostPayload(['link_uuid' => $row['uuid']]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toHaveKeys(['invitation', 'hosted_url'])
        ->and($c->json['data']['hosted_url'])->toBe('https://app.example.test/partner/onboard/fresh-partner-token')
        ->and(sponsorships_calls($link, 'commitTokenRotation'))->toHaveCount(1)
        ->and(sponsorships_calls($link, 'recordEmailSend'))->toBe([])
        ->and($c->releasedReservations)->toBe([]);
});

/**
 * Prerequisites:
 * - UUID not found for this program.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert HTTP 404 `link_not_found`.
 */
test('Sponsorships — resend maps unknown link UUID to 404', function () {
    $link = new SponsorshipsModelStub(['getLinkForProgramByUuid' => null]);
    [, , $models, $c] = sp_resend_ready(['partner/customer_link' => $link]);
    $c->setPostPayload(['link_uuid' => 'missing-uuid']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found'])
        ->and(sponsorships_calls($link, 'getLinkForProgramByUuid')[0]['args'])->toBe([1, 'missing-uuid']);
});

/**
 * Prerequisites:
 * - Program is not active.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert HTTP 409 `program_not_active`.
 */
test('Sponsorships — resend refuses when the program is not active', function () {
    $link = new SponsorshipsModelStub([]);
    [, , $models, $c] = sp_resend_ready(
        ['partner/customer_link' => $link],
        sponsorships_sample_program(['status' => 'paused']),
    );
    $c->setPostPayload(['link_uuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff']);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['program_not_active'])
        ->and(sponsorships_calls($link, 'getLinkForProgramByUuid'))->toBe([]);
});
