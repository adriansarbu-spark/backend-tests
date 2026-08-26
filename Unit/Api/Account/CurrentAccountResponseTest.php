<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once DIR_SYSTEM . 'library/config.php';
require_once PUBLIC_API . 'account.php';

final class CurrentAccountCustomerStub
{
    public function __construct(
        private readonly int $customerId = 61,
        private readonly string $email = 'Current.User@Example.com',
        private readonly int $companyId = 0,
        private readonly ?int $duplicateOfCustomerId = 62,
    ) {
    }

    public function getId(): int
    {
        return $this->customerId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getUserTmpUuid(): string
    {
        return 'safe-temporary-user-uuid';
    }

    public function getDuplicateOfCustomerId(): ?int
    {
        return $this->duplicateOfCustomerId;
    }
}

final class CurrentAccountCustomerModelStub
{
    /** @var array<string, mixed>|null */
    public ?array $customer = null;

    /** @var array<string, mixed> */
    public array $deletionEligibility = [
        'allowed' => false,
        'reason' => 'last_admin_cannot_delete_account',
        'blocking_companies' => [['uuid' => 'company-safe-uuid', 'name' => 'Safe Company']],
    ];

    /** @var list<int> */
    public array $getCustomerCalls = [];

    /** @var list<array{0: int, 1: string}> */
    public array $getCustomerEmailCalls = [];

    /** @var list<array{0: int, 1: int}> */
    public array $eligibilityCalls = [];

    public function __construct()
    {
        $this->customer = [
            'customer_id' => 61,
            'customer_group_id' => 4,
            'store_id' => 0,
            'language_id' => 2,
            'cart' => 'serialized-cart',
            'wishlist' => 'serialized-wishlist',
            'address_id' => 8,
            'safe' => 'internal-safe-flag',
            'signup_role' => 3,
            'existing_account_phone' => 'internal-phone',
            'existing_account_email' => 'internal-email@example.com',
            'existing_account_mail' => 'internal-mail',
            'duplicate_of_customer_id' => 62,
            'deleted' => 0,
            'date_deleted' => null,
            'alias_account' => 0,
            'image' => 'internal-image-path',
            'profile_avatar_token' => 'avatar-secret-token',
            'verification_id' => 'provider-verification-id',
            'verification_decline_json' => json_encode([
                'overall_status' => 'declined',
                'concerns' => [['id' => 'vendor.fraud', 'message' => 'raw vendor prose']],
            ]),
            'verification_status' => 'pending',
            'password' => 'password-hash',
            'salt' => 'password-salt',
            'token' => 'legacy-token',
            'code' => 'legacy-code',
            'totp_secret' => 'totp-secret',
            'cert_requested' => 1,
            'custom_field' => json_encode(['department' => 'Operations']),
            'firstname' => 'Current',
            'lastname' => 'User',
            'nationality' => 'RO',
            'id_number' => 'public-account-identity-value',
        ];
    }

    /** @return array<string, mixed> */
    public function loginCustomerById(int $customerId): array
    {
        return ['email' => 'fallback@example.com'];
    }

    /** @return array<string, mixed>|null */
    public function getCustomer(int $customerId): ?array
    {
        $this->getCustomerCalls[] = $customerId;

        return $this->customer;
    }

    /** @return array<string, mixed> */
    public function getCustomerEmail(int $customerId, string $email): array
    {
        $this->getCustomerEmailCalls[] = [$customerId, $email];

        return ['email_verified' => 1];
    }

    /** @return list<array{email: string}> */
    public function getCustomerEmails(int $customerId): array
    {
        return [
            ['email' => 'former.primary@example.com'],
            ['email' => 'former.secondary@example.com'],
        ];
    }

