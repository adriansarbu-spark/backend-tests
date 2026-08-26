<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/CertificateCrlFlowHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no CRL validation API calls run.
     */
    test('Certificates CRL - integration tests are turned off for this run', function () {
        $this->markTestSkipped(
            'Integration tests are disabled (SKIP_INTEGRATION_TESTS=true); CRL validation flow was not run.'
        );
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are enabled; `TEST_USER_1_*`, CRL threshold constants, `openssl`, and `samX509` are available.
 *
 * Steps:
 * 1. Confirm required configuration is present; if not, skip the whole file.
 */
beforeAll(function () {
    CertificateCrlFlowHelper::assertCrlValidationConfigOrSkip();
});

/**
 * Prerequisites:
 * - `TEST_USER_1` can authenticate; a valid `document_signing` certificate exists or can be issued.
 * - The CA issuer and CRL endpoints are reachable from the test runner (no env proxy).
 *
 * Steps:
 * 1. Sign in as `TEST_USER_1` and ensure a valid `document_signing` certificate exists (issue only if none).
 * 2. **GET** download for leaf PEM; resolve CRL signing CA from active `certificate_chain_pem` (AIA/CDP fallback).
 * 3. Download and validate the CRL from the leaf distribution point.
 * 4. Assert status **`good`**, signature verifies, and `thisUpdate` / `nextUpdate` meet thresholds.
 * 5. Never revoke.
 */
test('Certificates CRL - TEST_USER_1 valid certificate is not revoked in a valid signed CRL', function () {
    $bearer = CertificateCrlFlowHelper::bearerForUser1();

    $certificate = CertificateCrlFlowHelper::ensureValidCertificate($bearer, 'document_signing');
    expect($certificate)->toBeArray('ensureValidCertificate did not return a certificate row.');
    expect((string)($certificate['certificate_uuid'] ?? ''))->not->toBe('');
    expect((string)($certificate['serial_number'] ?? ''))->not->toBe('');

    $material = CertificateCrlFlowHelper::certificateMaterialForCrl($bearer, $certificate);
    $leafPem = $material['leaf_pem'];
    $issuerPem = $material['issuer_pem'];
    $issuerSourceUrl = $material['issuer_source_url'];
    $serial = $material['serial_number'];

    expect($serial)->not->toBe('', 'Selected certificate is missing serial_number.');
    expect($leafPem)->not->toBe('', 'Download did not yield leaf PEM.');
    expect($issuerPem)->not->toBe('', 'Could not resolve CA issuer PEM for CRL validation.');

    $validation = CertificateCrlFlowHelper::validateCrlForCertificate(
        $leafPem,
        $issuerPem,
        'account_certificate.pem',
        $issuerSourceUrl
    );

    expect($validation['ok'])->toBeTrue(
        CertificateCrlFlowHelper::formatCrlValidationFailure(
            $validation,
            'Expected CRL validation to pass for TEST_USER_1 certificate serial=' . $serial
            . ' issuer_source=' . $issuerSourceUrl . '.'
        )
    );

    expect($validation['status'])->toBe('good');
    expect($validation['signature_verified'])->toBeTrue();
    expect($validation['this_update'])->not->toBeNull();
    expect($validation['next_update'])->not->toBeNull();
    expect($validation['threshold_violations'])->toBe([]);
});
