<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::replaceParties()
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

    // Minimal apiRequest stub – tests override as needed.
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

test('replaceParties returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    $this->controller->replaceParties('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceParties returns 422 when version is not draft', function () {
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
                'status'        => 'published',
            ];
        }
    };

    $this->controller->replaceParties('t1', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceParties returns validation error when parties JSON is invalid', function () {
    $this->controller->apiRequest = new class {
        public function __get($name)
        {
            if ($name === 'request') {
                return new class {
                    public function all(): array {
                        return [
                            'parties' => '{invalid-json}',
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
    };

    $this->controller->replaceParties('t1', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['field'] ?? null)->toBe('parties');
});

test('replaceParties succeeds for draft version and parses parties correctly', function () {
    $jsonParties = json_encode([
        [
            'code'          => 'P1',
            'label'         => 'Party 1',
            'signing_order' => 1,
        ],
    ]);

    $this->controller->apiRequest = new class ($jsonParties) {
        private $jsonParties;
        public function __construct(string $jsonParties) { $this->jsonParties = $jsonParties; }
        public function __get($name)
        {
            if ($name === 'request') {
                $json = $this->jsonParties;
                return new class ($json) {
                    private $json;
                    public function __construct(string $json) { $this->json = $json; }
                    public function all(): array {
                        return [
                            'parties' => $this->json,
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
        public array $seenParties = [];
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
        public function replaceVersionParties(string $versionUuid, array $parties) {
            $this->seenParties = $parties;
            return true;
        }
        public function getVersionParties(string $versionUuid): array {
            return [
                [
                    'code'           => 'P1',
                    'label'          => 'Party 1',
                    'signing_order'  => 1,
                    'parallel_group' => 0,
                ],
            ];
        }
    };

    $this->controller->replaceParties('t1', 'v1');

    expect($this->controller->statusCode)->toBeNull();
    // The controller should have decoded the JSON into an array of parties.
    expect($this->controller->model_esign_tpl_template->seenParties ?? [])->toBeArray();
    expect(($this->controller->model_esign_tpl_template->seenParties[0]['code'] ?? null))->toBe('P1');

    $data = $this->controller->json['data'] ?? [];
    $parties = $data['parties'] ?? [];
    expect(count($parties))->toBe(1);
    expect($parties[0]['code'] ?? null)->toBe('P1');
    expect($parties[0]['signing_order'] ?? null)->toBe(1);
    expect($parties[0]['parallel_group'] ?? null)->toBe(0);
});

