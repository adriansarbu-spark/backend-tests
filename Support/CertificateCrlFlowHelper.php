<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once DIR_SYSTEM . 'library/samX509.php';

use GuzzleHttp\Client;
use phpseclib3\Math\BigInteger;
use samx509\SamX509;

/**
 * HTTP + CRL helpers for certificate revocation propagation integration tests.
 */
final class CertificateCrlFlowHelper
{
    private const CLOCK_SKEW_SEC = 60;

    public static function assertRequiredConfigOrSkip(): void
    {
        $required = [
            'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
            'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
            'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
            'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
            'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
            'TEST_USER_1_TOTP_SECRET' => defined('TEST_USER_1_TOTP_SECRET') ? TEST_USER_1_TOTP_SECRET : '',
            'CRL_POLL_INTERVAL_MS' => defined('CRL_POLL_INTERVAL_MS') ? (string)CRL_POLL_INTERVAL_MS : '',
            'CRL_MAX_TIMEOUT_SEC' => defined('CRL_MAX_TIMEOUT_SEC') ? (string)CRL_MAX_TIMEOUT_SEC : '',
            'CRL_SLA_PROPAGATION_MS' => defined('CRL_SLA_PROPAGATION_MS') ? (string)CRL_SLA_PROPAGATION_MS : '',
        ];

        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }

        if (!self::opensslCliAvailable()) {
            test()->markTestSkipped('openssl command is not available in this environment.');
        }

