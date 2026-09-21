<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/SponsorshipsTestDoubles.php';

beforeEach(function () {
    $this->spHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->spSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    sponsorships_set_method('GET');
});

afterEach(function () {
    if ($this->spHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->spSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - Company admin; company has no partner program.
 *
 * Steps:
 * 1. GET email_preview.
 * 2. Assert HTTP 404 `program_not_found`.
 */
test('Sponsorships — email preview returns 404 when program is missing', function () {
    $program = new SponsorshipsModelStub(['getProgramForCompany' => null]);
    [$registry] = sponsorships_registry(
        models: [
            'billing/entitlement_assignment' => new SponsorshipsModelStub(['isRoleAdminForCompany' => true]),
            'partner/program' => $program,
        ],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['program_not_found']);
});

/**
 * Prerequisites:
 * - Company admin with a program; request locale is not en-gb/ro-ro.
 *
 * Steps:
 * 1. GET email_preview?locale=fr-fr.
 * 2. Assert HTTP 400 `invalid_locale`.
 */
test('Sponsorships — email preview rejects unsupported locale', function () {
    [$registry] = sponsorships_console_fixture(query: ['locale' => 'fr-fr']);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_locale']);
});

/**
 * Prerequisites:
 * - Company admin; program + branding stubs; language stub for defaults.
 *
 * Steps:
 * 1. GET email_preview with locale=ro-ro (default plan).
 * 2. Assert `data` has locale, copy slots, branding.sender_display_name, company_brand.
 * 3. Prove language defaults were loaded for the locale.
 */
test('Sponsorships — email preview returns computed copy and branding shape', function () {
    $language = new SponsorshipsLanguageStub();
    [$registry, $load, $models] = sponsorships_console_fixture(query: ['locale' => 'ro-ro']);
    $registry->set('language', $language);

    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toHaveKeys(['locale', 'copy', 'branding', 'company_brand'])
        ->and($controller->json['data']['locale'])->toBe('ro-ro')
        ->and($controller->json['data']['copy'])->toHaveKeys([
            'subject',
            'title',
            'eyebrow',
            'invite_line',
            'body',
            'cta',
            'benefit_description',
            'logo_url',
            'accent_color',
            'on_accent',
        ])
        ->and($controller->json['data']['branding'])->toBe([
            'sender_display_name' => 'Acme Sponsors',
        ])
        ->and($controller->json['data']['company_brand'])->toBeNull()
        ->and($language->loadCalls)->toContain(['mail/mailgun', 'ro-ro'])
        ->and(sponsorships_calls($models['program'], 'getDefaultPlan'))->not->toBe([])
        ->and($load->loadedModels)->toContain('partner/program')
        ->and($load->loadedModels)->toContain('company/branding');
});

/**
 * Prerequisites:
 * - Company admin; program_plan_uuid query selects an active plan.
 *
 * Steps:
 * 1. GET email_preview with program_plan_uuid.
 * 2. Assert getActivePlanByUuid is used instead of getDefaultPlan.
 */
test('Sponsorships — email preview resolves explicit program_plan_uuid', function () {
    $plan = [
        'program_plan_id' => 8,
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'product_name' => 'Sign Enterprise',
    ];
    $program = sponsorships_program_model(extraResponses: [
        'getActivePlanByUuid' => $plan,
        'getDefaultPlan' => null,
    ]);
    [$registry, , $models] = sponsorships_console_fixture(
        query: [
            'locale' => 'en-gb',
            'program_plan_uuid' => '11111111-2222-3333-4444-555555555555',
        ],
        overrides: ['partner/program' => $program],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['locale'])->toBe('en-gb')
        ->and(sponsorships_calls($models['program'], 'getActivePlanByUuid')[0]['args'])->toBe([
            1,
            '11111111-2222-3333-4444-555555555555',
        ])
        ->and(sponsorships_calls($models['program'], 'getDefaultPlan'))->toBe([]);
});

/**
 * Prerequisites:
 * - Wrong HTTP verb.
 *
 * Steps:
 * 1. POST email_preview.
 * 2. Assert HTTP 405 before auth.
 */
test('Sponsorships — email preview rejects non-GET methods before auth', function () {
    sponsorships_set_method('POST');
    [$registry, $load] = sponsorships_registry(new SponsorshipsCustomerStub(0, 0, 0));
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe([]);
});
