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
 * - Company admin with route permission; company has no partner program.
 *
 * Steps:
 * 1. GET sponsorships/program.
 * 2. Assert HTTP 404 `program_not_found`.
 * 3. Prove getProgramForCompany was called with the active company id.
 */
test('Sponsorships — GET program returns 404 when the company has no program', function () {
    $program = new SponsorshipsModelStub(['getProgramForCompany' => null]);
    [$registry, $load] = sponsorships_registry(
        models: [
            'billing/entitlement_assignment' => new SponsorshipsModelStub(['isRoleAdminForCompany' => true]),
            'partner/program' => $program,
        ],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['program_not_found'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'partner/program'])
        ->and(sponsorships_calls($program, 'getProgramForCompany')[0]['args'])->toBe([20]);
});

/**
 * Prerequisites:
 * - Company admin; program stub returns a console payload.
 *
 * Steps:
 * 1. GET sponsorships/program.
 * 2. Assert success and `data.program` shape (uuid, status, branding, plans).
 */
test('Sponsorships — GET program returns console program payload', function () {
    [$registry, $load, $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toHaveKey('program')
        ->and($controller->json['data']['program'])->toHaveKeys([
            'program_uuid',
            'program_code',
            'name',
            'status',
            'billing_mode',
            'branding',
            'company_brand',
            'email_copy',
            'plans',
        ])
        ->and($controller->json['data']['program']['program_uuid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'partner/program']);
});

/**
 * Prerequisites:
 * - Company admin; program exists; PATCH body has non-array branding.
 *
 * Steps:
 * 1. PATCH with `branding` as a string.
 * 2. Assert HTTP 400 `invalid_branding`.
 * 3. Prove updateConsoleConfig was never called.
 */
test('Sponsorships — PATCH program rejects non-array branding', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['branding' => 'not-an-object']);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_branding'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH with sender_display_name longer than 128 characters.
 * 2. Assert HTTP 400 `invalid_sender_display_name`.
 */
test('Sponsorships — PATCH program rejects oversized sender display name', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'branding' => ['sender_display_name' => str_repeat('A', 129)],
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_sender_display_name'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH with email_copy that is not an object.
 * 2. Assert HTTP 400 `invalid_email_copy`.
 */
test('Sponsorships — PATCH program rejects non-array email_copy', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['email_copy' => 'bad']);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_email_copy'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH with an unknown locale key under email_copy.
 * 2. Assert HTTP 400 `invalid_email_copy_locale`.
 */
test('Sponsorships — PATCH program rejects unknown email_copy locale', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'email_copy' => ['fr-fr' => ['subject' => 'Bonjour']],
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_email_copy_locale'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH with an unknown slot key under a valid locale.
 * 2. Assert HTTP 400 `invalid_email_copy_key`.
 */
test('Sponsorships — PATCH program rejects unknown email_copy slot keys', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'email_copy' => ['ro-ro' => ['headline' => 'Nu']],
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_email_copy_key'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH with HTML tags in an email_copy value.
 * 2. Assert HTTP 400 `invalid_email_copy_value`.
 */
test('Sponsorships — PATCH program rejects HTML in email_copy values', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'email_copy' => ['ro-ro' => ['subject' => '<b>Hack</b>']],
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_email_copy_value'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists; plan lookup returns null.
 *
 * Steps:
 * 1. PATCH with unknown default_program_plan_uuid.
 * 2. Assert HTTP 400 `invalid_program_plan`.
 */
test('Sponsorships — PATCH program rejects unknown default plan uuid', function () {
    sponsorships_set_method('PATCH');
    $program = sponsorships_program_model(extraResponses: [
        'getActivePlanByUuid' => null,
    ]);
    [$registry, , $models] = sponsorships_console_fixture(
        overrides: ['partner/program' => $program],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'default_program_plan_uuid' => '99999999-9999-9999-9999-999999999999',
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_program_plan'])
        ->and(sponsorships_calls($models['program'], 'updateConsoleConfig'))->toBe([]);
});

/**
 * Prerequisites:
 * - Company admin; program exists.
 *
 * Steps:
 * 1. PATCH branding + email_copy (console-editable fields only).
 * 2. Assert success and that updateConsoleConfig received cleaned branding/copy.
 * 3. Prove commercial fields in the body were ignored (not passed to update).
 */
test('Sponsorships — PATCH program updates branding and email_copy and ignores commercial fields', function () {
    sponsorships_set_method('PATCH');
    [$registry, , $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([
        'branding' => ['sender_display_name' => '  New Sender  ', 'logo_url' => 'https://ignored.example/logo.png'],
        'email_copy' => [
            'ro-ro' => ['subject' => 'Invitatie', 'intro' => 'Salut'],
        ],
        // Commercial — console must not write these via this endpoint
        'billing_mode' => 'seat',
        'payment_terms_days' => 99,
        'status' => 'ended',
    ]);
    $controller->index();

    $updates = sponsorships_calls($models['program'], 'updateConsoleConfig');
    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toHaveKey('program')
        ->and($updates)->toHaveCount(1)
        ->and($updates[0]['args'][0])->toBe(1)
        ->and($updates[0]['args'][1])->toBe(['sender_display_name' => 'New Sender'])
        ->and($updates[0]['args'][2])->toBe([
            'ro-ro' => ['subject' => 'Invitatie', 'intro' => 'Salut'],
        ])
        ->and($updates[0]['args'][3])->toBeNull();
});

/**
 * Prerequisites:
 * - Wrong HTTP verb for program.
 *
 * Steps:
 * 1. POST to program index.
 * 2. Assert HTTP 405 `method_not_allowed`.
 */
test('Sponsorships — program rejects unsupported HTTP methods', function () {
    sponsorships_set_method('POST');
    [$registry, $load] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'partner/program']);
});
