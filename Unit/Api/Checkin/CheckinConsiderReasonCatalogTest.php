<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once DIR_SYSTEM . 'library/checkin_consider_catalog.php';
require_once __DIR__ . '/_support/CheckinConsiderCatalogTestDoubles.php';

/**
 * Unit tests: CheckinConsiderReasonCatalog resolution (user + integrator
 * surfaces) and the compliance guarantees around hidden codes.
 */

function checkin_catalog_with_fixture(): array
{
    $db = new CheckinCatalogDbDouble(checkin_catalog_fixture_rows());
    $registry = new CheckinCatalogRegistryDouble(['db' => $db]);

    return [new CheckinConsiderReasonCatalog($registry), $db];
}

test('resolveForUser returns curated localized messages and skips summary IDs', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $resolved = $catalog->resolveForUser([
        ['id' => 'DC045', 'service' => 'doc-check', 'message' => 'Found 2 issue(s)'],
        ['id' => 'DC004', 'service' => 'doc-check', 'message' => 'Glared photo provided.'],
    ], 'ro-ro');

    expect($resolved['specific'])->toBeTrue()
        ->and($resolved['messages'])->toBe(['Există reflexii de lumină pe fotografia documentului. Reîncercați evitând reflexiile directe.'])
        ->and($resolved['retryable'])->toBeTrue();
});

test('resolveForUser renders DC047 params with Romanian field name', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $resolved = $catalog->resolveForUser([
        [
            'id' => 'DC047',
            'service' => 'doc-check',
            'message' => 'Fields from ocr and mrz have conflict: 2031-08-03, 2031-12-03',
            'params' => ['field' => 'Date of expiry', 'value_ocr' => '2031-08-03', 'value_mrz' => '2031-12-03'],
        ],
    ], 'ro');

    expect($resolved['messages'][0])->toBe(
        'Câmpul Data expirării citit din textul tipărit (2031-08-03) nu corespunde cu zona de citire automată (2031-12-03). Reîncercați cu o fotografie clară a documentului.'
    );
});

test('resolveForUser never discloses hidden fraud codes and falls back to the generic line', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $resolved = $catalog->resolveForUser([
        ['id' => 'DC028', 'service' => 'doc-check', 'message' => 'Digital tampering of a document suspected.'],
    ], 'en');

    expect($resolved['specific'])->toBeFalse()
        ->and($resolved['retryable'])->toBeFalse()
        ->and($resolved['messages'])->toHaveCount(1)
        ->and($resolved['messages'][0])->not->toContain('tampering');
});

test('resolveForUser marks the outcome non-retryable when any visible reason is terminal', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $resolved = $catalog->resolveForUser([
        ['id' => 'DC004', 'service' => 'doc-check'],
        ['id' => 'DC048', 'service' => 'doc-check'],
    ], 'en');

    expect($resolved['messages'])->toHaveCount(2)
        ->and($resolved['retryable'])->toBeFalse();
});

test('resolveForIntegrator returns stable reason aliases and end_user_safe flags', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $details = $catalog->resolveForIntegrator([
        ['id' => 'DC004', 'service' => 'doc-check', 'message' => 'Glared photo provided.'],
        ['id' => 'DC028', 'service' => 'doc-check', 'message' => 'Digital tampering of a document suspected.'],
        ['id' => 'platform.personal_number_invalid', 'service' => 'platform'],
    ]);

    expect(array_column($details, 'reason_code'))->toBe(['glare_on_photo', 'digital_tampering', 'personal_number_invalid'])
        ->and($details[0]['end_user_safe'])->toBeTrue()
        ->and($details[1]['end_user_safe'])->toBeFalse()
        ->and($details[1]['retryable'])->toBeFalse()
        ->and($details[2]['code'])->toBe('platform.personal_number_invalid');
});

