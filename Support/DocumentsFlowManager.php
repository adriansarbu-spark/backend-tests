<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/DocumentsApiHelper.php';

final class DocumentsFlowManager
{
    private string $user1Bearer = '';
    private string $user2Bearer = '';
    private string $uuid = '';
    private string $apiBase;
    private bool $initialized = false;

    public function __construct(
        private readonly string $user1Email,
        private readonly string $user1Password,
        private readonly string $user2Email,
        private readonly string $user2Password,
        ?string $apiBase = null
    ) {
        $this->apiBase = $apiBase ?? (API_URL . 'documents');
    }

    /**
     * Factory that reads credentials from `tests_config.php`.
     *
     * Skips when required config is missing to prevent false negatives.
     */
    public static function forConfiguredTestUsers(): self
    {
        self::assertRequiredConfigOrSkip();

        return new self(
            TEST_USER_1_EMAIL,
            TEST_USER_1_PASSWORD,
            TEST_USER_2_EMAIL,
            TEST_USER_2_PASSWORD
        );
    }

    /**
     * Initialize the flow exactly once:
     * - acquire bearer tokens for user1/user2
     * - create a document owned by user1
     *
     * This is intentionally stateful so multiple tests can share one created UUID.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->user1Bearer = ApiAuthHelper::bearerTokenFor($this->user1Email, $this->user1Password);
        $this->user2Bearer = ApiAuthHelper::bearerTokenFor($this->user2Email, $this->user2Password);
        [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($this->user1Bearer, $this->apiBase);

        $this->apiBase = $apiBase;
        $this->uuid = $uuid;
        $this->initialized = true;
    }

    /**
     * API base used for document requests (usually API_URL . 'documents').
     */
    public function getApiBase(): string
    {
        $this->ensureInitialized();
        return $this->apiBase;
    }

    /**
     * UUID of the document created during initialization (owner-only identifier).
     */
    public function getUuid(): string
    {
        $this->ensureInitialized();
        return $this->uuid;
    }

    /**
     * Bearer token for TEST_USER_1 (document owner).
     */
    public function getUser1Bearer(): string
    {
        $this->ensureInitialized();
        return $this->user1Bearer;
    }

    /**
     * Bearer token for TEST_USER_2 (non-owner).
     */
    public function getUser2Bearer(): string
    {
        $this->ensureInitialized();
        return $this->user2Bearer;
    }

    /**
     * Internal safety check so tests don't accidentally use an uninitialized manager.
     */
    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            throw new LogicException('DocumentsFlowManager must be initialized before usage.');
        }
    }

    /**
     * Minimal config validation for documents integration flows.
     */
    private static function assertRequiredConfigOrSkip(): void
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
}
