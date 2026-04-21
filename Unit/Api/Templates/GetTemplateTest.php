<?php

declare(strict_types=1);

// Load test config and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::getTemplate()
 *
 * Cover not-found, ownership and basic response shaping.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->customer = new class {
        public function getRoleId() { return 20; }
    };

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('getTemplate returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    $this->controller->getTemplate('missing-uuid');

    expect($this->controller->statusCode)->toBe(404);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors)->not->toBe([]);
});

test('getTemplate returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'name'                        => 'Other',
                'created_by_customer_role_id' => 999,
                'content'                     => 'body',
                'visibility'                  => 'private',
                'status'                      => 'draft',
                'version'                     => 1,
                'category_code'               => 'general',
                'date_added'                  => '2024-01-01 10:00:00',
            ];
        }
    };

    $this->controller->getTemplate('uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors)->not->toBe([]);
});

test('getTemplate returns template with parties and smartfields for owner', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'name'                        => 'My template',
                'description'                 => 'Desc',
                'content'                     => 'body',
                'category_code'               => 'contracts',
                'visibility'                  => 'private',
                'status'                      => 'published',
                'version'                     => 2,
                'date_added'                  => '2024-01-01 10:00:00',
                'date_modified'               => '2024-01-02 11:00:00',
                'created_by_customer_role_id' => 20,
            ];
        }
        public function getVersionParties(string $uuid): array {
            return [[
                'code'           => 'P1',
                'label'          => 'Party 1',
                'signing_order'  => 1,
                'parallel_group' => 0,
            ]];
        }
        public function getVersionSmartfields(string $uuid): array {
            return [[
                'field_key'     => 'field1',
                'label'         => 'Field 1',
                'type'          => 'text',
                'required'      => 1,
                'default_value' => 'x',
                'party_code'    => 'P1',
            ]];
        }
    };

    $this->controller->getTemplate('uuid-1');

    expect($this->controller->statusCode)->toBeNull();

    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('uuid-1');
    expect($data['name'] ?? null)->toBe('My template');
    expect($data['content'] ?? null)->toBe('body');

    $parties = $data['parties'] ?? [];
    expect(count($parties))->toBe(1);
    expect($parties[0]['code'] ?? null)->toBe('P1');

    $smartfields = $data['smartfields'] ?? [];
    expect(count($smartfields))->toBe(1);
    expect($smartfields[0]['field_key'] ?? null)->toBe('field1');
});

