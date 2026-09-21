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
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: array<string, mixed>, 3: TestableControllerPublicAPIV1SponsorshipsInvitationsBatch}
 */
function sp_batch_ready(array $overrides = [], ?array $program = null): array
{
    if (!isset($overrides['partner/customer_link'])) {
        $overrides['partner/customer_link'] = sponsorships_link_model_for_validation();
    }
    [$registry, $load, $models] = sponsorships_console_fixture(
        query: ['route' => 'publicapi/v1/sponsorships/invitations/batch'],
        overrides: $overrides,
        program: $program,
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsInvitationsBatch::class,
        $registry,
        sponsorships_all_permissions(),
    );
    // Default: no onboarding/active blocker during validation.
    $controller->blockingLinkOverride = null;

    return [$registry, $load, $models, $controller];
}

/**
 * Prerequisites:
 * - Active program; one valid and one invalid email row; confirm omitted/false.
 *
 * Steps:
 * 1. POST batch with confirm=false.
 * 2. Assert HTTP 200 validation report (error_count=1) without create calls.
 * 3. Prove createOrRefreshLink was never invoked.
 */
test('Sponsorships — batch confirm=false validates only and does not create links', function () {
    $link = sponsorships_link_model_for_validation(['createOrRefreshLink' => ['ok' => true]]);
    [, , $models, $c] = sp_batch_ready(['partner/customer_link' => $link]);
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'confirm' => false,
        'rows' => [
            ['email' => 'ok@example.com'],
            ['email' => 'not-an-email'],
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['error'] ?? [])->toBe([])
        ->and($c->json['data']['distribution'])->toBe('simplifi_email')
        ->and($c->json['data']['row_count'])->toBe(2)
        ->and($c->json['data']['valid_count'])->toBe(1)
        ->and($c->json['data']['error_count'])->toBe(1)
        ->and($c->json['data']['rows'][0])->not->toHaveKey('error')
        ->and($c->json['data']['rows'][1]['error'])->toBe('invalid_email')
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([])
        ->and($c->findExistingBlockingLinkCalls)->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Active program; any invalid row with confirm=true (all-or-nothing).
 *
 * Steps:
 * 1. POST batch with confirm=true and one bad email.
 * 2. Assert HTTP 422 `batch_has_invalid_rows` and the validation report.
 * 3. Prove no createOrRefreshLink calls were made.
 */
test('Sponsorships — batch confirm=true rejects the whole batch with 422 when any row is invalid', function () {
    $link = sponsorships_link_model_for_validation();
    [, , $models, $c] = sp_batch_ready(['partner/customer_link' => $link]);
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'confirm' => true,
        'rows' => [
            ['email' => 'ok@example.com'],
            ['email' => 'bad'],
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['batch_has_invalid_rows'])
        ->and($c->json['data']['error_count'])->toBe(1)
        ->and($c->json['data']['rows'][1]['error'])->toBe('invalid_email')
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; partner_delivered rows missing external_ref; confirm=true.
 *
 * Steps:
 * 1. POST batch.
 * 2. Assert HTTP 422 with per-row `external_ref_required`.
 */
test('Sponsorships — batch partner_delivered requires external_ref per row', function () {
    [, , $models, $c] = sp_batch_ready();
    $c->setPostPayload([
        'distribution' => 'partner_delivered',
        'confirm' => true,
        'rows' => [
            ['external_ref' => ''],
            ['email' => 'ignored@example.com'],
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['batch_has_invalid_rows'])
        ->and($c->json['data']['error_count'])->toBe(2)
        ->and($c->json['data']['rows'][0]['error'])->toBe('external_ref_required')
        ->and($c->json['data']['rows'][1]['error'])->toBe('external_ref_required');
});

/**
 * Prerequisites:
 * - Active program; more than MAX_ROWS (1000) in the payload.
 *
 * Steps:
 * 1. POST batch with 1001 rows.
 * 2. Assert HTTP 400 `too_many_rows` and `data.max_rows`.
 * 3. Prove validation/create never ran.
 */
test('Sponsorships — batch rejects payloads larger than max rows', function () {
    $link = sponsorships_link_model_for_validation();
    [, , $models, $c] = sp_batch_ready(['partner/customer_link' => $link]);
    $rows = [];
    for ($i = 0; $i < 1001; $i++) {
        $rows[] = ['email' => "user{$i}@example.com"];
    }
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'confirm' => false,
        'rows' => $rows,
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['too_many_rows'])
        ->and($c->json['data']['max_rows'])->toBe(1000)
        ->and($c->findExistingBlockingLinkCalls)->toBe([])
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; existing onboarding/active blocker for the email.
 *
 * Steps:
 * 1. POST confirm=false with a blocking link stubbed.
 * 2. Assert the row reports `link_already_exists` without creating.
 */
test('Sponsorships — batch validate reports link_already_exists for blocking recipients', function () {
    $link = sponsorships_link_model_for_validation();
    [, , $models, $c] = sp_batch_ready(['partner/customer_link' => $link]);
    $c->blockingLinkOverride = ['status' => ModelPartnerCustomerLink::STATUS_ONBOARDING];
    $c->setPostPayload([
        'distribution' => 'simplifi_email',
        'confirm' => false,
        'rows' => [
            ['email' => 'taken@example.com'],
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['error_count'])->toBe(1)
        ->and($c->json['data']['rows'][0]['error'])->toBe('link_already_exists')
        ->and(sponsorships_calls($link, 'createOrRefreshLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Program exists but is not active.
 *
 * Steps:
 * 1. POST batch.
 * 2. Assert HTTP 409 `program_not_active` before row work.
 */
test('Sponsorships — batch refuses when the program is not active', function () {
    $link = sponsorships_link_model_for_validation();
    [, , $models, $c] = sp_batch_ready(
        ['partner/customer_link' => $link],
        sponsorships_sample_program(['status' => 'draft']),
    );
    $c->setPostPayload([
        'confirm' => true,
        'rows' => [['email' => 'ok@example.com']],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['program_not_active'])
        ->and($c->findExistingBlockingLinkCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; empty rows array.
 *
 * Steps:
 * 1. POST batch with rows=[].
 * 2. Assert HTTP 400 `rows_required`.
 */
test('Sponsorships — batch requires at least one row', function () {
    [, , $models, $c] = sp_batch_ready();
    $c->setPostPayload([
        'confirm' => false,
        'rows' => [],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['rows_required']);
});
