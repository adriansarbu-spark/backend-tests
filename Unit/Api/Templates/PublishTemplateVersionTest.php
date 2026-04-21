<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::publishVersion()
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

test('publishVersion returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    $this->controller->publishVersion('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishVersion publishes draft version and returns payload', function () {
    $this->controller->model_esign_tpl_template = new class {
        private $versions = [];

        public function __construct()
        {
            $this->versions['v1'] = [
                'uuid'           => 'v1',
                'template_uuid'  => 't1',
                'version_number' => 1,
                'status'         => 'draft',
                'published_at'   => null,
            ];
        }

        public function getTemplateByUuid(string $uuid)
        {
            // Minimal template row; ownership already enforced via customer role in tests.
            if ($uuid === 't1') {
                return [
                    'uuid'                        => 't1',
                    'created_by_customer_role_id' => 20,
                ];
            }
            return null;
        }

        public function getVersionByUuid(string $uuid)
        {
            return $this->versions[$uuid] ?? null;
        }

        public function publishVersion(string $uuid)
        {
            if (!isset($this->versions[$uuid])) {
                return false;
            }
            $this->versions[$uuid]['status'] = 'published';
            $this->versions[$uuid]['published_at'] = '2024-01-01 10:00:00';
            return true;
        }
    };

    $this->controller->publishVersion('t1', 'v1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('v1');
    expect($data['template_uuid'] ?? null)->toBe('t1');
    expect($data['version_number'] ?? null)->toBeInt();
    expect($data['status'] ?? null)->toBe('published');
}
);

