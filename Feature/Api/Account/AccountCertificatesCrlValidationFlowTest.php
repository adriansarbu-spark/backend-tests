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
        $this->markTestSkipped('Integration tests are disabled');
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
 * - `TEST_USER_1` has a valid active certificate with Authority Information Access and CRL distribution point URLs.
 * - The CA issuer and CRL endpoints are reachable from the test runner.
 *
 * Steps:
 * 1. Sign in as `TEST_USER_1` and **GET** `/publicapi/v1/account/certificates`; pick the first valid `document_signing` certificate.
 * 2. **GET** `/publicapi/v1/account/certificates?certificate_uuid=…&action=download&format=cer` for leaf PEM material.
 * 3. Download the CRL from the leaf certificate's distribution point and resolve the CRL signing CA certificate.
 * 4. Inspect leaf and CRL-signing CA certificates (subject, issuer, serial, dates, CRL distribution point URL on the leaf).
 * 5. Download and validate the CRL from the URL embedded in the leaf certificate.
 * 6. Assert the CRL status is **`good`** (serial not revoked), the CRL signature verifies, and `thisUpdate` / `nextUpdate` meet configured thresholds.
 */
test('Certificates CRL - TEST_USER_1 valid certificate is not revoked in a valid signed CRL', function () {
    $bearer = CertificateCrlFlowHelper::bearerForUser1();

    [$listStatus, $listJson, $listRaw] = CertificateCrlFlowHelper::listCertificates($bearer);
    $listDebug = 'status=' . $listStatus . ' raw=' . substr($listRaw, 0, 800);
    expect($listStatus)->toBe(200, 'GET account/certificates list failed. ' . $listDebug);
    expect(is_array($listJson))->toBeTrue('GET account/certificates returned non-JSON. ' . $listDebug);

    $certificate = CertificateCrlFlowHelper::findFirstCertificateInList(
        $listJson,
        'document_signing',
        'valid'
    );
    expect($certificate)->not->toBeNull(
        'No valid document_signing certificate found in account/certificates list. ' . $listDebug
    );

    $material = CertificateCrlFlowHelper::certificateMaterialForCrl($bearer, $certificate);
    $leafPem = $material['leaf_pem'];
    $issuerPem = $material['issuer_pem'];
    $issuerSourceUrl = $material['issuer_source_url'];
    $serial = $material['serial_number'];

    expect($serial)->not->toBe('', 'Selected certificate is missing serial_number.');
    expect($leafPem)->not->toBe('', 'Download did not yield leaf PEM.');
    expect($issuerPem)->not->toBe('', 'Could not resolve CA issuer PEM from downloaded leaf certificate.');

    $validation = CertificateCrlFlowHelper::validateCrlForCertificate(
        $leafPem,
        $issuerPem,
        'account_certificate.pem',
        $issuerSourceUrl
    );

    expect($validation['ok'])->toBeTrue(
        CertificateCrlFlowHelper::formatCrlValidationFailure(
            $validation,
            'Expected CRL validation to pass for TEST_USER_1 certificate serial=' . $serial . '.'
        )
    );

    expect($validation['status'])->toBe('good');
    expect($validation['signature_verified'])->toBeTrue();
    expect($validation['this_update'])->not->toBeNull();
    expect($validation['next_update'])->not->toBeNull();
    expect($validation['threshold_violations'])->toBe([]);
})->skip(SKIP_CRL_VALIDATION_TESTS);
