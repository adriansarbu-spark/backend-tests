<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountCompaniesModelStubs.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Mock builders for account companies controller unit tests (must run on PHPUnit TestCase $this).
 */
trait AccountCompaniesModelMocks
{
    /** @return MockObject&AccountCompaniesCompanyModelStub */
    protected function acCompanyModel(): MockObject
    {
        return $this->createMock(AccountCompaniesCompanyModelStub::class);
    }

    /** @return MockObject&AccountCompaniesCustomerRoleModelStub */
    protected function acCustomerRoleModel(): MockObject
    {
        return $this->createMock(AccountCompaniesCustomerRoleModelStub::class);
    }
}
