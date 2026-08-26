<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/AuthorDocumentsTestDoubles.php';

/**
 * @param mixed ...$args
 * @return mixed
 */
function author_docs_private_value(object $controller, string $method, ...$args): mixed
{
    $reflection = new ReflectionMethod(ControllerPublicAPIV1EsignAuthorDocuments::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$args);
}

/** @return array<string, mixed> */
function author_docs_test_row(array $overrides = []): array
{
    return array_replace([
        'id' => 42,
        'uuid' => 'author-document-uuid',
        'company_id' => 10,
        'name' => 'Employment agreement',
        'description' => 'Safe description',
        'status' => 'draft',
        'prepaid_invite_default' => 0,
        'content_snapshot' => '<p>Agreement</p>',
        'template_id' => null,
        'template_version' => null,
        'source_type' => 'scratch',
        'date_added' => '2026-08-12 10:00:00',
        'date_modified' => '2026-08-12 10:05:00',
    ], $overrides);
}

/**
 * Prerequisites:
 * - Author-document detail is scoped to the active company.
 *
 * Steps:
 * 1. Request an owned, foreign, and missing UUID.
 * 2. Assert owned response shape and uniform foreign/missing access errors.
 * 3. Assert no foreign party, field, or campaign identifier is returned.
 */
test('Author documents API — detail remains company-scoped', function () {
    $ownedModel = new AuthorDocumentModelStub();
    $ownedModel->documentByUuid = author_docs_test_row();
    $ownedModel->parties = [[
        'id' => 1,
        'role_code' => 'employee',
        'role_label' => 'Employee',
        'name' => 'Alex',
        'email' => 'alex@example.com',
        'signing_order' => 1,
        'parallel_group' => 0,
    ]];
    $ownedModel->fields = [[
        'id' => 2,
        'field_key' => 'employee.position',
        'label' => 'Position',
        'type' => 'text',
        'value' => 'Engineer',
        'required' => 1,
        'assigned_party_role_code' => 'employee',
        'sort_order' => 1,
    ]];
    $owned = author_docs_controller($ownedModel, new AuthorCampaignModelStub());
    $owned->getDocument('author-document-uuid');

    $foreignModel = new AuthorDocumentModelStub();
    $foreignModel->documentByUuid = author_docs_test_row(['company_id' => 99, 'uuid' => 'foreign-secret-uuid']);
    $foreign = author_docs_controller($foreignModel, new AuthorCampaignModelStub());
    $foreign->getDocument('foreign-secret-uuid');

    $missing = author_docs_controller(new AuthorDocumentModelStub(), new AuthorCampaignModelStub());
    $missing->getDocument('missing-uuid');

    expect($owned->json['data']['uuid'])->toBe('author-document-uuid')
        ->and($owned->json['data']['parties'][0]['role_code'])->toBe('employee')
        ->and($owned->json['data']['party_recipients']['employee'][0]['position'])->toBe('Engineer')
        ->and($foreign->statusCode)->toBe(403)
        ->and($foreign->json['error'])->toContain('access_denied')
        ->and($missing->statusCode)->toBe(404)
        ->and($missing->json['error'])->toContain('document_not_found')
        ->and(json_encode($foreign->json))->not->toContain('foreign-secret-uuid');
});

/**
 * Prerequisites:
 * - Collection listing must pass active-company scope and bounded pagination to the model.
 *
 * Steps:
 * 1. List with filters, search, ascending name sort, and an excessive page size.
 * 2. Inspect the exact model scope and normalized response counters.
 * 3. Reject unsupported sort input before any model query.
 */
