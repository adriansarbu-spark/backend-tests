<?php

declare(strict_types=1);

/**
 * Integration/feature test (real HTTP call).
 *
 * What this test covers from `tests/strategies/signing-testing-strategy.md` (194–198):
 * - Create document + signer for user1@example.com
 * - Authenticate as user2@example.com
 * - GET /api/signing/{sign_code} -> 403, no document details leaked
 *
 * IMPORTANT NOTE (manual setup required):
 * This codebase currently doesn't include a public API endpoint to create a signing document+signer
 * for tests, and there is no DB test harness in `tests/Feature/` yet.
 *
 * So this test runs against the real dev API and expects you to provide:
 * - An auth token for `user2@example.com`
 *
 * How to run:
 *   SIGNING_TEST_USER2_BEARER_TOKEN="<jwt or access token>" \
 *   ./vendor/bin/pest tests/Feature/Api/Signing/GetSignerInfoTest.php
 *
 * If you don't provide env vars, the test will be skipped (so CI won't fail by default).
 */

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/SigningFixtures.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping integration test', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}


$controller = SigningFixtures::bootstrapController();



/**
 * Minimal HTTP helper (Guzzle) returning [statusCode, decodedJson|null, rawBody].
 */
function http_get_json(string $url, array $headers = []): array
{
    $client = new \GuzzleHttp\Client([
        // Use dev API certs/settings; disable throwing on 4xx/5xx so we can assert status codes.
        'http_errors' => false,
        'timeout'     => 20,
    ]);

    $headerMap = ['Accept' => 'application/json'];
    foreach ($headers as $headerLine) {
        // Allow caller to pass header lines like "Authorization: Bearer ...".
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $headerMap[trim($parts[0])] = trim($parts[1]);
        }
    }

    $resp = $client->request('GET', $url, [
        'headers' => $headerMap,
    ]);

    $status = (int)$resp->getStatusCode();
    $body = (string)$resp->getBody();

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = null;
    }

    return [$status, $decoded, $body];
}

test('GET /api/signing/{sign_code} is forbidden for non-signer (no leakage)', function () use ($controller) {
    $user2Token = defined('SIGNING_TEST_USER2_BEARER_TOKEN')
        ? (string)SIGNING_TEST_USER2_BEARER_TOKEN
        : ((string)(getenv('SIGNING_TEST_USER2_BEARER_TOKEN') ?: ''));

    if ($user2Token === '') {
        $this->markTestSkipped(
            "Set SIGNING_TEST_USER2_BEARER_TOKEN to run this integration test."
        );
    }

    $fixture = SigningFixtures::createDocumentWithSigner($controller, [
        'owner_customer_role_id' => 1170,
        'signer_email' => 'alexandru.zamfir+test@simplifi.ro',
        'name_tag' => '_testing_feature_signing_forbidden',
    ]);

    $signCode = (string)$fixture['sign_code'];

    // The public API is served under the same host as HTTP_SERVER, with /api/ prefix.
    // Example: https://api01.dev.simplifi.ro/api/signing/<sign_code>
    $url = rtrim(HTTP_SERVER, '/') . '/api/signing/' . rawurlencode($signCode);

    $authorization = preg_match('/^Bearer\s+/i', $user2Token)
        ? $user2Token
        : 'Bearer ' . $user2Token;

    [$status, $json, $raw] = http_get_json($url, [
        // Auth token for user2@example.com.
        // If your auth expects a different header, adjust here.
        'Authorization: ' . $authorization,
        'x-backend-authenticator: keycloak'
    ]);


    
    expect($status)->toBe(403);

    // We intentionally keep assertions broad because the API may return either:
    // - { success: 0, error: [...], data: null }
    // - or another 403-shaped error payload
    if (is_array($json)) {
        // Must not leak signer/document details on forbidden access.
        // If data exists, it should be null/empty and must not contain obvious document fields.
        $data = $json['data'] ?? null;
        expect($data)->not->toBeArray();
    } else {
        // If response isn't JSON for some reason, still ensure we didn't accidentally dump content.
        expect((string)$raw)->not->toContain('document_uuid');
        expect((string)$raw)->not->toContain('signature_request_annotations');
    }
});

