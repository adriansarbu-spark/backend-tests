<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once DIR_SYSTEM . 'library/checkin.php';
require_once __DIR__ . '/_support/CheckinConsiderCatalogTestDoubles.php';

/**
 * No-codes-on-approved invariant for the expiry-conflict override:
 * when a vendor decline caused solely by an OCR-vs-MRZ Date of expiry
 * conflict is overturned to approved, the outcome must carry NO decline
 * codes on any surface (verification_decline_json null, no
 * platform_check_codes). The same payload with the override disabled must
 * decline WITH the vendor concerns, proving the codes are keyed off the
 * final status rather than the vendor verdict.
 */

/** Config double serving only the keys the checkin pipeline reads. */
final class CheckinOverrideConfigDouble
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}

/** Records the verification data handed to the guest update (CSC flow). */
final class CheckinOverrideModelDouble
{
    public ?array $updatedVerificationData = null;

    public function getVerificationByVerificationId(string $uuid): array
    {
        return ['flow' => 'csc_api_enrollment', 'verification_id' => 'iv-1', 'customer_id' => 0];
    }

    public function updateVerificationGuestByVerificationId(string $uuid, array $data): int
    {
        $this->updatedVerificationData = $data;

        return 1;
    }
}

/** Customer KYC flow: records what updateCustomerDeclined stores on identity. */
final class CheckinCustomerFlowModelDouble
{
    public ?array $updatedVerificationData = null;

    /** @var array{0: int, 1: ?string}|null */
    public ?array $customerDeclinedCall = null;

    public function getVerificationByVerificationId(string $uuid): array
    {
        return ['flow' => 'verification', 'verification_id' => 'iv-2', 'customer_id' => 5];
    }

    public function updateVerificationByVerificationId(string $uuid, int $customer_id, array $data): int
    {
        $this->updatedVerificationData = $data;

        return 1;
    }

    public function updateCustomerDeclined(int $customer_id, ?string $decline_json = null): void
    {
        $this->customerDeclinedCall = [$customer_id, $decline_json];
    }
}

/** No-op model loader so registry-driven model lookups resolve to null. */
final class CheckinOverrideLoaderDouble
{
    public function model(string $route): void
    {
    }
}

function override_checkin_service(bool $overrideEnabled): CheckinService
{
    $registry = new CheckinCatalogRegistryDouble([
        'db' => new CheckinCatalogDbDouble(),
        'load' => new CheckinOverrideLoaderDouble(),
        'config' => new CheckinOverrideConfigDouble([
            'checkin_session_status_approved' => 'approved',
            'checkin_session_status_declined' => 'declined',
            'checkin_expiry_conflict_override' => $overrideEnabled,
            'checkin_base_url' => 'https://simplifi.sb.getid.dev',
        ]),
    ]);

    return new CheckinService($registry);
}

/**
 * Webhook body: vendor decline caused ONLY by a Date of expiry OCR-vs-MRZ
 * conflict on a Romanian ID; every override gate condition holds (single
 * declined row, recognized concern IDs, future dates, MRZ check digit valid).
 */
function expiry_conflict_webhook_body(): array
{
    $approvedRow = function (string $category, string $value): array {
        return [
            'category' => $category,
            'equal' => true,
            'valid' => true,
            'status' => 'approved',
            'message' => 'Value is ok',
            'ocr' => $value,
            'conflicts' => [],
        ];
    };

    // TD1 line 2: expiry 311203 at pos 9-14 with valid ICAO check digit 2.
    $mrzLine2 = '9001011M3112032ROU<<<<<<<<<<<0';

    return [
        'id' => 'app-override-1',
        'metadata' => ['externalId' => 'uuid-override-1'],
        'overallResult' => [
            'status' => 'declined',
            'validationDate' => '2026-07-26T10:00:00Z',
            'concerns' => [
                ['id' => 'DC045', 'service' => 'doc-check', 'status' => 'declined', 'message' => 'Found 1 issue(s)'],
                ['id' => 'DC047', 'service' => 'doc-check', 'status' => 'declined', 'message' => 'Fields from ocr and mrz have conflict: 2031-08-03, 2031-12-03'],
            ],
        ],
        'servicesResults' => [
            'docCheck' => [
                'status' => 'declined',
                'documentDataChecking' => [
                    $approvedRow('Personal number', '1900101400012'),
                    $approvedRow('Document number', 'RX123456'),
                    $approvedRow('First name', 'ION'),
                    $approvedRow('Original First name', 'ION'),
                    $approvedRow('Last name', 'POPESCU'),
                    $approvedRow('Original Last name', 'POPESCU'),
                    $approvedRow('Issue country', 'ROU'),
                    $approvedRow('Nationality code', 'ROU'),
                    $approvedRow('Date of birth', '1990-01-01'),
                    [
                        'category' => 'Date of expiry',
                        'equal' => false,
                        'valid' => false,
                        'status' => 'declined',
                        'message' => 'Fields from ocr and mrz have conflict: 2031-08-03, 2031-12-03',
                        'ocr' => '2031-08-03',
                        'mrz' => '2031-12-03',
                        'conflicts' => ['ocr', 'mrz'],
                    ],
                ],
                'extracted' => [
                    'ocr' => [],
                    'images' => [],
                    'mrz' => [
                        ['category' => 'MRZ full', 'content' => "I<ROURX123456<1900101400012<<<\n" . $mrzLine2 . "\nPOPESCU<<ION<<<<<<<<<<<<<<<<<<"],
                    ],
                ],
            ],
            'livenessCheck' => ['status' => 'approved'],
        ],
    ];
}

