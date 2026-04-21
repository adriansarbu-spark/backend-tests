<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::replaceLibraryParties()
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

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('replaceLibraryParties returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->replaceLibraryParties('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceLibraryParties returns 404 when version is not found or mismatched group', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'group-1'];
            }
            // missing version / different group
            return null;
        }
    };

    $this->controller->replaceLibraryParties('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceLibraryParties returns 422 when version is not draft', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'group-1'];
            }
            return ['uuid' => $uuid, 'library_group_uuid' => 'group-1', 'status' => 'published'];
        }
    };

    $this->controller->replaceLibraryParties('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('replaceLibraryParties validates parties and returns updated parties on success', function () {
    $this->controller->apiRequest = new class {
        public function __get($name)
        {
            if ($name === 'request') {
                return new class {
                    public function all(): array {
                        return [
                            'parties' => [
                                ['code' => 'A', 'label' => 'Party A', 'signing_order' => 1],
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

    $this->controller->model_esign_tpl_library = new class {
        public array $seenParties = [];
        public function getLibraryByUuid(string $uuid) {
            return ['uuid' => $uuid, 'library_group_uuid' => 'group-1', 'status' => 'draft'];
        }
        public function replaceVersionParties(string $uuid, array $parties): void {
            $this->seenParties = $parties;
        }
        public function getVersionParties(string $uuid): array {
            return [
                ['code' => 'A', 'label' => 'Party A', 'signing_order' => 1, 'parallel_group' => 0],
            ];
        }
    };

    $this->controller->replaceLibraryParties('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    $parties = $data['parties'] ?? [];
    expect($parties)->toHaveCount(1);
    expect($parties[0]['code'] ?? null)->toBe('A');
    expect($parties[0]['signing_order'] ?? null)->toBe(1);
});