        if (!class_exists(SamX509::class)) {
            test()->markTestSkipped('samX509 certificate library is not available in this environment.');
        }
    }

    public static function assertCrlValidationConfigOrSkip(): void
    {
        self::assertRequiredConfigOrSkip();

        $required = [
            'CRL_THIS_UPDATE_MAX_AGE_SEC' => defined('CRL_THIS_UPDATE_MAX_AGE_SEC')
                ? (string)CRL_THIS_UPDATE_MAX_AGE_SEC
                : '',
            'CRL_NEXT_UPDATE_WARNING_SEC' => defined('CRL_NEXT_UPDATE_WARNING_SEC')
                ? (string)CRL_NEXT_UPDATE_WARNING_SEC
                : '',
        ];

        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }
    }

    public static function bearerForUser1(): string
    {
        return ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    }

    public static function certificatesUrl(): string
    {
        return API_URL . 'account/certificates';
    }

    public static function certificatesListUrl(int $page = 1, int $perPage = 20): string
    {
        return self::certificatesUrl() . '?' . http_build_query([
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public static function certificatesRequestUrl(): string
    {
        return self::certificatesUrl() . '?request=1';
    }

    public static function certificateActionUrl(string $certificateUuid, string $action, ?string $format = null): string
    {
        $query = [
            'certificate_uuid' => $certificateUuid,
            'action' => $action,
        ];
        if ($format !== null && $format !== '') {
            $query['format'] = $format;
        }

        return self::certificatesUrl() . '?' . http_build_query($query);
    }

    public static function revokeUrl(string $certificateUuid): string
    {
        return self::certificateActionUrl($certificateUuid, 'revoke');
    }

    public static function downloadUrl(string $certificateUuid, string $format = 'cer'): string
    {
        return self::certificateActionUrl($certificateUuid, 'download', $format);
    }

    /**
     * @return array{0:int,1:?array,2:string}
     */
    public static function listCertificates(string $bearer, int $page = 1, int $perPage = 20): array
    {
        return ApiAuthHelper::apiRequest('GET', self::certificatesListUrl($page, $perPage), $bearer);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findFirstCertificateInList(?array $json, string $usage, string $status): ?array
    {
        if (!is_array($json)) {
            return null;
        }

        foreach ((array)($json['data']['certificates'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['usage'] ?? '') === $usage && (string)($row['status'] ?? '') === $status) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{0:int,1:?array,2:string}
     */
    public static function downloadCertificateJson(string $bearer, string $certificateUuid, string $format = 'cer'): array
    {
        return ApiAuthHelper::apiRequest('GET', self::downloadUrl($certificateUuid, $format), $bearer, [
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * @param array<string,mixed> $certificateListRow
     * @return array{leaf_pem:string,issuer_pem:string,issuer_source_url:string,serial_number:string,certificate_uuid:string,usage:string}
     */
    public static function certificateMaterialForCrl(string $bearer, array $certificateListRow): array
    {
        $uuid = (string)($certificateListRow['certificate_uuid'] ?? '');
        $serial = (string)($certificateListRow['serial_number'] ?? '');
        $usage = (string)($certificateListRow['usage'] ?? 'document_signing');

        if ($uuid === '') {
            throw new RuntimeException('Certificate list row is missing certificate_uuid.');
        }
        if ($serial === '') {
            throw new RuntimeException('Certificate list row is missing serial_number.');
        }

        [$downloadStatus, $downloadJson, $downloadRaw] = self::downloadCertificateJson($bearer, $uuid, 'cer');
        if ($downloadStatus !== 200 || !is_array($downloadJson)) {
            throw new RuntimeException(
                'Certificate download failed. status=' . $downloadStatus . ' raw=' . substr($downloadRaw, 0, 800)
            );
        }

        $derBase64 = (string)($downloadJson['data']['certificate_der_base64'] ?? '');
        if ($derBase64 === '') {
            throw new RuntimeException('Certificate download response is missing certificate_der_base64.');
        }

        $leafPem = self::derBase64ToPem($derBase64);
        $issuer = self::resolveIssuerCertPemFromLeaf($leafPem);

        return [
            'leaf_pem' => $leafPem,
            'issuer_pem' => $issuer['issuer_pem'],
            'issuer_source_url' => $issuer['issuer_source_url'],
            'serial_number' => $serial,
            'certificate_uuid' => $uuid,
            'usage' => $usage,
        ];
    }

    /**
     * @return array{subject:string,issuer:string,not_before:string,not_after:string,serial:string,crl_uris:list<string>,ca_issuer_uris:list<string>,raw:string}
     */
    public static function inspectCertificatePem(string $pem, string $label = 'certificate'): array
    {
        $file = self::writeTempPem('inspect', $pem);
        try {
            $result = self::runCommand(
                sprintf(
                    'openssl x509 -in %s -noout -subject -issuer -dates -serial',
                    escapeshellarg($file)
                )
            );

            if ($result['exit_code'] !== 0) {
                throw new RuntimeException(
                    "Failed to inspect {$label} certificate.\n" . $result['output']
                );
            }

            return [
                'subject' => self::extractOpenSslField($result['output'], 'subject'),
                'issuer' => self::extractOpenSslField($result['output'], 'issuer'),
                'not_before' => self::extractOpenSslField($result['output'], 'notBefore'),
                'not_after' => self::extractOpenSslField($result['output'], 'notAfter'),
                'serial' => self::extractOpenSslField($result['output'], 'serial'),
                'crl_uris' => self::extractCrlDistributionPointUrlsFromCertPem($pem),
                'ca_issuer_uris' => self::extractCaIssuerUrlsFromCertPem($pem),
                'raw' => $result['output'],
            ];
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array{ok:bool,output:string,exit_code:int}
     */
    public static function verifyLeafAgainstIssuer(string $leafPem, string $issuerPem): array
    {
        $leafFile = self::writeTempPem('leaf', $leafPem);
        $issuerFile = self::writeTempPem('issuer', $issuerPem);

        try {
            $result = self::runCommand(
                sprintf(
                    'openssl verify -partial_chain -trusted %s %s',
                    escapeshellarg($issuerFile),
                    escapeshellarg($leafFile)
                )
            );

            $ok = $result['exit_code'] === 0
                && (str_contains($result['output'], ': OK') || str_contains($result['output'], ' OK'));

            return [
                'ok' => $ok,
                'output' => $result['output'],
                'exit_code' => $result['exit_code'],
            ];
        } finally {
            @unlink($leafFile);
            @unlink($issuerFile);
        }
    }

    /**
     * @return list<string>
     */
    public static function extractCrlDistributionPointUrlsFromCertPem(string $pem): array
    {
        $x509 = new SamX509();
        if ($x509->loadX509($pem) === false) {
            throw new RuntimeException('Failed to load leaf certificate for CRL distribution point extraction.');
        }

        $extension = $x509->getExtension('id-ce-cRLDistributionPoints');
        $urls = self::extractUniformResourceIdentifiers($extension);

        $unique = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $unique[$url] = $url;
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<string>
     */
    public static function extractCaIssuerUrlsFromCertPem(string $pem): array
    {
        $x509 = new SamX509();
        if ($x509->loadX509($pem) === false) {
            throw new RuntimeException('Failed to load leaf certificate for CA issuer URL extraction.');
        }

        $extension = $x509->getExtension('id-pe-authorityInfoAccess');
        if (!is_array($extension)) {
            return [];
        }

        $urls = [];
        foreach ($extension as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string)($entry['accessMethod'] ?? '') !== 'id-ad-caIssuers') {
                continue;
            }

            $location = $entry['accessLocation'] ?? null;
            if (!is_array($location)) {
                continue;
            }

            $url = trim((string)($location['uniformResourceIdentifier'] ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * @return list<string>
     */
    public static function buildCaIssuerCertCandidateUrls(string $leafPem, ?SamX509 $crlSignerX509 = null, ?string $pkiSeedUrl = null): array
    {
        $seedUrls = array_merge(
            self::extractCaIssuerUrlsFromCertPem($leafPem),
            self::extractCrlDistributionPointUrlsFromCertPem($leafPem)
        );
        if ($pkiSeedUrl !== null && $pkiSeedUrl !== '') {
            $seedUrls[] = $pkiSeedUrl;
        }

        $candidates = [];
        foreach ($seedUrls as $seedUrl) {
            foreach (self::deriveCaCertUrlsFromPkiUrl($seedUrl) as $candidate) {
                $candidates[$candidate] = $candidate;
            }

            if (!self::urlLooksLikeCrlEndpoint($seedUrl)) {
                $candidates[$seedUrl] = $seedUrl;
            }
        }

        if ($crlSignerX509 instanceof SamX509) {
            foreach (self::extractCrlDistributionPointUrlsFromCertPem($leafPem) as $crlUrl) {
                foreach (self::issuerCommonNameCertUrls($crlUrl, self::commonNameFromX509($crlSignerX509, true)) as $candidate) {
                    $candidates[$candidate] = $candidate;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * Resolve the CA certificate that signs the CRL referenced by the leaf certificate.
     *
     * @return array{issuer_pem:string,issuer_source_url:string}
     */
    public static function resolveIssuerCertPemFromLeaf(string $leafPem): array
    {
        $crlUrls = self::extractCrlDistributionPointUrlsFromCertPem($leafPem);
        if ($crlUrls === []) {
            throw new RuntimeException('Leaf certificate does not contain a valid CRL distribution point URL.');
        }

        $errors = [];
        foreach ($crlUrls as $crlUrl) {
            $download = self::downloadPkixResource($crlUrl, 'crl');
            if (!empty($download['retryable']) || !empty($download['error'])) {
                $errors[] = $crlUrl . ': ' . (string)($download['error'] ?? 'crl_download_failed');
                continue;
            }

            $crlBody = (string)$download['body'];
            if ($crlBody === '' || !self::isCrlBody($crlBody)) {
                $errors[] = $crlUrl . ': crl_malformed';
                continue;
            }

            $crlX509 = new SamX509();
            if ($crlX509->loadCRL(self::normalizeCrlBody($crlBody)) === false) {
                $errors[] = $crlUrl . ': crl_parse_failed';
                continue;
            }

            $resolved = self::resolveCaCertForCrlSigner($leafPem, $crlX509, $crlUrl, $errors);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new RuntimeException(
            'Could not resolve CRL signing CA certificate from leaf CRL distribution points. '
            . implode(' | ', $errors)
        );
    }

    /**
     * @param list<string> $errors
     * @return array{issuer_pem:string,issuer_source_url:string}|null
     */
    private static function resolveCaCertForCrlSigner(
        string $leafPem,
        SamX509 $crlX509,
        string $crlUrl,
        array &$errors
    ): ?array {
        $caIssuerUrls = self::buildCaIssuerCertCandidateUrls($leafPem, $crlX509, $crlUrl);

        foreach ($caIssuerUrls as $caIssuerUrl) {
            if (self::urlLooksLikeCrlEndpoint($caIssuerUrl)) {
                continue;
            }

            $download = self::downloadPkixResource($caIssuerUrl, 'ca_issuer');
            if (!empty($download['retryable']) || !empty($download['error'])) {
                $errors[] = $caIssuerUrl . ': ' . (string)($download['error'] ?? 'download_failed');
                continue;
            }

            $body = (string)$download['body'];
            if (self::isCrlBody($body)) {
                $errors[] = $caIssuerUrl . ': ca_issuer_response_is_crl';
                continue;
            }

            $issuerPem = self::normalizeCertificateBody($body);
            if ($issuerPem === '') {
                $errors[] = $caIssuerUrl . ': ca_issuer_malformed';
                continue;
            }

            $issuerX509 = new SamX509();
            if ($issuerX509->loadX509($issuerPem) === false) {
                $errors[] = $caIssuerUrl . ': ca_issuer_parse_failed';
                continue;
            }

            if (!self::crlIssuerMatchesCaSubject($crlX509, $issuerX509)) {
                $errors[] = $caIssuerUrl . ': crl_signer_dn_mismatch';
                continue;
            }

            return [
                'issuer_pem' => $issuerPem,
                'issuer_source_url' => $caIssuerUrl,
            ];
        }

        return null;
    }

    /**
     * @param array{verify_signature?:bool,check_thresholds?:bool,leaf_label?:string,expected_status?:string,issuer_source_url?:string} $options
     * @return array{
     *   status:?string,
     *   error:?string,
     *   retryable:bool,
     *   raw:string,
     *   crl_url:string,
     *   leaf_subject:string,
     *   leaf_issuer:string,
     *   leaf_serial:string,
     *   issuer_subject:string,
     *   signature_verified:?bool,
     *   this_update:?string,
     *   next_update:?string,
     *   this_update_unix:?int,
     *   next_update_unix:?int,
     *   threshold_violations:list<string>,
     *   log:string
     * }
     */
    public static function performCrlCheck(string $leafPem, ?string $issuerPem = null, array $options = []): array
    {
        $verifySignature = (bool)($options['verify_signature'] ?? false);
        $checkThresholds = (bool)($options['check_thresholds'] ?? false);
        $leafLabel = (string)($options['leaf_label'] ?? 'leaf.pem');
        $expectedStatus = strtolower((string)($options['expected_status'] ?? ''));

        $issuerSourceUrl = (string)($options['issuer_source_url'] ?? '');
        if ($issuerPem === null || trim($issuerPem) === '') {
            $resolvedIssuer = self::resolveIssuerCertPemFromLeaf($leafPem);
            $issuerPem = $resolvedIssuer['issuer_pem'];
            $issuerSourceUrl = $resolvedIssuer['issuer_source_url'];
        }

        $leafInspection = self::inspectCertificatePem($leafPem, 'leaf');
        $issuerInspection = self::inspectCertificatePem($issuerPem, 'issuer');

        $crlUrls = $leafInspection['crl_uris'];
        if ($crlUrls === []) {
            throw new RuntimeException('Leaf certificate does not contain a valid CRL distribution point URL.');
        }

        $logLines = [
            'leaf_label=' . $leafLabel,
            'leaf_subject=' . $leafInspection['subject'],
            'leaf_issuer=' . $leafInspection['issuer'],
            'leaf_serial=' . $leafInspection['serial'],
            'leaf_not_before=' . $leafInspection['not_before'],
            'leaf_not_after=' . $leafInspection['not_after'],
            'issuer_source_url=' . ($issuerSourceUrl !== '' ? $issuerSourceUrl : '(from_leaf_aia)'),
            'issuer_subject=' . $issuerInspection['subject'],
            'issuer_not_before=' . $issuerInspection['not_before'],
            'issuer_not_after=' . $issuerInspection['not_after'],
            'crl_urls=' . implode(',', $crlUrls),
        ];

        $lastFailure = null;
        foreach ($crlUrls as $crlUrl) {
            $download = self::downloadCrl($crlUrl);
            $logLines[] = 'crl_url=' . $crlUrl;
            $logLines[] = 'crl_http_status=' . (string)($download['http_status'] ?? 0);

            if (!empty($download['retryable'])) {
                $lastFailure = [
                    'status' => null,
                    'error' => (string)($download['error'] ?? 'crl_download_failed'),
                    'retryable' => true,
                    'raw' => (string)($download['raw'] ?? ''),
                    'crl_url' => $crlUrl,
                ];
                continue;
            }

            if (!empty($download['error'])) {
                $lastFailure = [
                    'status' => null,
                    'error' => (string)$download['error'],
                    'retryable' => false,
                    'raw' => (string)($download['raw'] ?? ''),
                    'crl_url' => $crlUrl,
                ];
                break;
            }

            $validation = self::validateDownloadedCrl(
                (string)$download['body'],
                $crlUrl,
                $crlUrls,
                $issuerPem,
                $leafInspection['serial'],
                $verifySignature,
                $checkThresholds
            );

            $logLines = array_merge($logLines, (array)($validation['log_lines'] ?? []));
            $logLines[] = 'crl_status=' . ($validation['status'] ?? '(not found)');
            $logLines[] = 'signature_verified=' . ($validation['signature_verified'] === null
                ? '(skipped)'
                : ($validation['signature_verified'] ? 'true' : 'false'));
            $logLines[] = 'this_update=' . ($validation['this_update'] ?? '(not found)');
            $logLines[] = 'next_update=' . ($validation['next_update'] ?? '(not found)');
            if (($validation['threshold_violations'] ?? []) !== []) {
                $logLines[] = 'threshold_violations=' . implode(',', $validation['threshold_violations']);
            }
            $logLines[] = 'crl_raw=' . substr((string)($validation['raw'] ?? ''), 0, 2000);

            if (!empty($validation['error'])) {
                $lastFailure = [
                    'status' => $validation['status'],
                    'error' => (string)$validation['error'],
                    'retryable' => false,
                    'raw' => (string)($validation['raw'] ?? ''),
                    'crl_url' => $crlUrl,
                    'signature_verified' => $validation['signature_verified'] ?? null,
                    'this_update' => $validation['this_update'] ?? null,
                    'next_update' => $validation['next_update'] ?? null,
                    'this_update_unix' => $validation['this_update_unix'] ?? null,
                    'next_update_unix' => $validation['next_update_unix'] ?? null,
                    'threshold_violations' => $validation['threshold_violations'] ?? [],
                ];
                break;
            }

            if ($expectedStatus !== '' && ($validation['status'] ?? null) !== $expectedStatus) {
                return self::buildCrlResult(
                    $validation['status'],
                    'crl_status_mismatch',
                    (string)($validation['raw'] ?? ''),
                    $crlUrl,
                    $leafInspection,
                    $issuerInspection,
                    $validation['signature_verified'] ?? null,
                    $validation['this_update'] ?? null,
                    $validation['next_update'] ?? null,
                    $validation['this_update_unix'] ?? null,
                    $validation['next_update_unix'] ?? null,
                    $validation['threshold_violations'] ?? [],
                    false,
                    $logLines
                );
            }

            return self::buildCrlResult(
                $validation['status'],
                null,
                (string)($validation['raw'] ?? ''),
                $crlUrl,
                $leafInspection,
                $issuerInspection,
                $validation['signature_verified'] ?? null,
                $validation['this_update'] ?? null,
                $validation['next_update'] ?? null,
                $validation['this_update_unix'] ?? null,
                $validation['next_update_unix'] ?? null,
                $validation['threshold_violations'] ?? [],
                false,
                $logLines
            );
        }

        if ($lastFailure === null) {
            $lastFailure = [
                'status' => null,
                'error' => 'crl_download_failed',
                'retryable' => true,
                'raw' => '',
                'crl_url' => $crlUrls[0] ?? '',
            ];
        }

        return self::buildCrlResult(
            $lastFailure['status'],
            $lastFailure['error'],
            $lastFailure['raw'],
            $lastFailure['crl_url'],
            $leafInspection,
            $issuerInspection,
            $lastFailure['signature_verified'] ?? null,
            $lastFailure['this_update'] ?? null,
            $lastFailure['next_update'] ?? null,
            $lastFailure['this_update_unix'] ?? null,
            $lastFailure['next_update_unix'] ?? null,
            $lastFailure['threshold_violations'] ?? [],
            (bool)$lastFailure['retryable'],
            $logLines
        );
    }

    /**
     * @return array{status:?string,error:?string,retryable:bool,raw:string,log:string}
     */
    public static function queryCrlStatus(
        string $leafPem,
        ?string $issuerPem = null,
        string $leafLabel = 'leaf.pem',
        string $issuerSourceUrl = ''
    ): array {
        $result = self::performCrlCheck($leafPem, $issuerPem, [
            'verify_signature' => true,
            'check_thresholds' => false,
            'leaf_label' => $leafLabel,
            'issuer_source_url' => $issuerSourceUrl,
        ]);

        return [
            'status' => $result['status'],
            'error' => $result['error'],
            'retryable' => $result['retryable'],
            'raw' => $result['raw'],
            'log' => $result['log'],
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   status:?string,
     *   error:?string,
     *   retryable:bool,
     *   raw:string,
     *   signature_verified:?bool,
     *   this_update:?string,
     *   next_update:?string,
     *   threshold_violations:list<string>,
     *   log:string
     * }
     */
    public static function validateCrlForCertificate(
        string $leafPem,
        ?string $issuerPem = null,
        string $leafLabel = 'certificate.pem',
        string $issuerSourceUrl = ''
    ): array {
        $result = self::performCrlCheck($leafPem, $issuerPem, [
            'verify_signature' => true,
            'check_thresholds' => true,
            'leaf_label' => $leafLabel,
            'expected_status' => 'good',
            'issuer_source_url' => $issuerSourceUrl,
        ]);

        $ok = ($result['status'] ?? null) === 'good'
            && ($result['error'] ?? null) === null
            && ($result['signature_verified'] ?? false) === true
            && ($result['threshold_violations'] ?? []) === [];

        return [
            'ok' => $ok,
            'status' => $result['status'],
            'error' => $result['error'],
            'retryable' => $result['retryable'],
            'raw' => $result['raw'],
            'signature_verified' => $result['signature_verified'],
            'this_update' => $result['this_update'],
            'next_update' => $result['next_update'],
            'threshold_violations' => $result['threshold_violations'],
            'log' => $result['log'],
        ];
    }

    /**
     * @return array{status:string,raw:string,attempts:int,poll_log:list<array<string,mixed>>,last_error:?string,log:string}
     */
    public static function waitForCrlStatus(
        string $expectedStatus,
        string $leafPem,
        ?string $issuerPem,
        int $maxWaitSec,
        int $pollIntervalMs,
        ?float $deadlineHrTime = null,
        string $leafLabel = 'leaf.pem',
        string $issuerSourceUrl = ''
    ): array {
        $expectedStatus = strtolower($expectedStatus);
        $pollLog = [];
        $startedAt = hrtime(true);
        $deadline = $deadlineHrTime ?? (microtime(true) + $maxWaitSec);
        $attempt = 0;
        $lastRaw = '';
        $lastError = null;
        $lastLog = '';

        while (microtime(true) < $deadline) {
            $attempt++;
            $result = self::queryCrlStatus($leafPem, $issuerPem, $leafLabel, $issuerSourceUrl);
            $lastRaw = $result['raw'];
            $lastLog = (string)($result['log'] ?? '');
            $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);

            if (!empty($result['error']) && empty($result['retryable'])) {
                $pollLog[] = [
                    'poll' => $attempt,
                    'elapsed_ms' => $elapsedMs,
                    'status' => $result['status'],
                    'error' => $result['error'],
                ];

                throw new RuntimeException(
                    'Non-retryable CRL error while waiting for status=' . $expectedStatus . ': ' . (string)$result['error']
                    . "\nlog=" . substr($lastLog, 0, 1200)
                    . "\nraw=" . substr($lastRaw, 0, 1200)
                );
            }

            if ($result['status'] === $expectedStatus) {
                $pollLog[] = [
                    'poll' => $attempt,
                    'elapsed_ms' => $elapsedMs,
                    'status' => $result['status'],
                    'error' => null,
                ];

                return [
                    'status' => $result['status'],
                    'raw' => $lastRaw,
                    'attempts' => $attempt,
                    'poll_log' => $pollLog,
                    'last_error' => null,
                    'log' => $lastLog,
                ];
            }

            $lastError = $result['error'] ?? ($result['status'] ?? 'unknown');
            $pollLog[] = [
                'poll' => $attempt,
                'elapsed_ms' => $elapsedMs,
                'status' => $result['status'],
                'error' => $lastError,
            ];

            usleep(max(1, $pollIntervalMs) * 1000);
        }

        return [
            'status' => '',
            'raw' => $lastRaw,
            'attempts' => $attempt,
            'poll_log' => $pollLog,
            'last_error' => $lastError ?? 'timeout',
            'log' => $lastLog,
        ];
    }

    /**
     * @return array{0:int,1:?array,2:string}
     */
    public static function postJson(string $url, string $bearer, array $body): array
    {
        return ApiAuthHelper::apiRequest('POST', $url, $bearer, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @return array{0:int,1:?array,2:string}
     */
    public static function revokeCertificate(string $bearer, string $certificateUuid): array
    {
        return self::postJson(self::revokeUrl($certificateUuid), $bearer, [
            'totp_code' => ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_1_TOTP_SECRET),
        ]);
    }

    /**
     * @return array{0:int,1:?array,2:string}
     */
    public static function getIssuancePrerequisites(string $bearer): array
    {
        return ApiAuthHelper::apiRequest('GET', self::certificatesRequestUrl(), $bearer);
    }

    /**
     * @param list<string> $acceptedLegalDocumentUuids
     * @return array{0:int,1:?array,2:string}
     */
    public static function issueCertificate(string $bearer, string $usage, array $acceptedLegalDocumentUuids): array
    {
        return self::postJson(self::certificatesUrl(), $bearer, [
            'usage' => $usage,
            'accepted_legal_document_uuids' => $acceptedLegalDocumentUuids,
            'totp_code' => ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_1_TOTP_SECRET),
        ]);
    }

    /**
     * @param array<string,mixed>|null $json
     */
    public static function joinedErrors(?array $json): string
    {
        if (!is_array($json)) {
            return '';
        }

        return implode(' | ', array_map('strval', (array)($json['error'] ?? [])));
    }

    public static function derBase64ToPem(string $derBase64): string
    {
        $der = base64_decode($derBase64, true);
        if ($der === false || $der === '') {
            throw new RuntimeException('Failed to decode certificate_der_base64.');
        }

        return self::derBytesToPem($der);
    }

    /**
     * @return array{ok:bool,new_serial_number:string,debug:string}
     */
    public static function reissueFreshCertificate(string $bearer, string $usage, string $previousSerial): array
    {
        $debugLines = [];

        [$reqStatus, $reqJson, $reqRaw] = self::getIssuancePrerequisites($bearer);
        if ($reqStatus !== 200 || !is_array($reqJson)) {
            return [
                'ok' => false,
                'new_serial_number' => '',
                'debug' => 'GET certificates/request failed. status=' . $reqStatus . ' raw=' . substr($reqRaw, 0, 800),
            ];
        }

        $uuids = [];
        foreach ((array)($reqJson['data']['documents'] ?? []) as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $uuid = trim((string)($doc['uuid'] ?? ''));
            if ($uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        if ($uuids === []) {
            return [
                'ok' => false,
                'new_serial_number' => '',
                'debug' => 'No legal document UUIDs returned from certificates/request.',
            ];
        }

        [$issueStatus, $issueJson, $issueRaw] = self::issueCertificate($bearer, $usage, $uuids);
        $debugLines[] = 'issue status=' . $issueStatus . ' errors=' . self::joinedErrors($issueJson);
        if ($issueStatus !== 200 || !is_array($issueJson) || (int)($issueJson['success'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'new_serial_number' => '',
                'debug' => implode("\n", $debugLines) . "\nissue raw=" . substr($issueRaw, 0, 800),
            ];
        }

        $newSerial = '';
        $attempts = 12;
        $sleepMs = 500;
        for ($i = 0; $i < $attempts; $i++) {
            [$listStatus, $listJson] = self::listCertificates($bearer);
            if ($listStatus === 200 && is_array($listJson)) {
                $cert = self::findFirstCertificateInList($listJson, $usage, 'valid');
                $serial = (string)($cert['serial_number'] ?? '');
                if ($serial !== '' && strcasecmp($serial, $previousSerial) !== 0) {
                    $newSerial = $serial;
                    break;
                }
            }
            usleep($sleepMs * 1000);
        }

        if ($newSerial === '') {
            return [
                'ok' => false,
                'new_serial_number' => '',
                'debug' => implode("\n", $debugLines) . "\nnew certificate serial not observed after reissue.",
            ];
        }

        return [
            'ok' => true,
            'new_serial_number' => $newSerial,
            'debug' => implode("\n", $debugLines) . "\nnew_serial_number={$newSerial}",
        ];
    }

    /**
     * @param list<array<string,mixed>> $pollLog
     */
    public static function formatPollLog(array $pollLog): string
    {
        if ($pollLog === []) {
            return '(no polls recorded)';
        }

        $lines = [];
        foreach ($pollLog as $entry) {
            $lines[] = sprintf(
                '#%d elapsed_ms=%d status=%s error=%s',
                (int)($entry['poll'] ?? 0),
                (int)($entry['elapsed_ms'] ?? 0),
                (string)($entry['status'] ?? 'null'),
                (string)($entry['error'] ?? 'null')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string,mixed>> $pollLog
     */
    public static function propagationDebugReport(
        string $testUser,
        string $oldSerial,
        string $revocationTimestamp,
        array $pollLog,
        ?int $propagationMs,
        int $slaMs,
        string $newSerial,
        string $extra = '',
        string $issuerSourceUrl = ''
    ): string {
        $lines = [
            'test_user=' . $testUser,
            'old_certificate_serial=' . ($oldSerial !== '' ? $oldSerial : '(none)'),
            'revocation_timestamp=' . ($revocationTimestamp !== '' ? $revocationTimestamp : '(not reached)'),
            'issuer_source_url=' . ($issuerSourceUrl !== '' ? $issuerSourceUrl : '(from_leaf_aia)'),
            'crl_poll_log:',
            self::formatPollLog($pollLog),
            'final_propagation_ms=' . ($propagationMs !== null ? (string)$propagationMs : '(not measured)'),
            'sla_threshold_ms=' . $slaMs,
            'new_certificate_serial=' . ($newSerial !== '' ? $newSerial : '(not reissued)'),
        ];

        if ($extra !== '') {
            $lines[] = $extra;
        }

        return implode("\n", $lines);
    }

    public static function formatCrlValidationFailure(array $validation, string $extra = ''): string
    {
        $lines = [
            'crl_validation_ok=' . (!empty($validation['ok']) ? 'true' : 'false'),
            'crl_status=' . (string)($validation['status'] ?? '(null)'),
            'crl_error=' . (string)($validation['error'] ?? '(null)'),
            'signature_verified=' . (string)($validation['signature_verified'] ?? '(null)'),
            'this_update=' . (string)($validation['this_update'] ?? '(null)'),
            'next_update=' . (string)($validation['next_update'] ?? '(null)'),
            'threshold_violations=' . implode(',', (array)($validation['threshold_violations'] ?? [])),
            'crl_log:',
            (string)($validation['log'] ?? '(no log)'),
        ];

        if ($extra !== '') {
            $lines[] = $extra;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public static function evaluateCrlTimestampThresholds(?int $thisUpdateUnix, ?int $nextUpdateUnix): array
    {
        $violations = [];
        $now = time();
        $maxAgeSec = defined('CRL_THIS_UPDATE_MAX_AGE_SEC') ? (int)CRL_THIS_UPDATE_MAX_AGE_SEC : 86400;
        $warningSec = defined('CRL_NEXT_UPDATE_WARNING_SEC') ? (int)CRL_NEXT_UPDATE_WARNING_SEC : 3600;

        if ($thisUpdateUnix === null) {
            $violations[] = 'thisUpdate_missing';
        } elseif ($now - $thisUpdateUnix > $maxAgeSec) {
            $violations[] = 'thisUpdate_too_old';
        }

        if ($nextUpdateUnix === null) {
            $violations[] = 'nextUpdate_missing';
        } elseif ($nextUpdateUnix + self::CLOCK_SKEW_SEC < $now) {
            $violations[] = 'nextUpdate_expired';
        } elseif ($nextUpdateUnix - $now < $warningSec) {
            $violations[] = 'nextUpdate_too_close_to_expiry';
        }

        return $violations;
    }

    public static function canonicalSerialHex(string $serial): string
    {
        $hex = strtolower(self::serialToBigInteger($serial)->toHex());

        return ltrim($hex, '0') ?: '0';
    }

    public static function serialNumbersEqual(string $left, string $right): bool
    {
        return self::serialToBigInteger($left)->equals(self::serialToBigInteger($right));
    }

    public static function serialToBigInteger(string $serial): BigInteger
    {
        $serial = trim($serial);
        if (preg_match('/^serial\s*=/i', $serial) === 1) {
            $serial = trim(substr($serial, (int)strpos($serial, '=') + 1));
        }

        $clean = str_replace([':', ' ', '0x', '0X'], '', $serial);
        if ($clean === '') {
            return new BigInteger(0);
        }

        if (preg_match('/^[0-9A-Fa-f]+$/', $clean) === 1 && preg_match('/[A-Fa-f]/', $clean) === 1) {
            return new BigInteger($clean, 16);
        }

        if (preg_match('/^[0-9A-Fa-f]+$/', $clean) === 1 && strlen($clean) > 15) {
            return new BigInteger($clean, 16);
        }

        return new BigInteger($clean);
    }

    /**
     * @return array{http_status:int,body:string,error:?string,retryable:bool,raw:string}
     */
    private static function downloadCrl(string $url): array
    {
        return self::downloadPkixResource($url, 'crl');
    }

    /**
     * @return array{http_status:int,body:string,error:?string,retryable:bool,raw:string}
     */
    private static function downloadPkixResource(string $url, string $resourceLabel = 'resource'): array
    {
        $timeoutSec = defined('CRL_DOWNLOAD_TIMEOUT_SEC') ? (int)CRL_DOWNLOAD_TIMEOUT_SEC : 10;
        $client = new Client([
            'http_errors' => false,
            'timeout' => max(1, $timeoutSec),
            'allow_redirects' => true,
        ]);

        $requestUrl = self::appendCacheBustingQueryParam($url);

        try {
            $response = $client->request('GET', $requestUrl, [
                'headers' => [
                    'Cache-Control' => 'no-cache, no-store',
                    'Pragma' => 'no-cache',
                ],
            ]);
        } catch (Throwable $e) {
            return [
                'http_status' => 0,
                'body' => '',
                'error' => $resourceLabel . '_download_failed',
                'retryable' => true,
                'raw' => 'download_exception=' . $e->getMessage(),
            ];
        }

        $status = (int)$response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status >= 500) {
            return [
                'http_status' => $status,
                'body' => $body,
                'error' => $resourceLabel . '_http_5xx',
                'retryable' => true,
                'raw' => 'http_status=' . $status . ' body_prefix=' . substr($body, 0, 200),
            ];
        }

        if ($status < 200 || $status >= 300 || $body === '') {
            return [
                'http_status' => $status,
                'body' => $body,
                'error' => $resourceLabel . '_download_failed',
                'retryable' => false,
                'raw' => 'http_status=' . $status . ' body_prefix=' . substr($body, 0, 200),
            ];
        }

        return [
            'http_status' => $status,
            'body' => $body,
            'error' => null,
            'retryable' => false,
            'raw' => 'http_status=' . $status . ' bytes=' . strlen($body),
        ];
    }

    /**
     * @param list<string> $certificateCrlUrls
     * @return array{
     *   status:?string,
     *   error:?string,
     *   raw:string,
     *   signature_verified:?bool,
     *   this_update:?string,
     *   next_update:?string,
     *   this_update_unix:?int,
     *   next_update_unix:?int,
     *   threshold_violations:list<string>,
     *   log_lines:list<string>
     * }
     */
    private static function validateDownloadedCrl(
        string $crlBody,
        string $downloadedUrl,
        array $certificateCrlUrls,
        string $issuerPem,
        string $leafSerial,
        bool $verifySignature,
        bool $checkThresholds
    ): array {
        $logLines = [];
        $crlPemOrDer = self::normalizeCrlBody($crlBody);
        if ($crlPemOrDer === '') {
            return [
                'status' => null,
                'error' => 'crl_malformed',
                'raw' => 'empty_or_unrecognized_crl_body',
                'signature_verified' => false,
                'this_update' => null,
                'next_update' => null,
                'this_update_unix' => null,
                'next_update_unix' => null,
                'threshold_violations' => [],
                'log_lines' => ['crl_parse=failed'],
            ];
        }

        $issuerX509 = new SamX509();
        if ($issuerX509->loadX509($issuerPem) === false) {
            return [
                'status' => null,
                'error' => 'crl_issuer_load_failed',
                'raw' => 'failed_to_load_ca_issuer',
                'signature_verified' => false,
                'this_update' => null,
                'next_update' => null,
                'this_update_unix' => null,
                'next_update_unix' => null,
                'threshold_violations' => [],
                'log_lines' => ['issuer_load=failed'],
            ];
        }

        $crlX509 = new SamX509();
        $crlX509->loadCA($issuerPem);
        $loadedCrl = $crlX509->loadCRL($crlPemOrDer);
        if ($loadedCrl === false) {
            return [
                'status' => null,
                'error' => 'crl_malformed',
                'raw' => 'samX509_loadCRL_failed',
                'signature_verified' => false,
                'this_update' => null,
                'next_update' => null,
                'this_update_unix' => null,
                'next_update_unix' => null,
                'threshold_violations' => [],
                'log_lines' => ['crl_parse=failed'],
            ];
        }

        if (!self::crlUrlIsApplicable($downloadedUrl, $certificateCrlUrls)) {
            return [
                'status' => null,
                'error' => 'crl_not_applicable',
                'raw' => 'downloaded_url_not_in_certificate_crl_distribution_points',
                'signature_verified' => null,
                'this_update' => null,
                'next_update' => null,
                'this_update_unix' => null,
                'next_update_unix' => null,
                'threshold_violations' => [],
                'log_lines' => ['crl_applicability=failed'],
            ];
        }

        $crlIssuerDn = $crlX509->getIssuerDN(SamX509::DN_STRING);
        $expectedIssuerDn = $issuerX509->getDN(SamX509::DN_STRING);
        if (!self::crlIssuerMatchesCaSubject($crlX509, $issuerX509)) {
            return [
                'status' => null,
                'error' => 'crl_issuer_mismatch',
                'raw' => 'expected_issuer=' . $expectedIssuerDn . ' crl_issuer=' . $crlIssuerDn,
                'signature_verified' => false,
                'this_update' => null,
                'next_update' => null,
                'this_update_unix' => null,
                'next_update_unix' => null,
                'threshold_violations' => [],
                'log_lines' => ['crl_issuer_mismatch=1'],
            ];
        }

        $signatureVerified = null;
        if ($verifySignature) {
            $signatureVerified = $crlX509->validateSignature(true) === true;
            if (!$signatureVerified) {
                return [
                    'status' => null,
                    'error' => 'crl_signature_invalid',
                    'raw' => 'crl_signature_validation_failed',
                    'signature_verified' => false,
                    'this_update' => null,
                    'next_update' => null,
                    'this_update_unix' => null,
                    'next_update_unix' => null,
                    'threshold_violations' => [],
                    'log_lines' => ['crl_signature=invalid'],
                ];
            }
        }

        $thisUpdateRaw = $loadedCrl['tbsCertList']['thisUpdate'] ?? null;
        $nextUpdateRaw = $loadedCrl['tbsCertList']['nextUpdate'] ?? null;
        $thisUpdate = self::formatCrlTimeValue($thisUpdateRaw);
        $nextUpdate = self::formatCrlTimeValue($nextUpdateRaw);
        $thisUpdateUnix = self::parseCrlTimeToUnix($thisUpdateRaw);
        $nextUpdateUnix = self::parseCrlTimeToUnix($nextUpdateRaw);

        $thresholdViolations = $checkThresholds
            ? self::evaluateCrlTimestampThresholds($thisUpdateUnix, $nextUpdateUnix)
            : [];

        if ($thisUpdateUnix !== null && time() + self::CLOCK_SKEW_SEC < $thisUpdateUnix) {
            return [
                'status' => null,
                'error' => 'crl_not_yet_valid',
                'raw' => 'thisUpdate_in_future',
                'signature_verified' => $signatureVerified,
                'this_update' => $thisUpdate,
                'next_update' => $nextUpdate,
                'this_update_unix' => $thisUpdateUnix,
                'next_update_unix' => $nextUpdateUnix,
                'threshold_violations' => $thresholdViolations,
                'log_lines' => ['crl_validity=not_yet_valid'],
            ];
        }

        if ($nextUpdateUnix !== null && $nextUpdateUnix + self::CLOCK_SKEW_SEC < time()) {
            return [
                'status' => null,
                'error' => 'crl_expired',
                'raw' => 'nextUpdate_in_past',
                'signature_verified' => $signatureVerified,
                'this_update' => $thisUpdate,
                'next_update' => $nextUpdate,
                'this_update_unix' => $thisUpdateUnix,
                'next_update_unix' => $nextUpdateUnix,
                'threshold_violations' => $thresholdViolations,
                'log_lines' => ['crl_validity=expired'],
            ];
        }

        if ($thresholdViolations !== []) {
            return [
                'status' => null,
                'error' => 'crl_threshold_violation',
                'raw' => 'threshold_violations=' . implode(',', $thresholdViolations),
                'signature_verified' => $signatureVerified,
                'this_update' => $thisUpdate,
                'next_update' => $nextUpdate,
                'this_update_unix' => $thisUpdateUnix,
                'next_update_unix' => $nextUpdateUnix,
                'threshold_violations' => $thresholdViolations,
                'log_lines' => ['crl_threshold_violation=1'],
            ];
        }

        $deltaIndicator = $crlX509->getExtension('id-ce-deltaCRLIndicator');
        if ($deltaIndicator !== false && $deltaIndicator !== null) {
            $logLines[] = 'crl_type=delta';
        }

        $issuingDistributionPoint = $crlX509->getExtension('id-ce-issuingDistributionPoint');
        if (is_array($issuingDistributionPoint) && !empty($issuingDistributionPoint['indirectCRL'])) {
            $logLines[] = 'crl_type=indirect';
        }

        $revokedSerials = $crlX509->listRevoked();
        $isRevoked = $crlX509->getRevoked(self::serialToBigInteger($leafSerial)->toString()) !== false;

        $status = $isRevoked ? 'revoked' : 'good';
        $logLines[] = 'leaf_serial_canonical=' . self::canonicalSerialHex($leafSerial);
        $logLines[] = 'revoked_entries=' . (is_array($revokedSerials) ? (string)count($revokedSerials) : '0');

        return [
            'status' => $status,
            'error' => null,
            'raw' => 'crl_entries=' . (is_array($revokedSerials) ? count($revokedSerials) : 0)
                . ' status=' . $status
                . ' this_update=' . ($thisUpdate ?? '')
                . ' next_update=' . ($nextUpdate ?? ''),
            'signature_verified' => $signatureVerified,
            'this_update' => $thisUpdate,
            'next_update' => $nextUpdate,
            'this_update_unix' => $thisUpdateUnix,
            'next_update_unix' => $nextUpdateUnix,
            'threshold_violations' => $thresholdViolations,
            'log_lines' => $logLines,
        ];
    }

    /**
     * @param mixed $extension
     * @return list<string>
     */
    private static function extractUniformResourceIdentifiers(mixed $extension): array
    {
        if (!is_array($extension)) {
            return [];
        }

        $urls = [];
        $walker = function (mixed $node) use (&$walker, &$urls): void {
            if (!is_array($node)) {
                return;
            }

            if (isset($node['uniformResourceIdentifier']) && is_string($node['uniformResourceIdentifier'])) {
                $urls[] = $node['uniformResourceIdentifier'];
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walker($value);
                }
            }
        };

        $walker($extension);

        return $urls;
    }

    private static function normalizeCrlBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, '-----BEGIN X509 CRL-----')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, "\x30")) {
            return $trimmed;
        }

        $decoded = base64_decode($trimmed, true);
        if (is_string($decoded) && $decoded !== '' && str_starts_with($decoded, "\x30")) {
            return $decoded;
        }

        return '';
    }

    private static function normalizeCertificateBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $trimmed, $matches) === 1) {
            return $matches[0] . "\n";
        }

        if (self::isCrlBody($trimmed)) {
            return '';
        }

        if (str_starts_with($trimmed, "\x30")) {
            $pem = self::derBytesToPem($trimmed);
            $x509 = new SamX509();

            return $x509->loadX509($pem) !== false ? $pem : '';
        }

        $decoded = base64_decode($trimmed, true);
        if (is_string($decoded) && $decoded !== '' && str_starts_with($decoded, "\x30") && !self::isCrlBody($decoded)) {
            $pem = self::derBytesToPem($decoded);
            $x509 = new SamX509();

            return $x509->loadX509($pem) !== false ? $pem : '';
        }

        return '';
    }

    private static function commonNameFromX509(SamX509 $x509, bool $issuer = false): string
    {
        $props = $issuer ? $x509->getIssuerDNProp('id-at-commonName') : $x509->getDNProp('id-at-commonName');
        if (is_array($props) && isset($props[0]) && is_string($props[0])) {
            return trim($props[0]);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function issuerCommonNameCertUrls(string $pkiSeedUrl, string $commonName): array
    {
        if ($commonName === '' || preg_match('#^https?://#i', $pkiSeedUrl) !== 1) {
            return [];
        }

        $path = (string)(parse_url($pkiSeedUrl, PHP_URL_PATH) ?? '');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($directory === '.' || $directory === '') {
            return [];
        }

        $scheme = (string)(parse_url($pkiSeedUrl, PHP_URL_SCHEME) ?? 'https');
        $host = (string)(parse_url($pkiSeedUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return [];
        }

        $port = parse_url($pkiSeedUrl, PHP_URL_PORT);
        $authorities = [
            $scheme . '://' . $host . ($port !== null ? ':' . $port : ''),
        ];
        if (strcasecmp($scheme, 'http') === 0) {
            $authorities[] = 'https://' . $host . ($port !== null ? ':' . $port : '');
        }

        $nameVariants = array_values(array_unique([
            $commonName,
            str_replace('Intermdiate', 'Intermediate', $commonName),
        ]));

        $candidates = [];
        foreach ($authorities as $authority) {
            foreach ($nameVariants as $nameVariant) {
                $filename = preg_replace('/\s+/', '_', trim($nameVariant));
                if (!is_string($filename) || $filename === '') {
                    continue;
                }
                foreach (['crt', 'cer', 'pem', 'der'] as $extension) {
                    $candidates[] = $authority . $directory . '/' . $filename . '.' . $extension;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function crlIssuerMatchesCaSubject(SamX509 $crlX509, SamX509 $issuerX509): bool
    {
        $crlIssuerCanon = $crlX509->getIssuerDN(SamX509::DN_CANON);
        $caSubjectCanon = $issuerX509->getDN(SamX509::DN_CANON);

        return is_string($crlIssuerCanon)
            && is_string($caSubjectCanon)
            && $crlIssuerCanon === $caSubjectCanon;
    }

    private static function urlLooksLikeCrlEndpoint(string $url): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

        return preg_match('/\.crl$/i', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private static function deriveCaCertUrlsFromPkiUrl(string $url): array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return [];
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return [];
        }

        $basePath = preg_replace('/\.(crl|crt|cer|pem|der)$/i', '', $path);
        if (!is_string($basePath) || $basePath === '') {
            return [];
        }

        $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?? 'https');
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT);
        $authority = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

        $candidates = [];
        foreach (['crt', 'cer', 'pem', 'der'] as $extension) {
            $candidates[] = $authority . $basePath . '.' . $extension;
        }

        if (strcasecmp($scheme, 'http') === 0) {
            foreach (['crt', 'cer', 'pem', 'der'] as $extension) {
                $candidates[] = 'https://' . $host . ($port !== null ? ':' . $port : '') . $basePath . '.' . $extension;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function isCrlBody(string $body): bool
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return false;
        }

        if (str_contains($trimmed, '-----BEGIN X509 CRL-----')) {
            return true;
        }

        $crlMaterial = self::normalizeCrlBody($trimmed);
        if ($crlMaterial === '') {
            return false;
        }

        $crlX509 = new SamX509();

        return $crlX509->loadCRL($crlMaterial) !== false;
    }

    private static function appendCacheBustingQueryParam(string $url): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . '_=' . (string)hrtime(true);
    }

    /**
     * @param list<string> $certificateCrlUrls
     */
    private static function crlUrlIsApplicable(string $downloadedUrl, array $certificateCrlUrls): bool
    {
        $downloadedUrl = rtrim($downloadedUrl, '/');
        foreach ($certificateCrlUrls as $candidate) {
            if (rtrim($candidate, '/') === $downloadedUrl) {
                return true;
            }
        }

        return false;
    }

    private static function formatCrlTimeValue(mixed $field): ?string
    {
        if ($field === null) {
            return null;
        }

        if (is_array($field)) {
            $field = $field['utcTime'] ?? $field['generalTime'] ?? null;
        }

        if ($field === null || $field === '') {
            return null;
        }

        if (is_object($field) && method_exists($field, '__toString')) {
            $field = (string)$field;
        }

        return trim((string)$field);
    }

    private static function parseCrlTimeToUnix(mixed $field): ?int
    {
        $formatted = self::formatCrlTimeValue($field);
        if ($formatted === null || $formatted === '') {
            return null;
        }

        return self::parseOpenSslDateToUnix($formatted);
    }

    /**
     * @param array{subject:string,issuer:string,not_before:string,not_after:string,serial:string,crl_uris:list<string>} $leafInspection
     * @param array{subject:string,issuer:string,not_before:string,not_after:string,serial:string,crl_uris:list<string>} $issuerInspection
     * @param list<string> $thresholdViolations
     * @param list<string> $logLines
     * @return array{
     *   status:?string,
     *   error:?string,
     *   raw:string,
     *   crl_url:string,
     *   leaf_subject:string,
     *   leaf_issuer:string,
     *   leaf_serial:string,
     *   issuer_subject:string,
     *   signature_verified:?bool,
     *   this_update:?string,
     *   next_update:?string,
     *   this_update_unix:?int,
     *   next_update_unix:?int,
     *   threshold_violations:list<string>,
     *   retryable:bool,
     *   log:string
     * }
     */
    private static function buildCrlResult(
        ?string $status,
        ?string $error,
        string $raw,
        string $crlUrl,
        array $leafInspection,
        array $issuerInspection,
        ?bool $signatureVerified,
        ?string $thisUpdate,
        ?string $nextUpdate,
        ?int $thisUpdateUnix,
        ?int $nextUpdateUnix,
        array $thresholdViolations,
        bool $retryable,
        array $logLines
    ): array {
        return [
            'status' => $status,
            'error' => $error,
            'raw' => $raw,
            'crl_url' => $crlUrl,
            'leaf_subject' => $leafInspection['subject'],
            'leaf_issuer' => $leafInspection['issuer'],
            'leaf_serial' => $leafInspection['serial'],
            'issuer_subject' => $issuerInspection['subject'],
            'signature_verified' => $signatureVerified,
            'this_update' => $thisUpdate,
            'next_update' => $nextUpdate,
            'this_update_unix' => $thisUpdateUnix,
            'next_update_unix' => $nextUpdateUnix,
            'threshold_violations' => $thresholdViolations,
            'retryable' => $retryable,
            'log' => implode("\n", $logLines),
        ];
    }

    private static function opensslCliAvailable(): bool
    {
        $output = [];
        $exitCode = 1;
        exec('openssl version 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * @return array{output:string,exit_code:int}
     */
    private static function runCommand(string $command): array
    {
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        return [
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    private static function writeTempPem(string $prefix, string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crl_' . $prefix . '_');
        if ($path === false) {
            throw new RuntimeException('Failed to create temporary PEM file.');
        }

        if (file_put_contents($path, $pem) === false) {
            @unlink($path);
            throw new RuntimeException('Failed to write temporary PEM file.');
        }

        return $path;
    }

    private static function derBytesToPem(string $der, string $sourceLabel = 'certificate'): string
    {
        if ($der === '' || !str_starts_with($der, "\x30")) {
            throw new RuntimeException("Failed to decode DER certificate bytes from {$sourceLabel}.");
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private static function extractOpenSslField(string $output, string $field): string
    {
        $pattern = '/^' . preg_quote($field, '/') . '=(.*)$/mi';
        if (preg_match($pattern, $output, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private static function parseOpenSslDateToUnix(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('M j H:i:s Y \G\M\T', trim($date));
        if ($dt instanceof DateTimeImmutable) {
            return $dt->getTimestamp();
        }

        $dt = DateTimeImmutable::createFromFormat('YmdHis\Z', trim($date));
        if ($dt instanceof DateTimeImmutable) {
            return $dt->getTimestamp();
        }

        $dt = DateTimeImmutable::createFromFormat('YmdHis', trim($date));
        if ($dt instanceof DateTimeImmutable) {
            return $dt->getTimestamp();
        }

        $fallback = strtotime(trim($date));

        return $fallback === false ? null : $fallback;
    }
}
