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
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: array<string, SponsorshipsModelStub|SponsorshipsBrandingModelStub>, 3: TestableControllerPublicAPIV1SponsorshipsInvitations}
 */
function sp_invitations_ready(array $overrides = [], ?array $program = null): array
{
    [$registry, $load, $models] = sponsorships_console_fixture(
        query: ['route' => 'publicapi/v1/sponsorships/invitations'],
        overrides: $overrides,
        program: $program,
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsInvitations::class,
        $registry,
        sponsorships_all_permissions(),
    );

    return [$registry, $load, $models, $controller];
}

/**
 * Prerequisites:
 * - Company admin; program exists but status is not active.
 *
 * Steps:
 * 1. POST a simplifi_email invite.
 * 2. Assert HTTP 409 `program_not_active`.
 * 3. Prove createOrRefreshLink was never called.
 */
test('Sponsorships — single invite refuses when the program is not active', function () {
    $link = new SponsorshipsModelStub([]);
    [, , $models, $c] = sp_invitations_ready(
        ['partner/customer_link' => $link],
        sponsorships_sample_program(['status' => 'paused']),
    );
    $c->setPostPayload(['email' => 'beneficiary@example.com']);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['program_not_active'])
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; distribution is not a known mode.
 *
 * Steps:
 * 1. POST with `distribution=carrier_pigeon`.
 * 2. Assert HTTP 400 `invalid_distribution`.
 * 3. Prove the link model was not asked to create.
 */
test('Sponsorships — single invite rejects an unknown distribution mode', function () {
    $link = new SponsorshipsModelStub([]);
    [, , $models, $c] = sp_invitations_ready(['partner/customer_link' => $link]);
    $c->setPostPayload([
        'distribution' => 'carrier_pigeon',
        'email' => 'beneficiary@example.com',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_distribution'])
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; model reports an onboarding/active conflict.
 *
 * Steps:
 * 1. POST simplifi_email invite.
 * 2. Assert HTTP 409 `link_already_exists`.
 * 3. Prove the rate-limit reservation was released and no email was queued.
 */
test('Sponsorships — single invite maps link_already_exists to 409', function () {
    $existing = sponsorships_sample_link(['status' => ModelPartnerCustomerLink::STATUS_ACTIVE]);
    $link = new SponsorshipsModelStub([
        'createOrRefreshLink' => [
            'ok' => false,
            'error' => 'link_already_exists',
            'link' => $existing,
        ],
    ]);
    [, , $models, $c] = sp_invitations_ready(['partner/customer_link' => $link]);
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'email' => 'beneficiary@example.com',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['link_already_exists'])
        ->and($c->json['data']['invitation']['uuid'])->toBe($existing['uuid'])
        ->and($c->releasedReservations)->toHaveCount(1)
        ->and($c->queueInvitationEmailCalls)->toBe([])
        ->and($c->billInvitationIfDueCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; partner_delivered create succeeds.
 *
 * Steps:
 * 1. POST partner_delivered invite with external_ref.
 * 2. Assert success payload includes `hosted_url` and invitation.
 * 3. Prove email queue was not used; billing hook was invoked.
 */
test('Sponsorships — partner_delivered invite returns hosted_url and skips email queue', function () {
    $row = sponsorships_sample_link([
        'distribution' => 'partner_delivered',
        'invited_email' => '',
        'external_ref' => 'CRM-42',
    ]);
    $created = sponsorships_create_or_refresh_ok(['link' => $row]);
    $link = new SponsorshipsModelStub([
        'createOrRefreshLink' => $created,
        'getLinkById' => $row,
        'formatLink' => $row,
    ]);
    [, , $models, $c] = sp_invitations_ready(['partner/customer_link' => $link]);
    $c->hostedOnboardUrlResult = 'https://app.example.test/partner/onboard/invite-token-plaintext';
    $c->setPostPayload([
        'distribution' => 'partner_delivered',
        'external_ref' => 'CRM-42',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toHaveKeys(['invitation', 'hosted_url'])
        ->and($c->json['data']['hosted_url'])->toBe('https://app.example.test/partner/onboard/invite-token-plaintext')
        ->and($c->json['data']['invitation']['external_ref'])->toBe('CRM-42')
        ->and($c->queueInvitationEmailCalls)->toBe([])
        ->and($c->billInvitationIfDueCalls)->toHaveCount(1)
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Active program; simplifi_email create + queue succeed.
 *
 * Steps:
 * 1. POST simplifi_email invite.
 * 2. Assert success without `hosted_url`.
 * 3. Prove email was queued, rate limit committed, and billing hook ran.
 */
test('Sponsorships — simplifi_email invite omits hosted_url and queues email', function () {
    $row = sponsorships_sample_link();
    $created = sponsorships_create_or_refresh_ok(['link' => $row]);
    $link = new SponsorshipsModelStub([
        'createOrRefreshLink' => $created,
        'getLinkById' => $row,
        'formatLink' => $row,
        'recordEmailSend' => null,
        'commitTokenRotation' => null,
    ]);
    [, , $models, $c] = sp_invitations_ready(['partner/customer_link' => $link]);
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'email' => 'beneficiary@example.com',
        'given_name' => 'Ben',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toHaveKey('invitation')
        ->and($c->json['data'])->not->toHaveKey('hosted_url')
        ->and($c->queueInvitationEmailCalls)->toHaveCount(1)
        ->and($c->committedReservations)->toHaveCount(1)
        ->and($c->billInvitationIfDueCalls)->toHaveCount(1)
        ->and(sponsorships_calls($link, 'recordEmailSend')[0]['args'])->toBe([9, true, null]);
});

/**
 * Prerequisites:
 * - Active program; create succeeds but harness forces queue failure.
 * - Staged token rotation present (refresh path).
 *
 * Steps:
 * 1. POST simplifi_email invite.
 * 2. Assert HTTP 500 `invitation_email_queue_failed`.
 * 3. Prove staged rotation was not committed and reservation was released.
 */
test('Sponsorships — single invite does not commit token rotation when email queue fails', function () {
    $row = sponsorships_sample_link();
    $staged = ['link_id' => 9, 'token_hash' => 'new-hash'];
    $created = sponsorships_create_or_refresh_ok([
        'link' => $row,
        'staged_rotation' => $staged,
        'refreshed' => true,
    ]);
    $link = new SponsorshipsModelStub([
        'createOrRefreshLink' => $created,
        'recordEmailSend' => null,
        'commitTokenRotation' => null,
    ]);
    [, , $models, $c] = sp_invitations_ready(['partner/customer_link' => $link]);
    $c->queueInvitationEmailResult = false;
    $c->setPostPayload(['email' => 'beneficiary@example.com']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_email_queue_failed'])
        ->and($c->releasedReservations)->toHaveCount(1)
        ->and($c->committedReservations)->toBe([])
        ->and($c->billInvitationIfDueCalls)->toBe([])
        ->and(sponsorships_calls($link, 'recordEmailSend')[0]['args'])->toBe([9, false, 'queue_failed'])
        ->and(sponsorships_calls($link, 'commitTokenRotation'))->toBe([]);
});
