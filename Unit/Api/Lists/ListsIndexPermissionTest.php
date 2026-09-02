<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'lists.php';
require_once __DIR__ . '/_support/ListsTestDoubles.php';

beforeEach(function () {
    $this->listsIndexHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->listsIndexSavedMethod = $this->listsIndexHadMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->listsIndexHadMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->listsIndexSavedMethod;
    }
});

/**
 * Prerequisites:
 * - Unit harness with permission and list-model doubles; no database.
 * - An authenticated caller asks for list 42 but has no `list.list_id.42` grant.
 *
 * Steps:
 * 1. GET `index()` with `list_id=42` and an empty permission list.
 * 2. Assert access is hidden as not found (**HTTP 404**) with a non-empty `error`.
 * 3. Assert the list is never loaded or queried (no IDOR data fetch).
 */
test('Lists API — index without list.list_id permission returns 404 and does not fetch the list', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $pageList] = lists_index_permission_harness(
        [],
        ['list_id' => '42', 'company_id' => '99'],
    );

    $controller->index();

    lists_index_assert_denied_hidden($controller, $load, $pageList);
});

/**
 * Prerequisites:
 * - Unit harness with permission and list-model doubles; no database.
 * - The caller may read list 41 but asks for a different list (42).
 *
 * Steps:
 * 1. GET `index()` for list 42 while `permission.get` only contains `list.list_id.41`.
 * 2. Assert the other list is hidden as not found (**HTTP 404**) so existence is not leaked.
 * 3. Assert list 42 is never fetched.
 */
test('Lists API — index with permission for a different list id returns 404 and hides existence', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $pageList] = lists_index_permission_harness(
        ['list.list_id.41', 'publicapi/v1/lists'],
        ['list_id' => '42'],
    );

    $controller->index();

    lists_index_assert_denied_hidden($controller, $load, $pageList);
});

/**
 * Prerequisites:
 * - Unit harness with permission and list-model doubles; no database.
 * - The caller has `list.list_id.42` but omits `list_id` from the request.
 *
 * Steps:
 * 1. GET `index()` without `list_id`.
 * 2. Assert **HTTP 404** with a non-empty `error` (no collection dump).
 * 3. Assert no list model is loaded.
 */
test('Lists API — index without list_id returns 404 even when a list permission is granted', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $pageList] = lists_index_permission_harness(
        ['list.list_id.42'],
        [],
    );

    $controller->index();

    lists_index_assert_denied_hidden($controller, $load, $pageList);
});

/**
 * Prerequisites:
 * - Unit harness with permission and list-model doubles; no database.
 * - The caller has `list.list_id.42` for the requested list.
 *
 * Steps:
 * 1. GET `index()` with `list_id=42` and matching `list.list_id.42`.
 * 2. Assert the permission gate proceeds to fetch that list (not **HTTP 404**).
 * 3. Assert the list model is queried for 42 and the table payload is returned.
 */
test('Lists API — index with matching list.list_id permission fetches and returns the list', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $pageList] = lists_index_permission_harness(
        ['list.list_id.41', 'list.list_id.42'],
        ['list_id' => '42', 'company_id' => '99'],
        10,
    );

    $controller->index();

    expect($controller->checkPluginCalls)->toBe(1)
        ->and($controller->statusCode)->toBe(200)
        ->and($controller->json['error'])->toBe([])
        ->and($controller->json['data'])->toBe([['label' => 'Alpha']])
        ->and($controller->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toContain('page/list')
        ->and($pageList->getListCalls)->toBe(['42'])
        ->and($pageList->getListContentCalls)->toHaveCount(1)
        ->and($pageList->getListContentCalls[0]['company_id'])->toBe(10);
});
