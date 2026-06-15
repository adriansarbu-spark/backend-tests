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
     * 1. Mark this placeholder as skipped so no CRL propagation API calls run.
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
 * - Integration tests are enabled; `TEST_USER_1_*`, CRL constants, `openssl`, and `samX509` are available.
 *
 * Steps:
 * 1. Confirm required configuration is present; if not, skip the whole file with a clear reason.
 */
beforeAll(function () {
    CertificateCrlFlowHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests are on; `TEST_USER_1` has a valid active certificate and TOTP enrolled.
 * - The downloaded leaf certificate exposes CA issuer (AIA) and CRL distribution point URLs reachable from the test runner.
 *
 * Steps:
 * 1. Sign in as `TEST_USER_1` and **GET** `/publicapi/v1/account/certificates?page=1&per_page=20`; pick the first
 *    certificate with `usage=document_signing` and `status=valid`.
 * 2. **GET** `/publicapi/v1/account/certificates?certificate_uuid=…&action=download&format=cer` for leaf PEM material.
 * 3. Resolve the CA issuer from the leaf certificate's Authority Information Access URL, then download the CRL from the
 *    leaf certificate's distribution point; confirm the certificate serial is not listed as revoked (**`good`**).
 * 4. **POST** `/publicapi/v1/account/certificates?certificate_uuid=…&action=revoke` with valid **`totp_code`**; record revocation timestamp.
 * 5. Poll the CRL at `CRL_POLL_INTERVAL_MS` until the serial appears in a valid CRL (**`revoked`**) or `CRL_MAX_TIMEOUT_SEC` elapses.
 * 6. Measure propagation time from successful revocation to first **`revoked`** CRL observation.
 * 7. Assert propagation time is within `CRL_SLA_PROPAGATION_MS`; fail with an alert if SLA is exceeded.
 * 8. In **`finally`**, always reissue a fresh certificate via **POST** `/publicapi/v1/account/certificates` and log the new serial number.
 */
test('Certificates CRL - revocation propagates to CRL within SLA for TEST_USER_1', function () {
    $bearer = CertificateCrlFlowHelper::bearerForUser1();

    $oldSerial = '';
    $oldUuid = '';
    $usage = 'document_signing';
    $leafPem = '';
    $issuerPem = '';
    $issuerSourceUrl = '';
    $revocationTimestamp = '';
    $propagationMs = null;
    $pollLog = [];
    $newSerialAfterReissue = '';
    $cleanupDebug = '';
    $testFailure = null;

    try {
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
        $oldSerial = $material['serial_number'];
        $oldUuid = $material['certificate_uuid'];
        $usage = $material['usage'];
        $leafPem = $material['leaf_pem'];
        $issuerPem = $material['issuer_pem'];
        $issuerSourceUrl = $material['issuer_source_url'];

        expect($oldSerial)->not->toBe('', 'Selected certificate is missing serial_number.');
        expect($oldUuid)->not->toBe('', 'Selected certificate is missing certificate_uuid.');
        expect($leafPem)->not->toBe('', 'Download did not yield leaf PEM.');
        expect($issuerPem)->not->toBe('', 'Could not resolve CA issuer PEM from downloaded leaf certificate.');

        $initialWait = CertificateCrlFlowHelper::waitForCrlStatus(
            'good',
            $leafPem,
            $issuerPem,
            min(30, (int)CRL_MAX_TIMEOUT_SEC),
            (int)CRL_POLL_INTERVAL_MS,
            null,
            'account_certificate.pem',
            $issuerSourceUrl
        );
        $pollLog = array_merge($pollLog, (array)($initialWait['poll_log'] ?? []));

        expect($initialWait['status'])->toBe(
            'good',
            CertificateCrlFlowHelper::propagationDebugReport(
                'TEST_USER_1',
                $oldSerial,
                '',
                $pollLog,
                null,
                (int)CRL_SLA_PROPAGATION_MS,
                '',
                'Initial CRL status was not good. '
                . 'log=' . substr((string)($initialWait['log'] ?? ''), 0, 1200)
                . "\nlast_raw=" . substr((string)$initialWait['raw'], 0, 1200),
                $issuerSourceUrl
            )
        );

        [$revokeStatus, $revokeJson, $revokeRaw] = CertificateCrlFlowHelper::revokeCertificate($bearer, $oldUuid);
        $revokeDebug = 'status=' . $revokeStatus
            . ' errors=' . CertificateCrlFlowHelper::joinedErrors($revokeJson)
            . ' raw=' . substr($revokeRaw, 0, 800);

        expect($revokeStatus)->toBe(200, 'Certificate revoke failed. ' . $revokeDebug);
        expect(is_array($revokeJson))->toBeTrue('Certificate revoke returned non-JSON. ' . $revokeDebug);
        expect((int)($revokeJson['success'] ?? 0))->toBe(1, 'Certificate revoke was not successful. ' . $revokeDebug);

        $revocationTimestamp = gmdate('c');
        $revocationHrStart = hrtime(true);
        $deadline = microtime(true) + (int)CRL_MAX_TIMEOUT_SEC;
        $pollIndex = count($pollLog);
        $lastCrlRaw = '';
        $lastCrlError = null;
        $revokedObserved = false;

        while (microtime(true) < $deadline) {
            $pollIndex++;
            $crlResult = CertificateCrlFlowHelper::queryCrlStatus(
                $leafPem,
                $issuerPem,
                'account_certificate.pem',
                $issuerSourceUrl
            );
            $lastCrlRaw = $crlResult['raw'];
            $elapsedMs = (int)round((hrtime(true) - $revocationHrStart) / 1_000_000);

            $pollLog[] = [
                'poll' => $pollIndex,
                'elapsed_ms' => $elapsedMs,
                'status' => $crlResult['status'],
                'error' => $crlResult['error'],
            ];

            if (!empty($crlResult['error']) && empty($crlResult['retryable'])) {
                throw new RuntimeException(
                    CertificateCrlFlowHelper::propagationDebugReport(
                        'TEST_USER_1',
                        $oldSerial,
                        $revocationTimestamp,
                        $pollLog,
                        null,
                        (int)CRL_SLA_PROPAGATION_MS,
                        $newSerialAfterReissue,
                        'Non-retryable CRL error during propagation polling. last_error='
                        . (string)($crlResult['error'] ?? 'unknown')
                        . "\nlast_crl_raw:\n" . substr($lastCrlRaw, 0, 2000),
                        $issuerSourceUrl
                    )
                );
            }

            if (($crlResult['status'] ?? null) === 'revoked') {
                $propagationMs = $elapsedMs;
                $revokedObserved = true;
                break;
            }

            $lastCrlError = $crlResult['error'] ?? ($crlResult['status'] ?? 'unknown');
            usleep((int)CRL_POLL_INTERVAL_MS * 1000);
        }

        if (!$revokedObserved) {
            throw new RuntimeException(
                CertificateCrlFlowHelper::propagationDebugReport(
                    'TEST_USER_1',
                    $oldSerial,
                    $revocationTimestamp,
                    $pollLog,
                    null,
                    (int)CRL_SLA_PROPAGATION_MS,
                    $newSerialAfterReissue,
                    'CRL did not list the certificate as revoked within timeout. last_error='
                    . (string)$lastCrlError
                    . "\nlast_crl_raw:\n" . substr($lastCrlRaw, 0, 2000),
                    $issuerSourceUrl
                )
            );
        }

        expect($propagationMs)->not->toBeNull();
        expect($propagationMs)->toBeLessThanOrEqual(
            (int)CRL_SLA_PROPAGATION_MS,
            'CRL SLA ALERT: revocation propagation exceeded threshold. '
            . CertificateCrlFlowHelper::propagationDebugReport(
                'TEST_USER_1',
                $oldSerial,
                $revocationTimestamp,
                $pollLog,
                (int)$propagationMs,
                (int)CRL_SLA_PROPAGATION_MS,
                $newSerialAfterReissue,
                'Propagation time exceeded SLA.',
                $issuerSourceUrl
            )
        );
    } catch (Throwable $e) {
        $testFailure = $e;
    } finally {
        try {
            $reissue = CertificateCrlFlowHelper::reissueFreshCertificate(
                $bearer,
                $usage !== '' ? $usage : 'document_signing',
                $oldSerial
            );
            $cleanupDebug = (string)($reissue['debug'] ?? '');
            if (!empty($reissue['ok'])) {
                $newSerialAfterReissue = (string)($reissue['new_serial_number'] ?? '');
            }
        } catch (Throwable $cleanupError) {
            $cleanupDebug .= ($cleanupDebug !== '' ? "\n" : '')
                . 'cleanup_exception=' . $cleanupError->getMessage();
        }
    }

    if ($testFailure !== null) {
        $enriched = CertificateCrlFlowHelper::propagationDebugReport(
            'TEST_USER_1',
            $oldSerial,
            $revocationTimestamp,
            $pollLog,
            $propagationMs,
            (int)CRL_SLA_PROPAGATION_MS,
            $newSerialAfterReissue,
            $cleanupDebug !== '' ? "cleanup:\n{$cleanupDebug}" : 'cleanup attempted',
            $issuerSourceUrl
        );

        if ($testFailure instanceof PHPUnit\Framework\ExpectationFailedException) {
            throw new PHPUnit\Framework\ExpectationFailedException(
                $testFailure->getMessage() . "\n\n" . $enriched,
                $testFailure->getComparisonFailure()
            );
        }

        throw new RuntimeException($testFailure->getMessage() . "\n\n" . $enriched, 0, $testFailure);
    }

    expect($newSerialAfterReissue)->not->toBe(
        '',
        'Cleanup did not observe a new certificate serial after reissue. ' . $cleanupDebug
    );
})->skip(SKIP_CRL_VALIDATION_TESTS);
