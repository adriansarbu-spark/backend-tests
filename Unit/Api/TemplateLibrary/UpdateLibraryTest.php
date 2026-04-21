<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::updateLibrary()
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('updateLibrary returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->updateLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
}
);

test('updateLibrary returns 422 when library is not draft', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return ['uuid' => $uuid, 'status' => 'published'];
        }
    };

    $this->controller->updateLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('updateLibrary validates category_code and returns 422 on invalid value', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return ['uuid' => $uuid, 'status' => 'draft'];
        }
        public function updateLibrary(string $uuid, array $data): bool { return true; }
        public function replaceVersionParties(string $uuid, array $parties): void {}
        public function replaceVersionSmartfields(string $uuid, array $smartfields): void {}
    };

    $this->controller
        ->method('getPost')
        ->willReturn([
            'category_code' => 'invalid-code',
        ]);

    $this->controller->updateLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('category_code');
});

test('updateLibrary updates fields and parties/smartfields on happy path', function () {
    $seen = new class {
        public array $updatedData = [];
        public array $parties = [];
        public array $smartfields = [];
    };

    $this->controller->model_esign_tpl_library = new class ($seen) {
        private $seen;
        public function __construct($seen) { $this->seen = $seen; }
        public function getLibraryByUuid(string $uuid) {
            return [
                'uuid' => $uuid,
                'status' => 'draft',
                'category_code' => 'general',
                'language_id' => 1,
            ];
        }
        public function updateLibrary(string $uuid, array $data): bool {
            $this->seen->updatedData = $data;
            return true;
        }
        public function replaceVersionParties(string $uuid, array $parties): void {
            $this->seen->parties = $parties;
        }
        public function replaceVersionSmartfields(string $uuid, array $smartfields): void {
            $this->seen->smartfields = $smartfields;
        }
    };

    $this->controller
        ->method('getPost')
        ->willReturn([
            'name' => 'Updated name',
            'description' => 'New desc',
            'content' => 'body',
            'category_code' => 'contracts',
            'language_id' => 2,
            'parties' => [
                ['code' => 'A', 'label' => 'Party A', 'signing_order' => 1],
            ],
            'smartfields' => [
                ['field_key' => 'f1', 'label' => 'Field 1', 'type' => 'text'],
            ],
        ]);

    $this->controller->updateLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data'] ?? null)->toBeArray();
    expect($seen->updatedData['name'] ?? null)->toBe('Updated name');
    expect($seen->updatedData['category_code'] ?? null)->toBe('contracts');
    expect($seen->updatedData['language_id'] ?? null)->toBe(2);
    expect($seen->parties)->toHaveCount(1);
    expect($seen->smartfields)->toHaveCount(1);
});

