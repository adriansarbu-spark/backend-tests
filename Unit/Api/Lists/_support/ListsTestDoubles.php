<?php

declare(strict_types=1);

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

require_once PUBLIC_API . 'lists.php';

if (! class_exists(ListsIndexPermissionController::class, false)) {
    /**
     * Harness for {@see ControllerPublicAPIV1Lists} index permission gate.
     */
    final class ListsIndexPermissionController extends ControllerPublicAPIV1Lists
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public function checkPlugin(): void
        {
            ++$this->checkPluginCalls;
        }

        /** @return null */
        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }
    }
}

if (! class_exists(ListsCustomerStub::class, false)) {
    final class ListsCustomerStub
    {
        public function __construct(
            private readonly int $customerId = 7,
            private readonly int $companyId = 10,
        ) {
        }

        public function getId(): int
        {
            return $this->customerId;
        }

        public function getCompanyId(): int
        {
            return $this->companyId;
        }
    }
}

if (! class_exists(ListsLanguageStub::class, false)) {
    final class ListsLanguageStub
    {
        public function get($key): string
        {
            return (string) $key;
        }
    }
}

if (! class_exists(ListsPageListModelStub::class, false)) {
    /**
     * Records list metadata and content fetches so deny paths can prove IDOR.
     */
    final class ListsPageListModelStub
    {
        /** @var list<mixed> */
        public array $getListCalls = [];

        /** @var list<array<string, mixed>> */
        public array $getListContentCalls = [];

        /** @var array<string, mixed>|false */
        public $listInfo = [
            'form_id' => 1,
            'category_id' => 5,
            'return_type' => 0,
            'button' => '[]',
            'button_description' => '[]',
        ];

        /** @var list<array<int|string, mixed>> */
        public array $listContent = [
            [10 => 'Alpha'],
        ];

        /** @return array<string, mixed>|false */
        public function getList($list_id)
        {
            $this->getListCalls[] = $list_id;

            return $this->listInfo;
        }

        /**
         * @param mixed $company_id
         * @param mixed $datalabels
         * @param mixed $language_id
         * @param mixed $parameters
         * @param mixed $report_to_run_id
         * @param mixed $breakdown_datalabels
         * @param mixed $report_filters
         * @param mixed $group_by_fields
         * @return list<array<int|string, mixed>>
         */
        public function getListContent(
            $company_id,
            $datalabels,
            $language_id,
            $parameters,
            $report_to_run_id,
            $breakdown_datalabels,
            $report_filters,
            $group_by_fields,
        ): array {
            $this->getListContentCalls[] = [
                'company_id' => $company_id,
                'datalabels' => $datalabels,
                'language_id' => $language_id,
                'parameters' => $parameters,
                'report_to_run_id' => $report_to_run_id,
                'breakdown_datalabels' => $breakdown_datalabels,
                'report_filters' => $report_filters,
                'group_by_fields' => $group_by_fields,
            ];

            return $this->listContent;
        }
    }
}

if (! class_exists(ListsSettingModuleModelStub::class, false)) {
    final class ListsSettingModuleModelStub
    {
        /** @var list<mixed> */
        public array $getModuleCalls = [];

        /** @var array<string, mixed>|false */
        public $module = [
            'field' => [
                [
                    'name' => [1 => 'Label'],
                    'placeholder' => [1 => ''],
                    'html_name' => 'label',
                    'type' => 'text',
                    'sort_order' => 1,
                    'display_field' => 1,
                    'datalabel_id' => 10,
                ],
            ],
        ];

        /** @return array<string, mixed>|false */
        public function getModule($module_id)
        {
            $this->getModuleCalls[] = $module_id;

            return $this->module;
        }
    }
}

if (! class_exists(ListsDatalabelModelStub::class, false)) {
    final class ListsDatalabelModelStub
    {
        /** @var list<mixed> */
        public array $getDataLabelsByIdsCalls = [];

        /** @var list<array<string, mixed>> */
        public array $datalabels = [
            [
                'datalabel_id' => 10,
                'table_key' => 'items',
                'field' => 'name',
                'operation_type' => 'none',
                'aggregator_columns' => '',
            ],
        ];

        /**
         * @param mixed $ids
         * @return list<array<string, mixed>>
         */
        public function getDataLabelsByIds($ids): array
        {
            $this->getDataLabelsByIdsCalls[] = $ids;

            return $this->datalabels;
        }
    }
}