test('expiry-conflict override approves with no platform codes and MRZ expiry as valid_until', function () {
    $service = override_checkin_service(true);

    $result = $service->extractVerificationData(expiry_conflict_webhook_body());

    expect($result['status'])->toBe('approved')
        ->and($result['valid'])->toBe(1)
        ->and($result)->not->toHaveKey('platform_check_codes')
        ->and($result['valid_until'])->toBe('2031-12-03');
});

test('processDecision stores no verification_decline_json when the override approves', function () {
    $service = override_checkin_service(true);
    $model = new CheckinOverrideModelDouble();

    $result = $service->processDecision(expiry_conflict_webhook_body(), 'app-override-1', $model, null);

    expect($result['status'])->toBe('approved')
        ->and($result['verification_decline_json'])->toBeNull()
        ->and($model->updatedVerificationData)->not->toBeNull()
        ->and($model->updatedVerificationData['verification_decline_json'])->toBeNull()
        ->and($model->updatedVerificationData)->not->toHaveKey('platform_check_codes');
});

test('a platform-only decline persists platform.* codes in the stored decline JSON', function () {
    // Vendor approved, but the mandatory checks fail (Personal number row
    // missing): the decline must be explainable from the persisted JSON alone,
    // since the user payload and guest status endpoint never see the
    // in-memory platform_check_codes.
    $body = expiry_conflict_webhook_body();
    $body['overallResult'] = ['status' => 'approved', 'validationDate' => '2026-07-26T10:00:00Z', 'concerns' => []];
    $body['servicesResults']['docCheck']['status'] = 'approved';
    $body['servicesResults']['docCheck']['documentDataChecking'] = array_values(array_filter(
        $body['servicesResults']['docCheck']['documentDataChecking'],
        fn (array $row) => !in_array($row['category'], ['Personal number', 'Date of expiry'], true)
    ));

    $service = override_checkin_service(true);
    $model = new CheckinOverrideModelDouble();

    $result = $service->processDecision($body, 'app-platform-1', $model, null);
    $decline = json_decode((string) $result['verification_decline_json'], true);
    $platformConcerns = array_values(array_filter($decline['concerns'], fn (array $c) => ($c['service'] ?? '') === 'platform'));

    expect($result['status'])->toBe('declined')
        ->and(array_column($platformConcerns, 'id'))->toBe(['platform.personal_number_missing'])
        ->and($model->updatedVerificationData['verification_decline_json'])->toBe($result['verification_decline_json']);
});

test('a vendor decline with failed mandatory checks stores both vendor and platform concerns', function () {
    $body = expiry_conflict_webhook_body();
    $body['servicesResults']['docCheck']['documentDataChecking'] = array_values(array_filter(
        $body['servicesResults']['docCheck']['documentDataChecking'],
        fn (array $row) => $row['category'] !== 'Personal number'
    ));

    $service = override_checkin_service(true);
    $model = new CheckinOverrideModelDouble();

    $result = $service->processDecision($body, 'app-mixed-1', $model, null);
    $ids = array_column(json_decode((string) $result['verification_decline_json'], true)['concerns'], 'id');

    expect($result['status'])->toBe('declined')
        ->and($ids)->toContain('DC047')
        ->and($ids)->toContain('platform.personal_number_missing');
});

test('the customer KYC decline stores the merged decline JSON on the identity row', function () {
    // Regression: updateCustomerDeclined used to receive the pre-merge vendor
    // JSON, so the account user API (which reads the identity column) missed
    // platform.* reasons that the decline email showed.
    $body = expiry_conflict_webhook_body();
    $body['servicesResults']['docCheck']['documentDataChecking'] = array_values(array_filter(
        $body['servicesResults']['docCheck']['documentDataChecking'],
        fn (array $row) => $row['category'] !== 'Personal number'
    ));

    $service = override_checkin_service(true);
    $model = new CheckinCustomerFlowModelDouble();

    $result = $service->processDecision($body, 'app-customer-1', $model, null);

    expect($result['status'])->toBe('declined')
        ->and($model->customerDeclinedCall)->not->toBeNull()
        ->and($model->customerDeclinedCall[0])->toBe(5)
        ->and($model->customerDeclinedCall[1])->toBe($result['verification_decline_json'])
        ->and(array_column(json_decode((string) $model->customerDeclinedCall[1], true)['concerns'], 'id'))
            ->toContain('platform.personal_number_missing');
});

test('the same payload declines with the vendor concerns when the override is disabled', function () {
    $service = override_checkin_service(false);
    $model = new CheckinOverrideModelDouble();

    $result = $service->processDecision(expiry_conflict_webhook_body(), 'app-override-1', $model, null);
    $decline = json_decode((string) $result['verification_decline_json'], true);
    $concernIds = array_column($decline['concerns'], 'id');

    expect($result['status'])->toBe('declined')
        ->and($decline['overall_status'])->toBe('declined')
        ->and($concernIds)->toContain('DC047')
        ->and($model->updatedVerificationData['verification_decline_json'])->not->toBeNull();
});
