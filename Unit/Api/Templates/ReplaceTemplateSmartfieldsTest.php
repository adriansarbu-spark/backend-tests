<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::replaceSmartfields()
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

    $this->controller->apiRequest = new class {
        public function __get($name)
        {
            if ($name === 'request') {
                return new class {
                    public function all(): array { return []; }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'application/json'; }
                };
            }
            return null;
        }
        public function getContent(): string { return ''; }
    };
});

test('replaceSmartfields returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    $this->controller->replaceSmartfields('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceSmartfields returns 422 when version is not draft', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
            ];
        }
        public function getVersionByUuid(string $uuid) {
            return [
                'uuid'         => $uuid,
                'template_uuid'=> 't1',
                'status'       => 'published',
            ];
        }
    };

    $this->controller->replaceSmartfields('t1', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceSmartfields succeeds for draft version and returns formatted smartfields', function () {
    // Provide request smartfields payload via apiRequest stub.
    $this->controller->apiRequest = new class {
        public function __get($name)
        {
            if ($name === 'request') {
                return new class {
                    public function all(): array {
                        return [
                            'smartfields' => [
                                [
                                    'field_key' => 'field1',
                                    'label'     => 'Field 1',
                                    'type'      => 'text',
                                    'required'  => true,
                                ],
                            ],
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'application/json'; }
                };
            }
            return null;
        }
        public function getContent(): string { return ''; }
    };

    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
            ];
        }
        public function getVersionByUuid(string $uuid) {
            return [
                'uuid'          => $uuid,
                'template_uuid' => 't1',
                'status'        => 'draft',
            ];
        }
        public function replaceVersionSmartfields(string $versionUuid, array $smartfields) {
            return true;
        }
        public function getVersionSmartfields(string $versionUuid): array {
            return [
                [
                    'field_key' => 'field1',
                    'label'     => 'Field 1',
                    'type'      => 'text',
                    'required'  => 1,
                ],
            ];
        }
    };

    $this->controller->replaceSmartfields('t1', 'v1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    $smartfields = $data['smartfields'] ?? [];
    expect(count($smartfields))->toBe(1);
    expect($smartfields[0]['field_key'] ?? null)->toBe('field1');
    expect($smartfields[0]['required'] ?? null)->toBeTrue();
});


