<?php

declare(strict_types=1);

/**
 * Test-only stand-in for {@see CscIntegratorAuth}.
 * Loaded instead of system/library/csc_integrator_auth.php for integrator unit tests.
 */
class CscIntegratorAuth
{
    /** @var object|null */
    public static $testDouble = null;

    /** @var object */
    private $inner;

    public function __construct($registry)
    {
        if (self::$testDouble !== null) {
            $this->inner = self::$testDouble;

            return;
        }

        $fallback = new CscIntegratorAuthFake();
        $fallback->valid = false;
        $fallback->lastError = 'missing_bearer';
        $this->inner = $fallback;
    }

    public function getLastError()
    {
        return $this->inner->getLastError();
    }

    public function getClientRow()
    {
        return $this->inner->getClientRow();
    }

    public function validateBearer($authHeader)
    {
        return (bool) $this->inner->validateBearer($authHeader);
    }
}
