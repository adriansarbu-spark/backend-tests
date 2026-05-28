<?php

declare(strict_types=1);

/**
 * Abstract stubs for PHPUnit mocks (replaces deprecated stdClass + addMethods()).
 */

abstract class AccountCompaniesCompanyModelStub
{
    /**
     * @return list<array<string, mixed>>
     */
    abstract public function getCompaniesForCustomerApi(int $customer_id): array;

    abstract public function addCompany(array $data): mixed;
}

abstract class AccountCompaniesCustomerRoleModelStub
{
    /**
     * @return array{company_uuid: string, role_uuid: string}|null
     */
    abstract public function getAdminRoleUuidsForCompany(int $customer_id, int $company_id): ?array;
}
