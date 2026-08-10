<?php

declare(strict_types=1);

/**
 * Test-only stand-in for {@see CscCredentialIssuance}.
 */
class CscCredentialIssuance
{
    /** @var object|null */
    public static $testDouble = null;

    /** @var object */
    private $inner;

    public function __construct($registry)
    {
        $this->inner = self::$testDouble ?? new CscCredentialIssuanceFake();
    }

    public function issueForEnrollment($client_id, $enrollment_uuid, $email = '')
    {
        return $this->inner->issueForEnrollment((int) $client_id, (string) $enrollment_uuid, (string) $email);
    }
}
