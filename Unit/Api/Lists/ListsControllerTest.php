<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'lists.php';

final class TestableControllerPublicAPIV1Lists extends ControllerPublicAPIV1Lists
{
    public function checkPlugin(): void
    {
    }

    public function sendResponse()
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function exposedFilterParameters(): array
    {
        return $this->getFilterParameters();
    }

    /** @param list<array<string, mixed>> $content */
    public function exposedTableData(array $content): array
    {
        return $this->getTableData($content);
    }

    /** @param array<int|string, array<string, mixed>> $fieldInfo */
    public function exposedTableHead(array $fieldInfo): array
    {
        return $this->getTableHead($fieldInfo);
    }

    public function exposedTranspose(array $fieldInfo, array $tableHead, array $tableData): array
    {
        return $this->getTableHeadWithTranspose($fieldInfo, $tableHead, $tableData);
    }

    public function exposedPrepareOutput(array $listContent, array $fieldInfo): array
    {
        return $this->prepareOutput($listContent, $fieldInfo);
    }

    public function exposedFilterLanguage(array $settingInfo, int $languageId): array
    {
        return $this->filterLanguage($settingInfo, $languageId);
    }

    public function exposedCustomStyle(array $fieldInfo, int $languageId): array
    {
        return $this->getCustomStyle($fieldInfo, $languageId);
    }
}

/** @param array<string, mixed> $query */
function lists_controller(array $query = []): TestableControllerPublicAPIV1Lists
{
    $registry = new Registry();
    $registry->set('request', (object) ['get' => $query, 'server' => []]);

    return new TestableControllerPublicAPIV1Lists($registry);
}

/**
 * Prerequisites:
 * - A list request contains reserved routing fields plus scalar and nested filters.
 *
 * Steps:
 * 1. Normalize the query through the real filter parser.
 * 2. Assert reserved parameters are removed and nested values keep deterministic keys.
 * 3. Assert language/report inputs remain ordinary explicit filters when not reserved.
 */
test('Lists API — filter input normalizes nested parameters deterministically', function () {
    $controller = lists_controller([
        'route' => 'publicapi/v1/lists',
        'list_id' => '42',
        'company_id' => '20',
        'status' => 'active',
        'sort' => ['field' => 'created_at', 'order' => 'DESC'],
        'range' => [
            'first' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
        ],
        'language' => 'ro-ro',
    ]);

    expect($controller->exposedFilterParameters())->toBe([
        'status' => 'active',
        'sort_field' => 'created_at',
        'sort_order' => 'DESC',
        'range_from' => ['2026-01-01'],
        'range_to' => ['2026-01-31'],
        'language' => 'ro-ro',
    ]);
});

/**
 * Prerequisites:
 * - Report rows include resource IDs, normal display fields, empty/zero values, and transposed fields.
 *
 * Steps:
 * 1. Transform raw list content into ordered field rows and table data.
 * 2. Build and transpose the table header.
 * 3. Assert field/value alignment and deterministic header replacement.
 */
test('Lists API — table and transpose output preserve field value alignment', function () {
    $controller = lists_controller();
    $fieldInfo = [
        1 => [
            'html_name' => 'resource_uuid',
            'name' => 'Identifier',
            'sort_order' => 1,
            'display_field' => 0,
            'resource_id_field' => 1,
            'backend_resource_value_field' => 0,
        ],
        2 => [
            'html_name' => 'label',
            'name' => 'Label',
            'sort_order' => 2,
            'display_field' => 1,
            'resource_id_field' => 0,
            'backend_resource_value_field' => 0,
        ],
        3 => [
            'html_name' => 'amount',
            'name' => 'Amount',
            'sort_order' => 3,
            'display_field' => 1,
            'resource_id_field' => 0,
            'backend_resource_value_field' => 0,
        ],
    ];
    $prepared = $controller->exposedPrepareOutput([
        [1 => 'uuid-1', 2 => 'Alpha', 3 => '0'],
        [1 => 'uuid-2', 2 => '', 3 => '25'],
        [1 => 'uuid-3', 2 => 'Beta', '{{3}}January' => '10'],
    ], $fieldInfo);
    $tableData = $controller->exposedTableData($prepared);
    $head = $controller->exposedTableHead($fieldInfo);
    $transposed = $controller->exposedTranspose($fieldInfo, $head, $tableData);

    expect($tableData[0])->toBe(['id' => 'uuid-1', 'label' => 'Alpha', 'amount' => '0'])
        ->and($tableData[1])->toBe(['id' => 'uuid-2', 'label' => '', 'amount' => '25'])
        ->and($tableData[2])->toBe(['id' => 'uuid-3', 'label' => 'Beta', '{{3}}January' => '10'])
        ->and(array_column($transposed, 'id'))->toBe(['label', '{{3}}January'])
        ->and($transposed[1]['label'])->toBe('January');
});