    /** @return array<string, mixed> */
    public function getAccountDeletionEligibility(int $customerId, int $companyId): array
    {
        $this->eligibilityCalls[] = [$customerId, $companyId];

        return $this->deletionEligibility;
    }
}

final class CurrentAccountCustomFieldModelStub
{
    /** @return list<array<string, mixed>> */
    public function getCustomFields(int $customerGroupId): array
    {
        return [
            ['location' => 'account', 'custom_field_id' => 1, 'name' => 'Department'],
            ['location' => 'address', 'custom_field_id' => 2, 'name' => 'Door'],
        ];
    }
}

final class CurrentAccountRewardModelStub
{
    /** @return list<array<string, mixed>> */
    public function getRewards(array $filter): array
    {
        return [];
    }
}

final class CurrentAccountValidationModelStub
{
    public function hasSuccessTotp(int $customerId): bool
    {
        return true;
    }
}

final class CurrentAccountCertificateModelStub
{
    public function userHasValidCertificate(int $customerId): bool
    {
        return true;
    }
}

final class CurrentAccountExportModelStub
{
    /** @return list<array<string, mixed>> */
    public function getActiveByCustomerId(int $customerId): array
    {
        return [
            [
                'export_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'created_at' => '2026-08-12 09:00:00',
                'expires_at' => '2026-08-19 09:00:00',
                'file_size_bytes' => '4096',
                'storage_path' => '/private/data/export.zip',
            ],
            ['export_uuid' => '', 'storage_path' => '/must/not/appear.zip'],
        ];
    }
}

final class CurrentAccountLanguageModelStub
{
    /** @return array<string, mixed> */
    public function getLanguage(int $languageId): array
    {
        return ['language_id' => $languageId, 'code' => 'RO-ro'];
    }
}

final class CurrentAccountLegalDocumentModelStub
{
    /** @return list<array<string, mixed>> */
    public function getActiveForFlow(string $flow, int $languageId): array
    {
        return [];
    }
}

final class CurrentAccountLanguageStub
{
    /** @var list<string> */
    public array $loaded = [];

    public function load(string $route): void
    {
        $this->loaded[] = $route;
    }

    public function get(string $key): string
    {
        return $key === 'date_format_short' ? 'Y-m-d' : '';
    }
}

final class CurrentAccountLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    /** @param array<string, object> $models */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $models,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if (isset($this->models[$route])) {
            $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
        }
    }
}

final class TestableControllerPublicAPIV1CurrentAccount extends ControllerPublicAPIV1Account
{
    public int $checkPluginCalls = 0;

    public int $sendResponseCalls = 0;

    public function checkPlugin(): void
    {
        ++$this->checkPluginCalls;
    }

    /** @return null */
    public function sendResponse()
    {
        ++$this->sendResponseCalls;

        return null;
    }
}

/**
 * @return array{
 *     0: TestableControllerPublicAPIV1CurrentAccount,
 *     1: CurrentAccountLoadStub,
 *     2: CurrentAccountCustomerModelStub
 * }
 */
function current_account_controller(
    int $customerId = 61,
    string $email = 'Current.User@Example.com',
): array {
    $registry = new Registry();
    $config = new Config();
    $config->set('config_customer_group_id', 4);
    $config->set('config_language', 'en-gb');
    $config->set('config_language_id', 1);
    $config->set('checkin_session_status_declined', 'declined');
    $config->set('ejbca_admin_dual_certificate_mode', 'best_effort');

    $customerModel = new CurrentAccountCustomerModelStub();
    $models = [
        'account/customer' => $customerModel,
        'account/custom_field' => new CurrentAccountCustomFieldModelStub(),
        'account/reward' => new CurrentAccountRewardModelStub(),
        'tool/validation' => new CurrentAccountValidationModelStub(),
        'certificate/certificate' => new CurrentAccountCertificateModelStub(),
        'account/customer_data_export' => new CurrentAccountExportModelStub(),
        'localisation/language' => new CurrentAccountLanguageModelStub(),
        'legal/document' => new CurrentAccountLegalDocumentModelStub(),
    ];
    $load = new CurrentAccountLoadStub($registry, $models);

    $registry->set('config', $config);
    $registry->set('load', $load);
    $registry->set('language', new CurrentAccountLanguageStub());
    $registry->set('customer', new CurrentAccountCustomerStub($customerId, $email));
    $registry->set('request', (object) ['get' => [], 'server' => []]);

    $controller = new TestableControllerPublicAPIV1CurrentAccount($registry);
    $controller->opencartVersion = 4000;
    $controller->useremail = 'sso.user@example.com';

    return [$controller, $load, $customerModel];
}

