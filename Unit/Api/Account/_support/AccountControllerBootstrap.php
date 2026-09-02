<?php

declare(strict_types=1);

/**
 * Loads {@see ControllerPublicAPIV1Account} without pulling the real
 * {@see AccountClosure} Keycloak/certificate cascade, and installs a static
 * double so DELETE /account can be unit-tested.
 *
 * Pest runs the Unit suite in one process. Every file that needs this
 * controller must load it through this bootstrap so the real
 * `account_closure.php` require inside deleteAccount() is never executed.
 */

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

if (! class_exists('AccountClosure', false)) {
    /**
     * Test double for {@see AccountClosure}. Records cascade calls and returns
     * a configurable result; invokes the security-event callback only on success.
     */
    class AccountClosure
    {
        /** @var array{ok: bool, error: ?string, cascaded_duplicate_customer_ids: list<int>} */
        public static array $result = [
            'ok' => true,
            'error' => null,
            'cascaded_duplicate_customer_ids' => [],
        ];

        /** @var list<array{customer_id: int}> */
        public static array $calls = [];

        public static function isTestDouble(): bool
        {
            return true;
        }

        public static function reset(): void
        {
            self::$result = [
                'ok' => true,
                'error' => null,
                'cascaded_duplicate_customer_ids' => [],
            ];
            self::$calls = [];
        }

        /**
         * @param object $registry
         * @param int $customer_id
         * @param callable|null $record_security_event
         * @return array{ok: bool, error: ?string, cascaded_duplicate_customer_ids: list<int>}
         */
        public static function closeAccountCascade($registry, $customer_id, $record_security_event = null): array
        {
            $customer_id = (int) $customer_id;
            self::$calls[] = ['customer_id' => $customer_id];

            if (! empty(self::$result['ok']) && is_callable($record_security_event)) {
                foreach (self::$result['cascaded_duplicate_customer_ids'] as $dupId) {
                    $record_security_event((int) $dupId, 'account_deleted', [
                        'cascade_from_customer_id' => $customer_id,
                    ]);
                }
                $record_security_event($customer_id, 'account_deleted', []);
            }

            return self::$result;
        }
    }
} elseif (! method_exists('AccountClosure', 'isTestDouble')) {
    throw new RuntimeException(
        'Real AccountClosure is already loaded; the account controller bootstrap cannot install a test double.'
    );
}

if (! class_exists(AccountApiCacheStub::class, false)) {
    final class AccountApiCacheStub
    {
        /** @var array<string, mixed> */
        public array $store = [];

        /** @var list<string> */
        public array $deleted = [];

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): void
        {
            $this->store[$key] = $value;
        }

        public function delete(string $key): void
        {
            $this->deleted[] = $key;
            unset($this->store[$key]);
        }
    }
}

/**
 * Load the account controller with the in-method AccountClosure require stripped.
 */
function account_load_account_controller(): void
{
    if (class_exists('ControllerPublicAPIV1Account', false)) {
        if (! defined('ACCOUNT_UNIT_CLOSURE_DOUBLE')) {
            throw new RuntimeException(
                'ControllerPublicAPIV1Account was loaded without the AccountClosure double. '
                . 'Require tests/Unit/Api/Account/_support/AccountControllerBootstrap.php first.'
            );
        }

        return;
    }

    $fullPath = PUBLIC_API . 'account.php';
    $code = file_get_contents($fullPath);
    if ($code === false) {
        throw new RuntimeException('Unable to read ' . $fullPath);
    }

    $replaced = 0;
    $code = preg_replace(
        '/^\s*require_once\s+DIR_SYSTEM\s*\.\s*[\'"]library\/account_closure\.php[\'"]\s*;\s*$/m',
        '// stripped for unit tests: account_closure.php',
        $code,
        1,
        $replaced,
    );
    if (! is_string($code) || $replaced !== 1) {
        throw new RuntimeException('Failed to strip account_closure.php require from account.php');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'account_ctrl_');
    if ($tmp === false) {
        throw new RuntimeException('tempnam failed');
    }
    file_put_contents($tmp, $code);
    require_once $tmp;
    @unlink($tmp);

    if (! defined('ACCOUNT_UNIT_CLOSURE_DOUBLE')) {
        define('ACCOUNT_UNIT_CLOSURE_DOUBLE', true);
    }
}

account_load_account_controller();
