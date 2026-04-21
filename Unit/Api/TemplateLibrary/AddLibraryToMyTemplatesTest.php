<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::addLibraryToMyTemplates()
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

    $this->controller->customer = new class {
        public function getCompanyId() { return 10; }
        public function getRoleId() { return 20; }
    };

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('addLibraryToMyTemplates returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->addLibraryToMyTemplates('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('addLibraryToMyTemplates creates user template and copies parties and smartfields', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return [
                'uuid'          => $uuid,
                'name'          => 'Lib name',
                'description'   => 'Lib desc',
                'category_code' => 'contracts',
            ];
        }
        public function getLatestPublishedVersion(string $uuid) {
            return [
                'uuid'        => 'ver-1',
                'content'     => 'content',
                'description' => 'Ver desc',
            ];
        }
        public function getVersionParties(string $uuid): array {
            return [
                [
                    'code'           => 'P1',
                    'label'          => 'Party 1',
                    'signing_order'  => 1,
                    'parallel_group' => 0,
                ],
            ];
        }
        public function getVersionSmartfields(string $uuid): array {
            return [
                [
                    'field_key' => 'field1',
                    'label'     => 'Field 1',
                    'type'      => 'text',
                    'required'  => 1,
                    'party_code'=> 'P1',
                ],
            ];
        }
    };

    $this->controller->model_esign_tpl_template = new class {
        public $capturedTemplate;
        public $capturedParties;
        public $capturedSmartfields;
        public function createTemplate(array $data) {
            $this->capturedTemplate = $data;
            return 123;
        }
        public function replaceVersionParties(string $uuid, array $parties) {
            $this->capturedParties = $parties;
            return true;
        }
        public function replaceVersionSmartfields(string $uuid, array $smartfields) {
            $this->capturedSmartfields = $smartfields;
            return true;
        }
    };

    $this->controller->addLibraryToMyTemplates('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();

    // Template fields copied correctly
    $tpl = $this->controller->model_esign_tpl_template->capturedTemplate ?? [];
    expect($tpl['name'] ?? null)->toBe('Lib name');
    expect($tpl['description'] ?? null)->toBe('Ver desc');
    expect($tpl['category_code'] ?? null)->toBe('contracts');
    expect($tpl['visibility'] ?? null)->toBe('private');
    expect($tpl['status'] ?? null)->toBe('draft');

    // Parties copied and normalised
    $parties = $this->controller->model_esign_tpl_template->capturedParties ?? [];
    expect($parties[0]['code'] ?? null)->toBe('P1');
    expect($parties[0]['signing_order'] ?? null)->toBe(1);
    expect($parties[0]['parallel_group'] ?? null)->toBe(0);

    // Smartfields copied
    $smartfields = $this->controller->model_esign_tpl_template->capturedSmartfields ?? [];
    expect($smartfields[0]['field_key'] ?? null)->toBe('field1');
    expect($smartfields[0]['type'] ?? null)->toBe('text');
}
);

