<?php

declare(strict_types=1);

final class SigningFlowHelper
{
    /**
     * Guardrail for integration tests.
     *
     * Skips the current test run if required credentials/fixtures are missing,
     * to avoid false negatives caused by environment misconfiguration.
     */
    public static function assertRequiredConfigOrSkip(): void
    {
        $required = [
            'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
            'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
            'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
            'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
            'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
            'TEST_USER_1_TOTP_SECRET' => defined('TEST_USER_1_TOTP_SECRET') ? TEST_USER_1_TOTP_SECRET : '',
            'TEST_USER_2_EMAIL' => defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : '',
            'TEST_USER_2_PASSWORD' => defined('TEST_USER_2_PASSWORD') ? TEST_USER_2_PASSWORD : '',
            'TEST_USER_2_TOTP_SECRET' => defined('TEST_USER_2_TOTP_SECRET') ? TEST_USER_2_TOTP_SECRET : '',
            'TEST_SIGING_FILE' => defined('TEST_SIGING_FILE') ? TEST_SIGING_FILE : '',
            'TEST_SIGNATURE_DATA_URL' => defined('TEST_SIGNATURE_DATA_URL') ? TEST_SIGNATURE_DATA_URL : '',
        ];

        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }

        if (!is_file(TEST_SIGING_FILE) || !is_readable(TEST_SIGING_FILE)) {
            test()->markTestSkipped('Missing or unreadable signing fixture PDF: ' . (string)TEST_SIGING_FILE);
        }

