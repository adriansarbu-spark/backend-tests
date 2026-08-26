<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Billing/_support/BillingTestDoubles.php';

if (!class_exists(Log::class, false)) {
    final class Log
    {
        /** @var list<string> */
        public static array $messages = [];

        public function __construct(public readonly string $filename = '')
        {
        }

        public function write(mixed $message): void
        {
            self::$messages[] = (string) $message;
        }
    }
}

require_once PUBLIC_API . 'stripe/webhook.php';

final class StripeWebhookResponseStub
{
    /** @var list<string> */
    public array $headers = [];

    public string $output = '';

    public function addHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->output, true);

        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * @param array<string, object> $models
 * @param array<string, mixed>  $server
 *
 * @return array{0: ControllerPublicapiv1StripeWebhook, 1: Registry, 2: StripeWebhookResponseStub, 3: BillingLoadStub}
 */
function stripe_webhook_fixture(array $models = [], array $server = []): array
{
    $registry = new Registry();
    $load = new BillingLoadStub($registry, $models);
    $response = new StripeWebhookResponseStub();
    $registry->set('load', $load);
    $registry->set('response', $response);
    $registry->set('request', (object) [
        'server' => array_replace([
            'REQUEST_METHOD' => 'POST',
            'HTTP_STRIPE_SIGNATURE' => 'test-signature',
        ], $server),
    ]);
    $controller = new ControllerPublicapiv1StripeWebhook($registry);

    return [$controller, $registry, $response, $load];
}

/** @param list<mixed> $arguments */
function stripe_webhook_invoke(object $controller, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($controller, $arguments);
}

function stripe_webhook_reset(): void
{
    stripe_test_reset();
    if (property_exists(Log::class, 'messages')) {
        Log::$messages = [];
    }
}
