<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/PartnerTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1PartnerJoin} (durable QR mint).
 */

beforeEach(function () {
    $this->partnerHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->partnerSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $this->partnerServerSnapshot = [];
    foreach (['REMOTE_ADDR', 'HTTP_HOST', 'SERVER_NAME'] as $key) {
        $this->partnerServerSnapshot[$key] = [
            'had' => array_key_exists($key, $_SERVER),
            'value' => $_SERVER[$key] ?? null,
        ];
    }
    partner_set_method('POST');
    // Join hashes ClientIp::get() into rate-limit keys; seed host/IP to avoid null strtolower() deprecations.
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_HOST'] = 'api.dev.simplifi.ro';
    $_SERVER['SERVER_NAME'] = 'api.dev.simplifi.ro';
});

afterEach(function () {
    if ($this->partnerHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->partnerSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
    foreach ($this->partnerServerSnapshot as $key => $meta) {
        if ($meta['had']) {
            $_SERVER[$key] = $meta['value'];
        } else {
            unset($_SERVER[$key]);
        }
    }
});

/**
 * Prerequisites:
 * - POST join; program_code and/or external_ref missing after trim.
 *
 * Steps:
 * 1. POST with the given incomplete body/query.
 * 2. Assert HTTP 400 `program_code_and_external_ref_required`.
 * 3. Prove partner models were not loaded.
 */
test('Partner - join missing program_code or external_ref returns 400', function (
    array $query,
    array $body,
) {
    [$registry, $load] = partner_registry(query: $query);
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->setPostPayload($body);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['program_code_and_external_ref_required'])
        ->and($load->loadedModels)->toBe([]);
})->with([
    'missing both' => [[], []],
    'blank program_code' => [['program_code' => '  '], ['external_ref' => 'EMP-1']],
    'blank external_ref' => [['program_code' => 'acme-partner'], ['external_ref' => '']],
    'missing external_ref key' => [['program_code' => 'acme-partner'], []],
]);

/**
 * Prerequisites:
 * - Non-POST verb.
 *
 * Steps:
 * 1. GET join.
 * 2. Assert HTTP 405 `method_not_allowed` before validation.
 */
test('Partner - join rejects non-POST with 405', function () {
    partner_set_method('GET');
    [$registry, $load] = partner_registry(
        query: ['program_code' => 'acme-partner'],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->setPostPayload(['external_ref' => 'EMP-1']);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toBe(['POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Cache present so the sliding-window branch runs; rate-limit stub denies.
 *
 * Steps:
 * 1. POST join with valid program_code + external_ref.
 * 2. Assert HTTP 429 `join_rate_limited` before program lookup.
 * 3. Prove both IP and ref attempt keys were reserved.
 */
test('Partner - join rate limit returns 429 before program lookup', function () {
    $cache = new PartnerCacheStub();
    $link = new SponsorshipsModelStub([]);
    $program = sponsorships_program_model();
    [$registry, $load] = partner_registry(
        query: ['program_code' => 'acme-partner'],
        overrides: [
            'partner/program' => $program,
            'partner/customer_link' => $link,
        ],
        cache: $cache,
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->rateLimitResult = ['ok' => false];
    $c->setPostPayload(['external_ref' => 'EMP-42']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['join_rate_limited'])
        ->and($c->rateLimitAttempts)->toHaveCount(2)
        ->and($c->rateLimitAttempts[0]['max'])->toBe(ControllerPublicAPIV1PartnerJoin::IP_LIMIT)
        ->and($c->rateLimitAttempts[1]['max'])->toBe(ControllerPublicAPIV1PartnerJoin::REF_LIMIT)
        ->and($load->loadedModels)->toBe([])
        ->and($program->callsTo('getActiveProgramByCode'))->toBe([])
        ->and($link->callsTo('mintTokenForJoin'))->toBe([]);
});

/**
 * Prerequisites:
 * - Cache + allowed rate limit; getActiveProgramByCode returns null.
 *
 * Steps:
 * 1. POST join for an unknown program code.
 * 2. Assert uniform HTTP 404 `link_not_found` (no program_not_found leak).
 */
test('Partner - join unknown program returns uniform link_not_found', function () {
    $cache = new PartnerCacheStub();
    $program = new SponsorshipsModelStub(['getActiveProgramByCode' => null]);
    $link = new SponsorshipsModelStub([
        'mintTokenForJoin' => ['ok' => true, 'token' => 'should-not-mint'],
    ]);
    [$registry] = partner_registry(
        query: ['program_code' => 'no-such-program'],
        overrides: [
            'partner/program' => $program,
            'partner/customer_link' => $link,
        ],
        cache: $cache,
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->setPostPayload(['external_ref' => 'EMP-99']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found'])
        ->and($program->callsTo('getActiveProgramByCode')[0]['args'])->toBe(['no-such-program'])
        ->and($link->callsTo('mintTokenForJoin'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active program; mintTokenForJoin fails (wrong state / unknown ref).
 *
 * Steps:
 * 1. POST join.
 * 2. Assert uniform HTTP 404 `link_not_found` regardless of stub error detail.
 */
test('Partner - join mint failure returns uniform link_not_found', function (string $stubError) {
    $cache = new PartnerCacheStub();
    $programRow = sponsorships_sample_program();
    $program = sponsorships_program_model($programRow);
    $link = new SponsorshipsModelStub([
        'mintTokenForJoin' => ['ok' => false, 'error' => $stubError],
    ]);
    [$registry] = partner_registry(
        query: ['program_code' => 'acme-partner'],
        overrides: [
            'partner/program' => $program,
            'partner/customer_link' => $link,
        ],
        cache: $cache,
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->setPostPayload(['external_ref' => 'EMP-gone']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found'])
        ->and($link->callsTo('mintTokenForJoin'))->toHaveCount(1)
        ->and($link->callsTo('mintTokenForJoin')[0]['args'][1])->toBe('EMP-gone');
})->with([
    'link_not_found',
    'revoked_or_active',
    'anything_else',
]);

/**
 * Prerequisites:
 * - Active program; mintTokenForJoin returns a fresh token; EmailQueue base URL available.
 *
 * Steps:
 * 1. POST join with a valid external_ref.
 * 2. Assert HTTP 200 `data.token` and hosted_url ending with the onboard path + token.
 * 3. Prove mintTokenForJoin received the program row and external_ref.
 */
test('Partner - join success mints token and hosted_url from stubs', function () {
    $cache = new PartnerCacheStub();
    $programRow = sponsorships_sample_program();
    $program = sponsorships_program_model($programRow);
    $token = 'fresh-join-token-abc';
    $link = new SponsorshipsModelStub([
        'mintTokenForJoin' => ['ok' => true, 'token' => $token],
    ]);
    [$registry] = partner_registry(
        query: ['program_code' => 'acme-partner'],
        overrides: [
            'partner/program' => $program,
            'partner/customer_link' => $link,
        ],
        cache: $cache,
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerJoin::class,
        $registry,
        partner_all_permissions(),
    );
    $c->setPostPayload(['external_ref' => 'EMP-42']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['token'])->toBe($token)
        ->and($c->json['data']['hosted_url'])->toEndWith(
            PartnerInvitationEmail::ONBOARD_PATH . rawurlencode($token),
        )
        ->and($link->callsTo('mintTokenForJoin')[0]['args'][0]['program_code'])->toBe('acme-partner')
        ->and($link->callsTo('mintTokenForJoin')[0]['args'][1])->toBe('EMP-42');
});