beforeEach(function () {
    $this->currentAccountHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->currentAccountSavedMethod = $this->currentAccountHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

afterEach(function () {
    if ($this->currentAccountHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->currentAccountSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - The session does not resolve to a current customer ID.
 *
 * Steps:
 * 1. Request the current-account response.
 * 2. Assert the endpoint returns no account data and stops before dependent privacy models are loaded.
 */
test('Account API — current-account response hides a missing session customer', function () {
    [$controller, $load, $customerModel] = current_account_controller(customerId: 0, email: '');

    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['data'])->toBeNull()
        ->and($customerModel->getCustomerCalls)->toBe([])
        ->and($load->loadedModels)->toBe(['account/customer'])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Customer 61 is authenticated but its account row is deleted.
 *
 * Steps:
 * 1. Request the current-account response.
 * 2. Assert no deleted profile or dependent account metadata is exposed.
 */
test('Account API — current-account response hides deleted account rows', function () {
    [$controller, $load, $customerModel] = current_account_controller();
    $customerModel->customer['deleted'] = 1;

    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['data'])->toBeNull()
        ->and($customerModel->getCustomerCalls)->toBe([61])
        ->and($load->loadedModels)->toBe(['account/customer']);
});

/**
 * Prerequisites:
 * - Customer 61 has duplicate-account metadata, an avatar, an active export, a certificate, and a pending verification row.
 *
 * Steps:
 * 1. Request the current-account response through the account controller.
 * 2. Assert safe derived fields, export metadata, language, and deletion eligibility are present.
 * 3. Assert internal IDs, credentials, raw decline JSON/prose, storage paths, and source profile tokens are absent.
 */
test('Account API — current-account response exposes derived data but removes internal and secret fields', function () {
    [$controller, , $customerModel] = current_account_controller();

    $controller->index();

    $data = $controller->json['data'];
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
    $internalKeys = [
        'customer_id',
        'customer_group_id',
        'store_id',
        'language_id',
        'cart',
        'wishlist',
        'address_id',
        'safe',
        'signup_role',
        'existing_account_phone',
        'existing_account_email',
        'existing_account_mail',
        'duplicate_of_customer_id',
        'deleted',
        'date_deleted',
        'alias_account',
        'image',
        'profile_avatar_token',
        'verification_id',
        'verification_decline_json',
        'password',
        'salt',
        'token',
        'code',
        'totp_secret',
        'cert_requested',
        'custom_field',
    ];

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['success'])->toBe(1)
        ->and($customerModel->getCustomerEmailCalls)->toBe([[61, 'Current.User@Example.com']])
        ->and($customerModel->eligibilityCalls)->toBe([[61, 0]])
        ->and($data)->not->toHaveKeys($internalKeys)
        ->and($data['email'])->toBe('Current.User@Example.com')
        ->and($data['email_verified'])->toBeTrue()
        ->and($data['totp_secret_set'])->toBeTrue()
        ->and($data['has_certificate'])->toBeTrue()
        ->and($data['verification_decline'])->toBeNull()
        ->and($data['is_duplicate'])->toBeTrue()
        ->and($data['duplicate_account_emails'])->toHaveCount(2)
        ->and($data['picture_url'])->toContain('/v1/account/profile-avatar/')
        ->and($data['citizenship'])->toBe('RO')
        ->and($data['sso_email'])->toBe('sso.user@example.com')
        ->and($data['authEndpoint'])->toBe('keycloak')
        ->and($data['dual_cert_mode'])->toBe('best_effort')
        ->and($data['language_code'])->toBe('ro-ro')
        ->and($data['data_exports'])->toHaveCount(1)
        ->and($data['data_exports'][0]['export_uuid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and($data['data_exports'][0])->not->toHaveKey('storage_path')
        ->and($data['account_deletion_allowed'])->toBeFalse()
        ->and($data['account_deletion_blocked_reason'])->toBe('last_admin_cannot_delete_account')
        ->and($encoded)->not->toContain('raw vendor prose')
        ->and($encoded)->not->toContain('/private/data/export.zip')
        ->and($encoded)->not->toContain('/must/not/appear.zip')
        ->and($encoded)->not->toContain('password-hash')
        ->and($encoded)->not->toContain('totp-secret')
        ->and($encoded)->not->toContain('legacy-token');
});
