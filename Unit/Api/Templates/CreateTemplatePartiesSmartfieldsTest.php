<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for parties (signers) and smartfields validation on createTemplate().
 *
 * @see ControllerPublicAPIV1EsignTplTemplates::createTemplate — assert code/field only, not message text.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;
    $this->controller->apiRequest = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };

    $this->controller->customer = new class {
        public function getCompanyId() { return 100; }
        public function getRoleId() { return 200; }
    };
});

test('createTemplate returns 422 when parties contain duplicate signer code', function () {
    $this->controller
        ->method('getPost')
        ->willReturn([
            'name' => 'With parties',
            'content' => '<p>x</p>',
            'parties' => [
                ['code' => 'signer1', 'label' => 'First', 'signing_order' => 1],
                ['code' => 'signer1', 'label' => 'Duplicate code', 'signing_order' => 2],
            ],
        ]);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) {
            throw new RuntimeException('createTemplate must not run when parties are invalid');
        }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('parties');
});

test('createTemplate returns 422 when smartfield is missing type', function () {
    $this->controller
        ->method('getPost')
        ->willReturn([
            'name' => 'With smartfields',
            'content' => '<p>x</p>',
            'smartfields' => [
                ['field_key' => 'note', 'label' => 'Note'],
            ],
        ]);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) {
            throw new RuntimeException('createTemplate must not run when smartfields are invalid');
        }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('smartfields');
});