if (! class_exists(ListsCategoryModelStub::class, false)) {
    final class ListsCategoryModelStub
    {
        /** @var list<mixed> */
        public array $getBreadCrumbsCalls = [];

        /** @var list<array<string, mixed>> */
        public array $breadcrumbs = [];

        /**
         * @param mixed $category_id
         * @return list<array<string, mixed>>
         */
        public function getBreadCrumbs($category_id): array
        {
            $this->getBreadCrumbsCalls[] = $category_id;

            return $this->breadcrumbs;
        }
    }
}

if (! class_exists(ListsLoadStub::class, false)) {
    final class ListsLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        /** @var list<string> */
        public array $loadedLanguages = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly ListsPageListModelStub $pageList,
            private readonly ListsSettingModuleModelStub $settingModule,
            private readonly ListsDatalabelModelStub $datalabel,
            private readonly ListsCategoryModelStub $category,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;

            if ($route === 'page/list') {
                $this->registry->set('model_page_list', $this->pageList);
            } elseif ($route === 'setting/module') {
                $this->registry->set('model_setting_module', $this->settingModule);
            } elseif ($route === 'task/datalabel') {
                $this->registry->set('model_task_datalabel', $this->datalabel);
            } elseif ($route === 'catalog/category') {
                $this->registry->set('model_catalog_category', $this->category);
            }
        }

        public function language(string $route): void
        {
            $this->loadedLanguages[] = $route;
        }
    }
}

if (! function_exists('lists_index_permission_harness')) {
    /**
     * @param list<string> $permissionGet
     * @param array<string, mixed> $query
     * @return array{
     *     0: ListsIndexPermissionController,
     *     1: ListsLoadStub,
     *     2: ListsPageListModelStub,
     *     3: ListsSettingModuleModelStub,
     *     4: ListsDatalabelModelStub
     * }
     */
    function lists_index_permission_harness(
        array $permissionGet = [],
        array $query = ['list_id' => '42'],
        int $companyId = 10,
    ): array {
        $registry = new Registry();
        $pageList = new ListsPageListModelStub();
        $settingModule = new ListsSettingModuleModelStub();
        $datalabel = new ListsDatalabelModelStub();
        $category = new ListsCategoryModelStub();
        $load = new ListsLoadStub($registry, $pageList, $settingModule, $datalabel, $category);

        $registry->set('load', $load);
        $registry->set('customer', new ListsCustomerStub(7, $companyId));
        $registry->set('language', new ListsLanguageStub());
        $registry->set('config', new class {
            public function get(string $key): mixed
            {
                return $key === 'config_language_id' ? 1 : null;
            }
        });
        $registry->set('request', (object) [
            'get' => $query,
            'server' => [],
        ]);

        $controller = new ListsIndexPermissionController($registry);
        $controller->permission = (object) [
            'get' => $permissionGet,
            'post' => [],
        ];

        return [$controller, $load, $pageList, $settingModule, $datalabel];
    }
}

if (! function_exists('lists_index_assert_denied_hidden')) {
    function lists_index_assert_denied_hidden(
        ListsIndexPermissionController $controller,
        ListsLoadStub $load,
        ListsPageListModelStub $pageList,
    ): void {
        expect($controller->checkPluginCalls)->toBe(1)
            ->and($controller->statusCode)->toBe(404)
            ->and($controller->json['error'])->not->toBeEmpty()
            ->and($controller->json['error'])->toContain('access_denied')
            ->and($controller->json['data'])->toBeNull()
            ->and($controller->sendResponseCalls)->toBe(1)
            ->and($load->loadedModels)->toBe([])
            ->and($pageList->getListCalls)->toBe([])
            ->and($pageList->getListContentCalls)->toBe([]);
    }
}
