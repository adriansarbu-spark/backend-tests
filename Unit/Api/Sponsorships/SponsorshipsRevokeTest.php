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
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: array<string, mixed>, 3: TestableControllerPublicAPIV1SponsorshipsInvitationsRevoke}
 */
function sp_revoke_ready(array $overrides = [], ?array $program = null): array
{
    [$registry, $load, $models] = sponsorships_console_fixture(
        query: ['route' => 'publicapi/v1/sponsorships/invitations/revoke'],
        overrides: $overrides,
        program: $program,
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsInvitationsRevoke::class,
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
 * 1. POST revoke.
 * 2. Assert HTTP 400 `link_uuid_required`.
 * 3. Prove revokeByPartner was not called.
 */
test('Sponsorships — partner revoke requires link_uuid', function (array $payload) {
    $link = new SponsorshipsModelStub([]);
    [, , $models, $c] = sp_revoke_ready(['partner/customer_link' => $link]);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['link_uuid_required'])
        ->and(sponsorships_calls($link, 'revokeByPartner'))->toBe([]);
})->with([
    'missing key' => [[]],
    'blank after trim' => [['link_uuid' => '   ']],
]);

/**
 * Prerequisites:
 * - Model cannot find the UUID in this program.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert HTTP 404 `link_not_found`.
 */
test('Sponsorships — partner revoke maps unknown UUID to 404', function () {
    $link = new SponsorshipsModelStub([
        'revokeByPartner' => ['ok' => false, 'error' => 'link_not_found'],
    ]);
    [, , $models, $c] = sp_revoke_ready(['partner/customer_link' => $link]);
    $c->setPostPayload(['link_uuid' => 'missing-uuid']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found'])
        ->and(sponsorships_calls($link, 'revokeByPartner')[0]['args'])->toBe([1, 'missing-uuid', 10]);
});

/**
 * Prerequisites:
 * - Model rejects a second revoke / wrong-state revoke.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert HTTP 400 with the model error code.
 */
test('Sponsorships — partner revoke maps other model failures to 400', function (string $error) {
    $link = new SponsorshipsModelStub([
        'revokeByPartner' => ['ok' => false, 'error' => $error],
    ]);
    [, , $models, $c] = sp_revoke_ready(['partner/customer_link' => $link]);
    $c->setPostPayload(['link_uuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe([$error]);
})->with([
    'link_already_revoked',
    'revoke_failed',
]);

/**
 * Prerequisites:
 * - Revoke succeeds; raw link has no billed_at so billing afterRevocation is a no-op.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert success with invitation payload.
 * 3. Prove revokeByPartner received program id, trimmed uuid, and actor customer id.
 */
test('Sponsorships — partner revoke success returns invitation without billing when never billed', function () {
    $formatted = sponsorships_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER,
    ]);
    $raw = sponsorships_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER,
        'billed_at' => null,
    ]);
    $link = new SponsorshipsModelStub([
        'revokeByPartner' => [
            'ok' => true,
            'link' => $formatted,
            'raw' => $raw,
        ],
    ]);
    $sponsorship = new SponsorshipsModelStub(['getPlanById' => ['program_plan_id' => 5]]);
    [, , $models, $c] = sp_revoke_ready([
        'partner/customer_link' => $link,
        'partner/sponsorship' => $sponsorship,
    ]);
    $c->setPostPayload(['link_uuid' => '  ' . $formatted['uuid'] . '  ']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['status'])->toBe(ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER)
        ->and(sponsorships_calls($link, 'revokeByPartner')[0]['args'])->toBe([1, $formatted['uuid'], 10])
        // billed_at empty → afterRevocation returns before loading sponsorship plan
        ->and(sponsorships_calls($sponsorship, 'getPlanById'))->toBe([]);
});

/**
 * Prerequisites:
 * - Revoke succeeds; raw link was billed so PartnerSponsorshipBilling::afterRevocation runs.
 * - getPlanById returns null so the billing hook exits before any Stripe call.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert success.
 * 3. Prove the billing hook loaded the plan (getPlanById called) — stubbed, no Stripe.
 */
test('Sponsorships — partner revoke invokes billing afterRevocation stub for billed links', function () {
    $formatted = sponsorships_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER,
    ]);
    $raw = sponsorships_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER,
        'billed_at' => '2026-08-01 12:00:00',
        'program_id' => 1,
        'program_plan_id' => 5,
        'link_id' => 9,
    ]);
    $link = new SponsorshipsModelStub([
        'revokeByPartner' => [
            'ok' => true,
            'link' => $formatted,
            'raw' => $raw,
        ],
        'markLinkUnbilled' => null,
    ]);
    $sponsorship = new SponsorshipsModelStub([
        // null plan → afterRevocation returns before decrementQuantityForPlan / Stripe
        'getPlanById' => null,
    ]);
    $program = sponsorships_program_model();
    [, , $models, $c] = sp_revoke_ready([
        'partner/customer_link' => $link,
        'partner/sponsorship' => $sponsorship,
        'partner/program' => $program,
    ]);
    $c->setPostPayload(['link_uuid' => $formatted['uuid']]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['uuid'])->toBe($formatted['uuid'])
        ->and(sponsorships_calls($program, 'getProgramById')[0]['args'])->toBe([1])
        ->and(sponsorships_calls($sponsorship, 'getPlanById')[0]['args'])->toBe([5])
        ->and(sponsorships_calls($link, 'markLinkUnbilled'))->toBe([]);
});