/**
 * Prerequisites:
 * - List buttons have mixed sort values and optional translated descriptions.
 *
 * Steps:
 * 1. Merge button descriptions into the action definitions.
 * 2. Sort numeric priorities before unspecified priorities.
 * 3. Assert absent descriptions remain null without changing action fields.
 */
test('Lists API — button descriptions and sorting remain aligned', function () {
    $controller = lists_controller();
    $buttons = $controller->getButtons([
        ['action' => 'late'],
        ['action' => 'first', 'sort_order' => '1'],
        ['action' => 'second', 'sort_order' => 2],
    ], [
        ['name' => 'Late', 'modal_text' => 'L'],
        ['name' => 'First', 'modal_text' => 'F'],
    ]);

    expect(array_column($buttons, 'action'))->toBe(['first', 'second', 'late'])
        ->and($buttons[0])->toMatchArray(['name' => 'First', 'modal_text' => 'F'])
        ->and($buttons[1]['name'])->toBeNull()
        ->and($buttons[2])->toMatchArray(['name' => 'Late', 'modal_text' => 'L']);
});

/**
 * Prerequisites:
 * - Form settings and field styles contain two language variants.
 *
 * Steps:
 * 1. Filter the settings to one language and normalize legacy misspelled message keys.
 * 2. Transform conditional style values by field HTML name.
 * 3. Assert only the selected localized values are returned.
 */
test('Lists API — language and style transformations select the requested locale', function () {
    $controller = lists_controller();
    $localized = static fn (string $en, string $ro): array => [1 => $en, 2 => $ro];
    $settings = [
        'admin_email' => $localized('Admin EN', 'Admin RO'),
        'customer_email' => $localized('Customer EN', 'Customer RO'),
        'field' => [[
            'name' => $localized('Status', 'Stare'),
            'placeholder' => $localized('Choose', 'Alege'),
            'option' => [[
                'option_value_description' => $localized('Active', 'Activ'),
            ]],
        ]],
        'form_description' => $localized('Description', 'Descriere'),
        'form_main_action' => $localized('Save', 'Salvează'),
        'form_title' => $localized('Title', 'Titlu'),
        'messege_email_validation' => $localized('Email', 'Email RO'),
        'messege_file_size' => $localized('Size', 'Mărime'),
        'messege_file_type' => $localized('Type', 'Tip'),
        'messege_required' => $localized('Required', 'Obligatoriu'),
        'response_messege' => $localized('Done', 'Gata'),
    ];
    $filtered = $controller->exposedFilterLanguage($settings, 2);
    $styles = $controller->exposedCustomStyle([[
        'html_name' => 'status',
        'style' => [[
            'style_value_description' => $localized('Active', 'Activ'),
            'color' => 'green',
            'icon' => 'check',
            'hide_text' => true,
        ]],
    ]], 2);

    expect($filtered['field'][0]['name'])->toBe('Stare')
        ->and($filtered['field'][0]['option'][0]['option_value_description'])->toBe('Activ')
        ->and($filtered['message_required'])->toBe('Obligatoriu')
        ->and($filtered)->not->toHaveKey('messege_required')
        ->and($styles)->toBe([
            'status' => [
                'Activ' => ['color' => 'green', 'icon' => 'check', 'hide_text' => true],
            ],
        ]);
});
