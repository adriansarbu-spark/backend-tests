<?php

declare(strict_types=1);

namespace Stripe {
    if (!class_exists(Stripe::class, false)) {
        final class Stripe
        {
            public static string $apiKey = '';

            public static string $apiVersion = '';

            public static function setApiKey(string $apiKey): void
            {
                self::$apiKey = $apiKey;
            }

            public static function setApiVersion(string $apiVersion): void
            {
                self::$apiVersion = $apiVersion;
            }
        }
    }

    if (!class_exists(Customer::class, false)) {
        final class Customer
        {
            /** @var list<mixed> */
            public static array $createQueue = [];

            /** @var list<mixed> */
            public static array $retrieveQueue = [];

            /** @var list<mixed> */
            public static array $updateQueue = [];

            /** @var list<array<string, mixed>> */
            public static array $createCalls = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $updateCalls = [];

            /** @var list<string> */
            public static array $retrieveCalls = [];

            /** @param array<string, mixed> $params */
            public static function create(array $params): object
            {
                self::$createCalls[] = $params;

                return self::next(self::$createQueue, (object) ['id' => 'cus_test']);
            }

            /** @param array<string, mixed> $params */
            public static function update(string $id, array $params): object
            {
                self::$updateCalls[] = ['id' => $id, 'params' => $params];

                return self::next(self::$updateQueue, (object) ['id' => $id]);
            }

            public static function retrieve(string $id): object
            {
                self::$retrieveCalls[] = $id;

                return self::next(self::$retrieveQueue, (object) ['id' => $id]);
            }

            /** @param list<mixed> $queue */
            private static function next(array &$queue, object $default): object
            {
                $result = $queue === [] ? $default : array_shift($queue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }

    if (!class_exists(Subscription::class, false)) {
        final class Subscription
        {
            /** @var list<mixed> */
            public static array $updateQueue = [];

            /** @var list<mixed> */
            public static array $retrieveQueue = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $updateCalls = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $retrieveCalls = [];

            /** @param array<string, mixed> $params */
            public static function update(string $id, array $params): object
            {
                self::$updateCalls[] = ['id' => $id, 'params' => $params];

                return self::next(self::$updateQueue, (object) ['id' => $id]);
            }

            /** @param array<string, mixed> $params */
            public static function retrieve(string $id, array $params = []): object
            {
                self::$retrieveCalls[] = ['id' => $id, 'params' => $params];

                return self::next(self::$retrieveQueue, (object) ['id' => $id]);
            }

            /** @param list<mixed> $queue */
            private static function next(array &$queue, object $default): object
            {
                $result = $queue === [] ? $default : array_shift($queue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }

    if (!class_exists(SubscriptionItem::class, false)) {
        final class SubscriptionItem
        {
            /** @var list<mixed> */
            public static array $updateQueue = [];

            /** @var list<mixed> */
            public static array $createQueue = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $updateCalls = [];

            /** @var list<array<string, mixed>> */
            public static array $createCalls = [];

            /** @param array<string, mixed> $params */
            public static function update(string $id, array $params): object
            {
                self::$updateCalls[] = ['id' => $id, 'params' => $params];

                return self::next(self::$updateQueue, (object) ['id' => $id]);
            }

            /** @param array<string, mixed> $params */
            public static function create(array $params): object
            {
                self::$createCalls[] = $params;

                return self::next(self::$createQueue, (object) ['id' => 'si_created']);
            }

            /** @param list<mixed> $queue */
            private static function next(array &$queue, object $default): object
            {
                $result = $queue === [] ? $default : array_shift($queue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }

    if (!class_exists(Invoice::class, false)) {
        final class Invoice
        {
            /** @var list<mixed> */
            public static array $retrieveQueue = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $retrieveCalls = [];

            /** @param array<string, mixed> $params */
            public static function retrieve(string $id, array $params = []): object
            {
                self::$retrieveCalls[] = ['id' => $id, 'params' => $params];
                $result = self::$retrieveQueue === [] ? (object) ['id' => $id] : array_shift(self::$retrieveQueue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }

    if (!class_exists(Webhook::class, false)) {
        final class Webhook
        {
            /** @var list<mixed> */
            public static array $queue = [];

            /** @var list<array{payload: string, signature: string, secret: string}> */
            public static array $calls = [];

            public static function constructEvent(string $payload, string $signature, string $secret): object
            {
                self::$calls[] = compact('payload', 'signature', 'secret');
                $result = self::$queue === []
                    ? (object) ['type' => 'unhandled.test', 'data' => (object) ['object' => (object) []]]
                    : array_shift(self::$queue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }
}

namespace Stripe\Checkout {
    if (!class_exists(Session::class, false)) {
        final class Session
        {
            /** @var list<mixed> */
            public static array $createQueue = [];

            /** @var list<mixed> */
            public static array $retrieveQueue = [];

            /** @var list<mixed> */
            public static array $lineItemsQueue = [];

            /** @var list<array<string, mixed>> */
            public static array $createCalls = [];

            /** @var list<string> */
            public static array $retrieveCalls = [];

            /** @var list<array{id: string, params: array<string, mixed>}> */
            public static array $lineItemsCalls = [];

            /** @param array<string, mixed> $params */
            public static function create(array $params): object
            {
                self::$createCalls[] = $params;

                return self::next(self::$createQueue, (object) ['id' => 'cs_test', 'url' => 'https://checkout.test/session']);
            }

            public static function retrieve(string $id): object
            {
                self::$retrieveCalls[] = $id;

                return self::next(self::$retrieveQueue, (object) ['id' => $id]);
            }

            /** @param array<string, mixed> $params */
            public static function allLineItems(string $id, array $params): object
            {
                self::$lineItemsCalls[] = ['id' => $id, 'params' => $params];

                return self::next(self::$lineItemsQueue, new LineItemsPage([]));
            }

            /** @param list<mixed> $queue */
            private static function next(array &$queue, object $default): object
            {
                $result = $queue === [] ? $default : array_shift($queue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }

        final class LineItemsPage
        {
            /** @param list<object> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function autoPagingIterator(): \Traversable
            {
                yield from $this->items;
            }
        }
    }
}

namespace Stripe\BillingPortal {
    if (!class_exists(Session::class, false)) {
        final class Session
        {
            /** @var list<mixed> */
            public static array $createQueue = [];

            /** @var list<array<string, mixed>> */
            public static array $createCalls = [];

            /** @param array<string, mixed> $params */
            public static function create(array $params): object
            {
                self::$createCalls[] = $params;
                $result = self::$createQueue === []
                    ? (object) ['url' => 'https://billing.test/portal']
                    : array_shift(self::$createQueue);
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        }
    }
}

namespace Stripe\Exception {
    if (!class_exists(ApiErrorException::class, false)) {
        class ApiErrorException extends \Exception
        {
            public function __construct(
                string $message = '',
                private readonly ?int $httpStatus = null,
                private readonly ?string $stripeCode = null,
            ) {
                parent::__construct($message);
            }

            public function getHttpStatus(): ?int
            {
                return $this->httpStatus;
            }

            public function getStripeCode(): ?string
            {
                return $this->stripeCode;
            }
        }
    }

    if (!class_exists(SignatureVerificationException::class, false)) {
        class SignatureVerificationException extends \Exception
        {
        }
    }
}

namespace {
    if (!defined('STRIPE_SECRET_KEY')) {
        define('STRIPE_SECRET_KEY', 'sk_test_controller_double');
    }
    if (!defined('STRIPE_WEBHOOK_SECRET')) {
        define('STRIPE_WEBHOOK_SECRET', 'whsec_controller_double');
    }

    function stripe_test_reset(): void
    {
        \Stripe\Stripe::$apiKey = '';
        \Stripe\Stripe::$apiVersion = '';
        \Stripe\Customer::$createQueue = [];
        \Stripe\Customer::$retrieveQueue = [];
        \Stripe\Customer::$updateQueue = [];
        \Stripe\Customer::$createCalls = [];
        \Stripe\Customer::$retrieveCalls = [];
        \Stripe\Customer::$updateCalls = [];
        \Stripe\Subscription::$updateQueue = [];
        \Stripe\Subscription::$retrieveQueue = [];
        \Stripe\Subscription::$updateCalls = [];
        \Stripe\Subscription::$retrieveCalls = [];
        \Stripe\SubscriptionItem::$updateQueue = [];
        \Stripe\SubscriptionItem::$createQueue = [];
        \Stripe\SubscriptionItem::$updateCalls = [];
        \Stripe\SubscriptionItem::$createCalls = [];
        \Stripe\Invoice::$retrieveQueue = [];
        \Stripe\Invoice::$retrieveCalls = [];
        \Stripe\Webhook::$queue = [];
        \Stripe\Webhook::$calls = [];
        \Stripe\Checkout\Session::$createQueue = [];
        \Stripe\Checkout\Session::$retrieveQueue = [];
        \Stripe\Checkout\Session::$lineItemsQueue = [];
        \Stripe\Checkout\Session::$createCalls = [];
        \Stripe\Checkout\Session::$retrieveCalls = [];
        \Stripe\Checkout\Session::$lineItemsCalls = [];
        \Stripe\BillingPortal\Session::$createQueue = [];
        \Stripe\BillingPortal\Session::$createCalls = [];
    }
}
