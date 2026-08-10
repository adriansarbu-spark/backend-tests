<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollmentPhotos::firstUploadCodeFromJson()
 * (protected helper exposed via TestableControllerPublicAPIV1CscEnrollmentPhotos).
 */

/**
 * Prerequisites:
 * - None; helper has no side effects.
 *
 * Steps:
 * 1. Invoke exposeFirstUploadCodeFromJson() with each dataset input.
 * 2. Assert the first trimmed non-empty string is returned, or '' when none exists.
 */
test('CSC enrollment photos helper — first upload code from json', function ($input, string $expected) {
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollmentPhotos($registry);

    expect($c->exposeFirstUploadCodeFromJson($input))->toBe($expected);
})->with([
    'null'                             => [null, ''],
    'empty string'                     => ['', ''],
    'empty object'                     => ['{}', ''],
    'empty array'                      => ['[]', ''],
    'raw scalar json (legacy vendor url)' => ['"http://x"', ''],
    'object with non-string value'     => ['{"a":1}', ''],
    'array with blank then real code'  => ['["  ","code1"]', 'code1'],
    'array with two codes returns first' => ['["code1","code2"]', 'code1'],
    'malformed json'                   => ['{invalid', ''],
]);
