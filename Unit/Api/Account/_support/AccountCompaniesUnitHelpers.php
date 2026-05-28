<?php

declare(strict_types=1);

/**
 * Shared helpers for {@see ControllerPublicAPIV1AccountCompanies} unit tests (Pest).
 */

/**
 * @return array{0: Registry, 1: AccountCompaniesRecordingLoadStub}
 */
function ac_registry_with_customer(object $customer): array
{
    $registry = new Registry();
    $load = new AccountCompaniesRecordingLoadStub();
    $registry->set('load', $load);
    $registry->set('customer', $customer);

    return array($registry, $load);
}

/** @return array{fiscal_address: array<string, mixed>, headquarters_address: array<string, mixed>} */
function ac_default_addresses(): array
{
    $block = array(
        'street'    => 'S',
        'street_no' => '1',
        'details'   => '',
        'jud_cod'   => 10,
        'loc_cod'   => 20,
    );

    return array(
        'fiscal_address'       => $block,
        'headquarters_address' => $block,
    );
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function ac_valid_post(array $overrides = array()): array
{
    return array_merge(
        array(
            'name'                => 'ACME',
            'country'             => 'RO',
            'tin'                 => '123456',
            'registration_number' => 'J40/1/2024',
        ),
        ac_default_addresses(),
        $overrides
    );
}