        if ((string)file_get_contents(TEST_SIGING_FILE) === '') {
            test()->markTestSkipped('Signing fixture PDF is empty: ' . (string)TEST_SIGING_FILE);
        }
    }

    public static function fixturePdfContent(): string
    {
        return (string)file_get_contents(TEST_SIGING_FILE);
    }

    /**
     * Convenience setup used by multiple integration sanity tests:
     * user1 creates a document, assigns user2 as the (only) signer, and sends it.
     *
     * Returns: [$uuid, $documentName, $user2SignCode]
     */
    public static function sentDocFromUser1ToUser2(): array
    {
        $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
        $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

        $documentName = 'sign-flow-sanity-user2-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $uuid = self::createDocument(
            $user1Bearer,
            $documentName,
            self::fixturePdfContent()
        );

        self::setAnnotations($user1Bearer, $uuid, [[
            'x' => 200,
            'y' => 40,
            'page' => 1,
            'type' => 'SIGNATURE',
            'email' => TEST_USER_2_EMAIL,
            'width' => 200,
            'height' => 80,
            'required' => true,
        ]]);
        self::setSigners($user1Bearer, $uuid, [[
            'customer_role_id' => null,
            'email' => TEST_USER_2_EMAIL,
            'signing_order' => 1,
            'signing_type' => 'SIGNATURE',
            'send_email' => false,
        ]]);

        self::sendDocument($user1Bearer, $uuid);

        $user2SignCode = self::waitForSignCodeForDocumentName($user2Bearer, $documentName);
        self::waitForSignerFileOk($user2Bearer, $user2SignCode);

        return [$uuid, $documentName, $user2SignCode];
    }

    /**
     * Create a new DRAFT document owned by the authenticated user.
     *
     * Endpoint: POST /publicapi/v1/documents (multipart upload)
     * Returns the document UUID (owner-only identifier).
     */
    public static function createDocument(string $bearer, string $documentName, string $pdfContent): string
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest('POST', API_URL . 'documents', $bearer, [
            'multipart' => [
                ['name' => 'name', 'contents' => $documentName],
                ['name' => 'signature_level', 'contents' => 'QUALIFIED'],
                [
                    'name' => 'file',
                    'contents' => $pdfContent,
                    'filename' => $documentName,
                    'headers' => ['Content-Type' => 'application/pdf'],
                ],
            ],
        ]);

        expect($status, 'Create document failed: ' . substr($raw, 0, 700))->toBe(200);
        expect(is_array($json), 'Create document returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();
        $uuid = (string)($json['data']['uuid'] ?? '');
        expect($uuid, 'Create document missing data.uuid: ' . substr($raw, 0, 700))->not->toBe('');
        return $uuid;
    }

    /**
     * Replace the document's signature annotations (only allowed while in DRAFT).
     *
     * Endpoint: POST /publicapi/v1/documents/{uuid}/annotations
     * Note: /send requires at least one SIGNATURE annotation.
     */
    public static function setAnnotations(string $bearer, string $uuid, array $annotations = []): void
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'documents/' . rawurlencode($uuid) . '/annotations',
            $bearer,
            ['form_params' => ['annotations' => json_encode($annotations)]]
        );

        expect($status, 'Set annotations failed: ' . substr($raw, 0, 700))->toBe(200);
        expect(is_array($json), 'Set annotations returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();
    }

    /**
     * Replace the document's signer list (only allowed while in DRAFT).
     *
     * Endpoint: POST /publicapi/v1/documents/{uuid}/signers
     */
    public static function setSigners(string $bearer, string $uuid, array $signers): void
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'documents/' . rawurlencode($uuid) . '/signers',
            $bearer,
            ['form_params' => ['signers' => json_encode($signers)]]
        );

        expect($status, 'Set signers failed: ' . substr($raw, 0, 700))->toBe(200);
        expect(is_array($json), 'Set signers returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();
    }

    /**
     * Fetch a sign_code for a given signer email from the OWNER view.
     *
     * Endpoint: GET /publicapi/v1/documents/{uuid}
     * Note: In this API, the owner typically only sees their own sign_code (if any),
     * so this helper is mostly useful when the owner is also a signer.
     */
    public static function getSignCodeForEmail(string $bearer, string $uuid, string $email): string
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest(
            'GET',
            API_URL . 'documents/' . rawurlencode($uuid),
            $bearer
        );

        expect($status, 'Get document failed: ' . substr($raw, 0, 700))->toBe(200);
        expect(is_array($json), 'Get document returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();

        foreach ((array)($json['data']['signers'] ?? []) as $signer) {
            if (strtolower(trim((string)($signer['email'] ?? ''))) === strtolower(trim($email))) {
                $signCode = (string)($signer['sign_code'] ?? '');
                if ($signCode !== '') {
                    return $signCode;
                }
            }
        }

        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        throw new RuntimeException(
            'sign_code not found for email ' . $email . ".\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 2000)
        );
        return '';
    }

    /**
     * Transition the document from DRAFT to PENDING (invites first signing group).
     *
     * Endpoint: POST /publicapi/v1/documents/{uuid}/send
     */
    public static function sendDocument(string $bearer, string $uuid): void
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'documents/' . rawurlencode($uuid) . '/send',
            $bearer
        );

        if ($status !== 200) {
            $jsonText = is_array($json)
                ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : 'null';
            throw new RuntimeException(
                "Send document failed.\nStatus={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 2000)
            );
        }

        expect(is_array($json), 'Send document returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();
    }

    /**
     * Read a sign_code from the SIGNER's document list by document name.
     *
     * Endpoint: GET /publicapi/v1/documents
     * Rationale: UUID endpoints are owner-only; signers discover the sign_code via listing.
     */
    public static function getSignCodeForDocumentName(string $bearer, string $documentName): string
    {
        [$status, $json, $raw] = ApiAuthHelper::apiRequest(
            'GET',
            API_URL . 'documents',
            $bearer
        );

        expect($status, 'List documents failed: ' . substr($raw, 0, 700))->toBe(200);
        expect(is_array($json), 'List documents returned non-JSON: ' . substr($raw, 0, 700))->toBeTrue();

        foreach ((array)($json['data'] ?? []) as $doc) {
            if (trim((string)($doc['name'] ?? '')) === trim($documentName)) {
                $signCode = (string)($doc['sign_code'] ?? '');
                if ($signCode !== '') {
                    return $signCode;
                }
            }
        }

        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        throw new RuntimeException(
            'sign_code not found for document name ' . $documentName . ".\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 2000)
        );
    }

    /**
     * Poll for sign_code to appear in the signer's document list.
     *
     * This smooths out eventual consistency between /send and the signer's visibility/listing.
     */
    public static function waitForSignCodeForDocumentName(
        string $bearer,
        string $documentName,
        int $attempts = 12,
        int $sleepMs = 500
    ): string {
        $lastError = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return self::getSignCodeForDocumentName($bearer, $documentName);
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep($sleepMs * 1000);
            }
        }

        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }
        throw new RuntimeException('sign_code wait failed without exception');
    }

    /**
     * Fetch signer metadata for a sign_code (signer-only view).
     *
     * Endpoint: GET /publicapi/v1/signing/{sign_code}
     */
    public static function getSignerInfo(string $bearer, string $signCode): array
    {
        return ApiAuthHelper::apiRequest(
            'GET',
            API_URL . 'signing/' . rawurlencode($signCode),
            $bearer
        );
    }

    /**
     * Download the PDF as seen by the signer (signer-only view).
     *
     * Endpoint: GET /publicapi/v1/signing/{sign_code}/file
     * Note: some lifecycle transitions (cancel, etc.) may block this endpoint.
     */
    public static function getSignerFile(string $bearer, string $signCode): array
    {
        return ApiAuthHelper::apiRequest(
            'GET',
            API_URL . 'signing/' . rawurlencode($signCode) . '/file',
            $bearer,
            ['headers' => ['Accept' => 'application/pdf']]
        );
    }

    /**
     * Poll until signer metadata becomes accessible (HTTP 200).
     *
     * Useful right after /send, when the signer may not be fully invited yet.
     */
    public static function waitForSignerInfoOk(
        string $bearer,
        string $signCode,
        int $attempts = 12,
        int $sleepMs = 500
    ): array {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            $last = self::getSignerInfo($bearer, $signCode);
            [$status] = $last;
            if ((int)$status === 200) {
                return $last;
            }
            usleep($sleepMs * 1000);
        }

        [$status, $json, $raw] = $last ?? [0, null, ''];
        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        throw new RuntimeException(
            "Signer info never became available.\nStatus={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr((string)$raw, 0, 2000)
        );
    }

    /**
     * Poll until signer PDF becomes accessible and looks like a PDF (%PDF header).
     *
     * This is a pragmatic readiness check used before attempting sign/reject.
     */
    public static function waitForSignerFileOk(
        string $bearer,
        string $signCode,
        int $attempts = 3,
        int $sleepMs = 500
    ): array {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            $last = self::getSignerFile($bearer, $signCode);
            [$status, , $raw] = $last;
            if ((int)$status === 200 && str_starts_with((string)$raw, '%PDF')) {
                return $last;
            }
            usleep($sleepMs * 1000);
        }

        [$status, $json, $raw] = $last ?? [0, null, ''];
        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        throw new RuntimeException(
            "Signer file never became available.\nStatus={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr((string)$raw, 0, 700)
        );
    }

    /**
     * Attempt signing multiple times to reduce flakiness from transient backend states.
     *
     * Endpoint: POST /publicapi/v1/signing/{sign_code}/sign
     */
    public static function signWithRetry(
        string $bearer,
        string $signCode,
        string $totpSecret,
        int $attempts = 6,
        int $sleepMs = 500
    ): array {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            $last = self::sign($bearer, $signCode, $totpSecret);
            [$status] = $last;
            if ((int)$status === 200) {
                return $last;
            }
            usleep($sleepMs * 1000);
        }
        return $last ?? [0, null, ''];
    }

    /**
     * Reject a signing request as the signer (requires TOTP).
     *
     * Endpoint: POST /publicapi/v1/signing/{sign_code}/reject
     */
    public static function reject(string $bearer, string $signCode, string $totpSecret): array
    {
        return ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'signing/' . rawurlencode($signCode) . '/reject',
            $bearer,
            [
                'form_params' => [
                    'totp' => ApiAuthHelper::getOtpFromTotpSecret($totpSecret),
                ],
            ]
        );
    }

    /**
     * Cancel a document as the owner (requires TOTP).
     *
     * Endpoint: POST /publicapi/v1/documents/{uuid}/cancel
     */
    public static function cancelDocument(string $bearer, string $uuid, string $totpSecret): array
    {
        return ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'documents/' . rawurlencode($uuid) . '/cancel',
            $bearer,
            [
                'form_params' => [
                    'totp' => ApiAuthHelper::getOtpFromTotpSecret($totpSecret),
                ],
            ]
        );
    }

    /**
     * Sign a document as the signer (requires TOTP + signature payload).
     *
     * Endpoint: POST /publicapi/v1/signing/{sign_code}/sign
     */
    public static function sign(string $bearer, string $signCode, string $totpSecret): array
    {
        $payload = [[
            'page' => 1,
            'x' => 200,
            'y' => 40,
            'width' => 200,
            'height' => 80,
            'data' => TEST_SIGNATURE_DATA_URL,
            'timestamp' => gmdate('c'),
        ]];

        return ApiAuthHelper::apiRequest(
            'POST',
            API_URL . 'signing/' . rawurlencode($signCode) . '/sign',
            $bearer,
            [
                'form_params' => [
                    'totp' => ApiAuthHelper::getOtpFromTotpSecret($totpSecret),
                    'signature_payload' => json_encode($payload),
                ],
            ]
        );
    }
}

