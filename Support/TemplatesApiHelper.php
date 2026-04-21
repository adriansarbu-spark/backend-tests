<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';

final class TemplatesApiHelper
{
    /**
     * Base URL for templates API used in integration tests.
     *
     * Endpoint prefix: /publicapi/v1/esign/tpl/templates
     */
    public static function apiBase(): string
    {
        return API_URL . 'esign/tpl/templates';
    }

    /**
     * Base URL for template library API (browse, add-to-my-templates, etc.).
     *
     * Endpoint prefix: /publicapi/v1/esign/tpl/library
     */
    public static function libraryApiBase(): string
    {
        return API_URL . 'esign/tpl/library';
    }

    /**
     * Minimal config validation for templates integration flows.
     *
     * Mirrors the documents/signing helpers: skip when required config is missing
     * to avoid false negatives on misconfigured environments.
     */
    public static function assertRequiredConfigOrSkip(): void
    {
        $required = [
            'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
            'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
            'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
            'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
            'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
            'TEST_USER_2_EMAIL' => defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : '',
            'TEST_USER_2_PASSWORD' => defined('TEST_USER_2_PASSWORD') ? TEST_USER_2_PASSWORD : '',
        ];

        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }
    }

    /**
     * Low-level helper to call POST /templates with an arbitrary payload.
     *
     * Returns: [$status, $jsonOrNull, $rawBody]
     */
    public static function rawCreateTemplate(
        string $userBearer,
        array $payload,
        ?string $apiBase = null
    ): array {
        $apiBase = $apiBase ?? self::apiBase();

        return ApiAuthHelper::apiRequest('POST', $apiBase, $userBearer, [
            'json' => $payload,
        ]);
    }

    /**
     * Happy-path helper for creating a template for flow tests.
     *
     * - Adds a unique name suffix.
     * - Asserts success (200 + data.uuid present).
     *
     * Returns: [$apiBase, $uuid]
     */
    public static function createTemplateForFlow(
        string $userBearer,
        array $overrides = [],
        ?string $apiBase = null
    ): array {
        $apiBase = $apiBase ?? self::apiBase();

        $name = $overrides['name'] ?? ('Templates flow ' . gmdate('YmdHis'));

        $payload = array_merge([
            'name' => $name,
            'content' => '<p>Template body</p>',
        ], $overrides);

        [$status, $json, $raw] = self::rawCreateTemplate($userBearer, $payload, $apiBase);

        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr((string)$raw, 0, 1200);

        expect($status, "Create template failed.\n{$debug}")->toBe(200);
        expect(is_array($json), "Create template did not return JSON.\n{$debug}")->toBeTrue();

        $uuid = (string)($json['data']['uuid'] ?? '');
        expect($uuid, "Create template response missing data.uuid.\n{$debug}")->not->toBe('');

        return [$apiBase, $uuid];
    }

    /**
     * Low-level PUT /templates/{uuid} with JSON body.
     *
     * Returns: [$status, $jsonOrNull, $rawBody]
     */
    public static function rawUpdateTemplate(
        string $userBearer,
        string $templateUuid,
        array $payload,
        ?string $apiBase = null
    ): array {
        $apiBase = $apiBase ?? self::apiBase();
        $url = $apiBase . '/' . rawurlencode($templateUuid);

        return ApiAuthHelper::apiRequest('PUT', $url, $userBearer, [
            'json' => $payload,
        ]);
    }

    /**
     * PUT /templates/{uuid} expecting 200 (draft owner update).
     *
     * Returns: [$status, $json, $raw] after assertions
     */
    public static function updateTemplateForFlow(
        string $userBearer,
        string $templateUuid,
        array $payload,
        ?string $apiBase = null
    ): array {
        $apiBase = $apiBase ?? self::apiBase();

        [$status, $json, $raw] = self::rawUpdateTemplate($userBearer, $templateUuid, $payload, $apiBase);

        $jsonText = is_array($json)
            ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : 'null';
        $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr((string)$raw, 0, 1200);

        expect($status, "Update template failed.\n{$debug}")->toBe(200);
        expect(is_array($json), "Update template did not return JSON.\n{$debug}")->toBeTrue();

        return [$status, $json, $raw];
    }

    /**
     * URL for operations that need uuid + version_uuid + optional action in the query string.
     *
     * Nested paths like /templates/{t}/versions/{v}/publish are not fully normalized into
     * request parameters by the front controller; query params match the intended rewrite rules.
     */
    public static function urlTemplateQuery(string $apiBase, string $templateUuid, array $query = []): string
    {
        $query = array_merge(['uuid' => $templateUuid], $query);

        return $apiBase . '?' . http_build_query($query);
    }

    /**
     * Library API URL with uuid / version_uuid / action in query (matches rewrite-style params).
     */
    public static function urlLibraryQuery(string $libraryBase, string $libraryUuid, array $query = []): string
    {
        $query = array_merge(['uuid' => $libraryUuid], $query);

        return $libraryBase . '?' . http_build_query($query);
    }
}

