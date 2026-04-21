<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::getLibrary()
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('getLibrary returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->getLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
}
);

test('getLibrary returns library with parties and smartfields and can_archive for simplifi.ro email', function () {
    $this->controller->customer = new class {
        public function getEmail(): string { return 'user@simplifi.ro'; }
    };

    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return [
                'uuid' => $uuid,
                'name' => 'Test library',
                'description' => 'Desc',
                'content' => 'body',
                'category_code' => 'general',
                'language_id' => 1,
                'status' => 'published',
                'version' => 2,
                'date_added' => '2024-01-01 10:00:00',
                'date_modified' => '2024-01-02 10:00:00',
            ];
        }
        public function getVersionParties(string $uuid): array {
            return [
                ['code' => 'A', 'label' => 'Party A', 'signing_order' => 1, 'parallel_group' => 0],
            ];
        }
        public function getVersionSmartfields(string $uuid): array {
            return [
                ['field_key' => 'f1', 'label' => 'Field 1', 'type' => 'text', 'required' => 1, 'party_code' => 'A'],
            ];
        }
    };

    $this->controller->getLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('lib-uuid');
    expect($data['status'] ?? null)->toBe('published');
    expect($data['version'] ?? null)->toBe(2);
    expect($data['can_archive'] ?? false)->toBeTrue();
    expect($data['parties'] ?? [])->toHaveCount(1);
    expect($data['smartfields'] ?? [])->toHaveCount(1);
});

