<?php

declare(strict_types=1);

/**
 * Minimal DB double for CheckinConsiderReasonCatalog / CheckinService unit
 * tests: serves preset catalog rows for SELECTs and records INSERTs (unknown-
 * code stub registration).
 */
final class CheckinCatalogDbDouble
{
    /** @var array<int, array<string, mixed>> */
    public array $rows;

    /** @var list<string> */
    public array $executedInserts = [];

    /** @param array<int, array<string, mixed>> $rows checkin_consider_reason rows */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function query(string $sql): object
    {
        if (stripos(ltrim($sql), 'INSERT') === 0) {
            $this->executedInserts[] = $sql;

            return (object) ['rows' => [], 'row' => [], 'num_rows' => 0];
        }

        return (object) ['rows' => $this->rows, 'row' => $this->rows[0] ?? [], 'num_rows' => count($this->rows)];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

/** Registry double resolving only the services the catalog/service touch. */
final class CheckinCatalogRegistryDouble
{
    /** @param array<string, mixed> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $key): mixed
    {
        return $this->services[$key] ?? null;
    }
}

/**
 * Catalog row factory with the same defaults as the migration schema.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function checkin_catalog_row(array $overrides): array
{
    return array_merge([
        'code' => '',
        'service' => 'doc-check',
        'vendor_key' => '',
        'vendor_type' => 'consider',
        'vendor_description' => '',
        'category' => 'other',
        'retryable' => '1',
        'user_message_en' => '',
        'user_message_ro' => '',
        'integrator_code' => '',
        'integrator_message' => '',
        'show_user' => '1',
        'show_integrator' => '1',
        'active' => '1',
        'curated' => '1',
        'sort_order' => '0',
    ], $overrides);
}

/**
 * Representative catalog fixture: a visible retryable code, a visible
 * non-retryable code, a hidden fraud code, and the templated DC047.
 *
 * @return array<int, array<string, mixed>>
 */
function checkin_catalog_fixture_rows(): array
{
    return [
        checkin_catalog_row([
            'code' => 'DC004',
            'vendor_key' => 'glareOnPhoto',
            'category' => 'photo_quality',
            'user_message_en' => 'There is glare on the document photo. Please retake it while avoiding direct light reflections.',
            'user_message_ro' => 'Există reflexii de lumină pe fotografia documentului. Reîncercați evitând reflexiile directe.',
            'integrator_code' => 'glare_on_photo',
            'integrator_message' => 'Glare on the document photo obscures information.',
        ]),
        checkin_catalog_row([
            'code' => 'DC048',
            'vendor_key' => 'documentExpired',
            'vendor_type' => 'field',
            'category' => 'document_integrity',
            'retryable' => '0',
            'user_message_en' => 'The document has expired. Please use a valid document.',
            'user_message_ro' => 'Documentul a expirat. Folosiți un document valabil.',
            'integrator_code' => 'document_expired',
            'integrator_message' => 'The document has expired.',
        ]),
        checkin_catalog_row([
            'code' => 'DC028',
            'vendor_key' => 'digitalTampering',
            'category' => 'fraud',
            'retryable' => '0',
            'show_user' => '0',
            'integrator_code' => 'digital_tampering',
            'integrator_message' => 'Digital tampering of the document is suspected.',
        ]),
        checkin_catalog_row([
            'code' => 'DC047',
            'vendor_key' => 'fieldConflict',
            'vendor_type' => 'field',
            'category' => 'data_mismatch',
            'user_message_en' => 'The {field} read from the printed text ({value_ocr}) does not match the machine-readable zone ({value_mrz}). Please retake a clear photo of the document.',
            'user_message_ro' => 'Câmpul {field} citit din textul tipărit ({value_ocr}) nu corespunde cu zona de citire automată ({value_mrz}). Reîncercați cu o fotografie clară a documentului.',
            'integrator_code' => 'field_conflict',
            'integrator_message' => 'The {field} conflicts between document sources ({value_ocr} vs {value_mrz}).',
        ]),
        checkin_catalog_row([
            'code' => 'platform.personal_number_invalid',
            'service' => 'platform',
            'vendor_key' => 'personal_number_invalid',
            'vendor_type' => 'platform',
            'category' => 'data_extraction',
            'user_message_en' => 'The personal number (CNP) on the document could not be validated. Please retake a clear photo; if the problem persists, contact support.',
            'user_message_ro' => 'Numărul personal (CNP) de pe document nu a putut fi validat. Reîncercați cu o fotografie clară; dacă problema persistă, contactați asistența.',
            'integrator_code' => 'personal_number_invalid',
            'integrator_message' => 'The personal number (CNP) failed validation (Simplifi mandatory check).',
        ]),
    ];
}