test('Author documents API — list is company-scoped and validates sorting', function () {
    $model = new AuthorDocumentModelStub();
    $model->listResult = ['documents' => [[
        'uuid' => 'listed-uuid',
        'name' => 'Listed agreement',
        'description' => null,
        'status' => 'sent',
        'source_type' => 'template',
        'template_id' => 5,
        'template_version' => 3,
        'parties_count' => 2,
        'smartfields_count' => 4,
        'documents_sent_count' => 3,
        'documents_completed_count' => 1,
        'date_added' => '2026-08-12 10:00:00',
        'date_modified' => '2026-08-12 10:05:00',
        'date_sent' => '2026-08-12 10:10:00',
    ]], 'total' => 1];
    $controller = author_docs_controller($model, new AuthorCampaignModelStub());
    $controller->request->get = [
        'page' => 2,
        'per_page' => 500,
        'sort' => 'name',
        'order' => 'asc',
        'status' => 'sent',
        'search' => ' Listed ',
        'name' => 'ignored fallback',
    ];
    $controller->listDocuments();

    $invalidModel = new AuthorDocumentModelStub();
    $invalid = author_docs_controller($invalidModel, new AuthorCampaignModelStub());
    $invalid->request->get = ['sort' => 'company_id'];
    $invalid->listDocuments();

    $params = $model->calls[0]['params'];
    expect($params['company_id'])->toBe(10)
        ->and($params['page'])->toBe(2)
        ->and($params['per_page'])->toBe(100)
        ->and($params['sort'])->toBe('name')
        ->and($params['order'])->toBe('ASC')
        ->and($params['search'])->toBe('Listed')
        ->and($params)->not->toHaveKey('name')
        ->and($controller->json['data'][0]['documents_pending_count'])->toBe(2)
        ->and($controller->json['pagination']['total'])->toBe(1)
        ->and($invalid->statusCode)->toBe(422)
        ->and($invalid->json['error'])->toContain('invalid_sort_field')
        ->and($invalidModel->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - Only an owned draft may be soft-deleted by the author-document endpoint.
 *
 * Steps:
 * 1. Delete owned draft, non-draft, foreign, and missing fixtures.
 * 2. Assert one owned model mutation and stable denial codes.
 * 3. Assert foreign and missing UUIDs cause no delete call.
 */
test('Author documents API — delete is company-scoped and draft-only', function () {
    $ownedModel = new AuthorDocumentModelStub();
    $ownedModel->documentByUuid = author_docs_test_row();
    $owned = author_docs_controller($ownedModel, new AuthorCampaignModelStub());
    $owned->deleteDocument('author-document-uuid');

    $sentModel = new AuthorDocumentModelStub();
    $sentModel->documentByUuid = author_docs_test_row(['status' => 'sent']);
    $sentModel->deleteResult = false;
    $sent = author_docs_controller($sentModel, new AuthorCampaignModelStub());
    $sent->deleteDocument('author-document-uuid');

    $foreignModel = new AuthorDocumentModelStub();
    $foreignModel->documentByUuid = author_docs_test_row(['company_id' => 99]);
    $foreign = author_docs_controller($foreignModel, new AuthorCampaignModelStub());
    $foreign->deleteDocument('author-document-uuid');

    expect($ownedModel->calls)->toBe([['method' => 'deleteDocument', 'uuid' => 'author-document-uuid']])
        ->and($owned->json['data']['uuid'])->toBe('author-document-uuid')
        ->and($sent->statusCode)->toBe(422)
        ->and($sent->json['error'])->toContain('document_not_draft')
        ->and($foreign->statusCode)->toBe(403)
        ->and($foreignModel->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - Preview signature blocks may bind only declared role emails and need stable PDF placeholder dimensions.
 *
 * Steps:
 * 1. Transform duplicate, declared, missing-role, and pre-styled blocks.
 * 2. Build annotation metadata from declared parties.
 * 3. Assert HTML escaping, role ordering, and default placeholder dimensions.
 */
test('Author documents API — preview transformation binds only declared party roles', function () {
    $controller = author_docs_controller(new AuthorDocumentModelStub(), new AuthorCampaignModelStub());
    $html = '<div class="signature-block-item" data-signature-party-code="employee"></div>'
        . '<div class="signature-block-item extra" data-signature-party-code="unknown"></div>'
        . '<div class="signature-block-item" style="width:10px" data-signature-party-code="manager"></div>';
    $injected = author_docs_private_value($controller, 'injectSignatureBlockDataAttributes', $html, [
        'employee' => 'safe&quot; onclick=&quot;bad@example.com',
        'manager' => 'manager@example.com',
    ]);
    $sized = author_docs_private_value($controller, 'ensureSignatureBlockPlaceholderSize', $injected);
    $annotations = author_docs_private_value($controller, 'buildSignatureAnnotations', $sized, [
        ['role_code' => 'employee', 'signing_order' => 1],
        ['role_code' => 'manager', 'signing_order' => 2],
    ], [
        'employee' => 'employee@example.com',
        'manager' => 'manager@example.com',
    ]);

    expect($injected)->toContain('data-required="true"')
        ->and($injected)->toContain('data-email="" data-required="true" data-signature-party-code="unknown"')
        ->and($injected)->not->toContain(' onclick="bad@example.com')
        ->and(substr_count($sized, 'min-width:200px'))->toBe(2)
        ->and($annotations)->toHaveCount(3)
        ->and($annotations[0]['email'])->toBe('employee@example.com')
        ->and($annotations[1]['email'])->toBe('')
        ->and($annotations[2]['email'])->toBe('manager@example.com')
        ->and($annotations[0]['width'])->toBe(200)
        ->and($annotations[0]['height'])->toBe(80);
});

/**
 * Prerequisites:
 * - Campaign rows may contain only declared document fields and one value object per role/row.
 *
 * Steps:
 * 1. Convert row payloads containing declared, unknown, and duplicate role data.
 * 2. Normalize the first value per role and resolve short keys to document field keys.
 * 3. Assert unknown field keys and numeric roles are omitted.
 */
test('Author documents API — structured campaign payload normalizes declared roles and fields', function () {
    $model = new AuthorDocumentModelStub();
    $model->documentByUuid = author_docs_test_row();
    $model->fields = [
        ['field_key' => 'employee.position', 'assigned_party_role_code' => 'employee'],
        ['field_key' => 'manager.department', 'assigned_party_role_code' => 'manager'],
    ];
    $controller = author_docs_controller($model, new AuthorCampaignModelStub());
    $converted = author_docs_private_value($controller, 'convertRowsToStructuredCampaignPayload', 'author-document-uuid', [
        [
            'party_values' => ['employee' => ['email' => 'first@example.com'], 0 => ['email' => 'numeric@example.com']],
            'field_values' => ['employee.position' => 'Engineer', 'unknown.secret' => 'drop-me'],
        ],
        [
            'party_values' => ['employee' => ['email' => 'second@example.com']],
            'field_values' => ['employee.position' => 'Manager'],
        ],
    ]);
    $party = author_docs_private_value($controller, 'normalizePartyValuesToSinglePerRole', $converted['party_values']);
    $fields = author_docs_private_value($controller, 'resolveStructuredFieldValuesToDocumentKeys', 42, $converted['field_values']);

    expect($converted['field_values']['employee'][0])->toBe(['position' => 'Engineer'])
        ->and(json_encode($converted))->not->toContain('drop-me')
        ->and($party['employee'])->toBe(['email' => 'first@example.com'])
        ->and($party)->not->toHaveKey(0)
        ->and($fields)->toBe(['employee.position' => 'Engineer']);
});

/**
 * Prerequisites:
 * - Chromium preview rendering is an external boundary and may be unavailable.
 *
 * Steps:
 * 1. Invoke the renderer adapter with a guaranteed-missing local endpoint.
 * 2. Suppress only the expected transport warning.
 * 3. Assert a stable 502 code and no partial PDF/annotation payload.
 */
test('Author documents API — preview provider failure returns a structured late error', function () {
    $controller = author_docs_controller(new AuthorDocumentModelStub(), new AuthorCampaignModelStub());
    set_error_handler(static fn (int $severity, string $message): bool => str_contains($message, 'file_get_contents'));
    try {
        $result = author_docs_private_value(
            $controller,
            'callChromiumPdfService',
            ['base_url' => 'file:///definitely/missing', 'render_path' => '/render'],
            '<html><body>Safe</body></html>',
            '',
            '',
        );
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeFalse()
        ->and($controller->statusCode)->toBe(502)
        ->and($controller->json['error'])->toContain('pdf_service_unavailable')
        ->and($controller->json)->not->toHaveKey('data');
});
