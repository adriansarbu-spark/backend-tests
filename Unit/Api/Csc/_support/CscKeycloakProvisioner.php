<?php

declare(strict_types=1);

/**
 * Test-only stand-in for {@see CscKeycloakProvisioner}.
 *
 * Loaded instead of system/library/csc_keycloak_provisioner.php when unit-testing
 * api_client (production file is left unchanged). Behaviour is delegated to
 * {@see CscKeycloakProvisionerFake} via {@see CscKeycloakProvisioner::$testDouble}.
 */
class CscKeycloakProvisioner
{
    /** @var object|null object with the Keycloak provisioner method surface */
    public static $testDouble = null;

    /** @var object */
    private $inner;

    public function __construct($registry)
    {
        if (self::$testDouble !== null) {
            $this->inner = self::$testDouble;

            return;
        }

        // Safe default: look unconfigured so accidental create/rotate cannot hit the network.
        $fallback = new CscKeycloakProvisionerFake();
        $fallback->configured = false;
        $this->inner = $fallback;
    }

    public function getLastError()
    {
        return method_exists($this->inner, 'getLastError') ? $this->inner->getLastError() : '';
    }

    public function isConfigured()
    {
        return (bool) $this->inner->isConfigured();
    }

    public function createIntegratorClient($api_client_uuid, $company_id, $display_name)
    {
        return $this->inner->createIntegratorClient($api_client_uuid, $company_id, $display_name);
    }

    public function setClientEnabled($keycloak_client_uuid, $enabled)
    {
        return $this->inner->setClientEnabled((string) $keycloak_client_uuid, (bool) $enabled);
    }

    public function rotateClientSecret($keycloak_client_uuid)
    {
        return $this->inner->rotateClientSecret((string) $keycloak_client_uuid);
    }

    public function rollbackClient($keycloak_client_uuid)
    {
        return $this->inner->rollbackClient((string) $keycloak_client_uuid);
    }

    public function getClientSecretForIntegrator($keycloak_client_uuid)
    {
        if (method_exists($this->inner, 'getClientSecretForIntegrator')) {
            return $this->inner->getClientSecretForIntegrator((string) $keycloak_client_uuid);
        }

        return null;
    }

    public function ensureIntegratorClientCredentialsCapable($keycloak_client_uuid)
    {
        return (bool) $this->inner->ensureIntegratorClientCredentialsCapable((string) $keycloak_client_uuid);
    }
}