test('resolveForIntegrator passes unknown codes through and registers a curation stub', function () {
    [$catalog, $db] = checkin_catalog_with_fixture();

    $details = $catalog->resolveForIntegrator([
        ['id' => 'DC999', 'service' => 'doc-check', 'message' => 'Brand new vendor code.'],
    ]);

    expect($details)->toHaveCount(1)
        ->and($details[0]['code'])->toBe('DC999')
        ->and($details[0]['reason_code'])->toBe('DC999')
        ->and($details[0]['end_user_safe'])->toBeFalse()
        ->and($db->executedInserts)->toHaveCount(1)
        ->and($db->executedInserts[0])->toContain("`code` = 'DC999'")
        ->and($db->executedInserts[0])->toContain('`curated` = 0');
});

test('filterUserSafeConcerns strips vendor prose and hidden codes from the frontend payload', function () {
    [$catalog] = checkin_catalog_with_fixture();

    $safe = $catalog->filterUserSafeConcerns([
        ['id' => 'DC004', 'service' => 'doc-check', 'message' => 'Glared photo provided.'],
        ['id' => 'DC028', 'service' => 'doc-check', 'message' => 'Digital tampering of a document suspected.'],
        ['id' => 'DC047', 'service' => 'doc-check', 'message' => 'raw vendor text', 'params' => ['field' => 'Date of expiry']],
    ]);

    expect(array_column($safe, 'id'))->toBe(['DC004', 'DC047'])
        ->and($safe[0])->not->toHaveKey('message')
        ->and($safe[1]['params'])->toBe(['field' => 'Date of expiry']);
});

test('concernsFromDeclineJson and platformConcerns normalize their inputs', function () {
    $json = json_encode([
        'overall_status' => 'declined',
        'concerns' => [
            ['id' => 'DC047', 'service' => 'doc-check', 'message' => 'conflict'],
            'not-an-array',
        ],
    ]);

    expect(CheckinConsiderReasonCatalog::concernsFromDeclineJson($json))->toBe([
        ['id' => 'DC047', 'service' => 'doc-check', 'message' => 'conflict'],
    ])
        ->and(CheckinConsiderReasonCatalog::concernsFromDeclineJson(null))->toBe([])
        ->and(CheckinConsiderReasonCatalog::concernsFromDeclineJson('{broken'))->toBe([])
        ->and(CheckinConsiderReasonCatalog::platformConcerns(['platform.check_failed', '', 42]))->toBe([
            ['id' => 'platform.check_failed', 'service' => 'platform'],
        ]);
});

test('listForIntegratorReference publishes only curated visible rows with message params', function () {
    $rows = checkin_catalog_fixture_rows();
    $rows[] = checkin_catalog_row([
        'code' => 'DC999',
        'curated' => '0',
        'active' => '0',
        'show_user' => '0',
        'integrator_message' => 'Auto-registered stub, not reviewed yet.',
    ]);
    $rows[] = checkin_catalog_row([
        'code' => 'INT00',
        'show_integrator' => '0',
        'integrator_message' => 'Internal-only row.',
    ]);
    $db = new CheckinCatalogDbDouble($rows);
    $catalog = new CheckinConsiderReasonCatalog(new CheckinCatalogRegistryDouble(['db' => $db]));

    $list = $catalog->listForIntegratorReference();
    $byCode = array_column($list, null, 'code');

    expect(array_keys($byCode))->toBe(['DC004', 'DC048', 'DC028', 'DC047', 'platform.personal_number_invalid'])
        ->and($byCode['DC047']['reason_code'])->toBe('field_conflict')
        ->and($byCode['DC047']['message'])->toContain('{field}')
        ->and($byCode['DC047']['message_params'])->toBe(['field', 'value_ocr', 'value_mrz'])
        ->and($byCode['DC028']['end_user_safe'])->toBeFalse()
        ->and($byCode['DC004'])->not->toHaveKey('message_params');
});

test('catalog degrades to the generic line when the table is missing', function () {
    $failingDb = new class {
        public function query(string $sql): object
        {
            throw new RuntimeException("Table 'checkin_consider_reason' doesn't exist");
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }
    };
    $catalog = new CheckinConsiderReasonCatalog(new CheckinCatalogRegistryDouble(['db' => $failingDb]));

    $resolved = $catalog->resolveForUser([['id' => 'DC004', 'service' => 'doc-check']], 'en');

    expect($resolved['specific'])->toBeFalse()
        ->and($resolved['messages'])->toHaveCount(1);
});
