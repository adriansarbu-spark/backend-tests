<?php

/**
 * Resolve test configuration constants based on the active executor.
 *
 * Local (Dev) runs use the original constant names.
 * Remote (Prod) runs use matching PROD_ prefixed constants.
 */
function resolveTestConfig(string $name, ?string $executor = null)
{
    $executor = normalizeTestExecutor($executor);

    if ($executor === 'remote') {
        $prodName = 'PROD_' . $name;
        if (!defined($prodName)) {
            throw new RuntimeException('Missing production configuration constant: ' . $prodName);
        }

        return constant($prodName);
    }

    if (!defined($name)) {
        throw new RuntimeException('Missing configuration constant: ' . $name);
    }

    return constant($name);
}

function currentTestExecutor(): string
{
    return normalizeTestExecutor(null);
}

function normalizeTestExecutor(?string $executor): string
{
    if ($executor === null) {
        $executor = getenv('TEST_EXECUTOR');
    }

    $executor = strtolower(trim((string)($executor ?: 'local')));

    return in_array($executor, array('local', 'remote'), true) ? $executor : 'local';
}

function isTestConfigDefined(string $name, ?string $executor = null): bool
{
    $executor = normalizeTestExecutor($executor);

    if ($executor === 'remote') {
        return defined('PROD_' . $name);
    }

    return defined($name);
}

function resolvedTestConfigValue(string $name, ?string $executor = null): string
{
    $value = resolveTestConfig($name, $executor);

    return is_string($value) ? trim($value) : trim((string)$value);
}

function assertTestConfigKeysOrSkip(array $keys): void
{
    foreach ($keys as $key) {
        if (!isTestConfigDefined($key) || resolvedTestConfigValue($key) === '') {
            if (function_exists('test')) {
                test()->markTestSkipped('Missing required test config constant: ' . $key);
            }
        }
    }
}
