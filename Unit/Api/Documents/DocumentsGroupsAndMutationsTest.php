<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if ($this->hadRequestMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - The owner has a draft whose document group is already locked.
 * - The owner has normal mutation permissions.
 *
 * Steps:
 * 1. Attempt signer replacement, annotation replacement, or draft metadata update through index routing.
 * 2. Assert the stable document_group_locked lifecycle error.
 * 3. Assert no document or signer write occurs.
 */
test('Documents API — group locks block draft mutations', function (string $method, ?string $action) {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $documentModel->document = documents_test_document(['document_group_uuid' => 'locked-group']);
    $documentModel->groupLocked = true;
    $_SERVER['REQUEST_METHOD'] = $method;
    $controller->request->get = array_filter([
        'uuid'   => 'document-owner-uuid',
        'action' => $action,
    ], static fn ($value): bool => $value !== null);

    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toContain('document_group_locked')
        ->and($documentModel->writes)->toBe([])
        ->and($signerModel->writes)->toBe([]);
})->with([
    'replace signers'       => ['POST', 'signers'],
    'replace annotations'   => ['POST', 'annotations'],
    'update draft metadata' => ['PUT', null],
]);

/**
 * Prerequisites:
 * - CC recipients are notification-only addresses and do not create document access.
 *
 * Steps:
 * 1. Normalize string and array inputs with mixed case, whitespace, empties, and duplicates.
 * 2. Reject malformed entries, too many recipients, and payloads longer than storage permits.
 * 3. Split the stored value back into the stable response array.
 */
test('Documents API — CC recipients normalize within count and storage limits', function () {
    [$controller] = documents_test_controller();
    $parse = new ReflectionMethod(ControllerPublicAPIV1Documents::class, 'parseCcNotificationEmails');
    $split = new ReflectionMethod(ControllerPublicAPIV1Documents::class, 'splitCcNotificationEmails');

    $normalizedString = $parse->invoke($controller, ' First@Example.COM ; second@example.com;first@example.com;; ');
    $normalizedArray = $parse->invoke($controller, ['Alpha@Example.com', ' beta@example.com ', 'ALPHA@example.com']);
    $tooMany = array_map(static fn (int $i): string => "recipient{$i}@example.com", range(1, 11));
    $tooLong = array_map(
        static fn (int $i): string => 'recipient' . $i . '@'
            . implode('.', array_fill(0, 3, str_repeat(chr(97 + $i), 50)))
            . '.example.com',
        range(0, 9),
    );

    expect($normalizedString)->toBe('first@example.com;second@example.com')
        ->and($normalizedArray)->toBe('alpha@example.com;beta@example.com')
        ->and($parse->invoke($controller, 'valid@example.com;not-an-email'))->toBeFalse()
        ->and($parse->invoke($controller, $tooMany))->toBeFalse()
        ->and($parse->invoke($controller, $tooLong))->toBeFalse()
        ->and($parse->invoke($controller, ' ; '))->toBeNull()
        ->and($split->invoke($controller, $normalizedString))->toBe(['first@example.com', 'second@example.com']);
});

/**
 * Prerequisites:
 * - A document belongs to a two-member pack.
 *
 * Steps:
 * 1. Attempt the single-document send route.
 * 2. Assert callers are directed to the atomic group-send route.
 * 3. Assert no document or signer write occurs before group preflight.
 */
test('Documents API — multi-member pack cannot use single-document send', function () {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $documentModel->document = documents_test_document(['document_group_uuid' => 'pack-group']);
    $documentModel->groupMemberCount = 2;
    $signerModel->signers = documents_test_signers();
    $controller->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'send'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toContain('use_document_group_send')
        ->and($documentModel->writes)->toBe([])
        ->and($signerModel->writes)->toBe([]);
});

/**
 * Prerequisites:
 * - An owned company pack contains two documents and mixed signer secrets.
 *
 * Steps:
 * 1. Open one member by UUID as the pack owner.
 * 2. Build the full group response from both members.
 * 3. Assert company/current metadata and hide every non-owner signer code.
 */
test('Documents API — grouped company detail exposes only owner-bound signing secrets', function () {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $first = documents_test_document(['document_group_uuid' => 'pack-group']);
    $second = documents_test_document([
        'document_id' => 72,
        'uuid' => 'document-sibling-uuid',
        'name' => 'Sibling agreement',
        'document_group_uuid' => 'pack-group',
        'origin_csc_api_client_id' => 900,
    ]);
    $documentModel->document = $first;
    $documentModel->personalOwned = false;
    $documentModel->groupMembers = [
        ['document_id' => 71, 'document_group_sent_at' => null],
        ['document_id' => 72, 'document_group_sent_at' => null],
    ];
    $documentModel->documentsById = [71 => $first, 72 => $second];
    $signerModel->signers = documents_test_signers();
    $controller->request->get = ['uuid' => 'document-owner-uuid'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $controller->index();

    $encoded = json_encode($controller->json['data']);
    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['member_count'])->toBe(2)
        ->and($controller->json['data']['documents'][0]['ownership'])->toBe('company')
        ->and($controller->json['data']['documents'][0]['is_current'])->toBeTrue()
        ->and($controller->json['data']['documents'][1]['is_current'])->toBeFalse()
        ->and($encoded)->not->toContain('invitee-sign-code')
        ->and($encoded)->not->toContain('integrator.example.test');
});
