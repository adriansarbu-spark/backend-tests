<?php

declare(strict_types=1);

/**
 * Unit coverage for PartnerSponsorshipBilling pure helpers and stubbed
 * afterRevocation / billInvitations / afterActivation entry points.
 * No real DB, Stripe, or DbMutex waits.
 */

require_once dirname(__DIR__, 3) . '/tests_config.php';
require_once DIR_SYSTEM . 'library/partner_sponsorship_billing.php';

if (!class_exists(Model::class, false)) {
    require_once DIR_SYSTEM . 'engine/model.php';
}

// Prefer the real model so status constants match production and validator
// suites are not shadowed by a constants-only stub in the same process.
if (!class_exists('ModelPartnerCustomerLink', false)) {
    if (defined('DIR_VENDOR') && is_file(DIR_VENDOR . 'autoload.php')) {
        require_once DIR_VENDOR . 'autoload.php';
    }
    require_once DIR_APPLICATION . 'model/partner/customer_link.php';
}

/** Call-recording model for billing library tests. */
final class PartnerBillingModelStub
{
    /** @var array<string, mixed> */
    public array $responses = [];

    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        $response = $this->responses[$method] ?? null;

        if ($response instanceof Closure) {
            return $response(...$arguments);
        }

        return $response;
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method,
        ));
    }
}

final class PartnerBillingLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    /**
     * @param array<string, object> $models
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $models,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if (isset($this->models[$route])) {
            $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
        }
    }

    public function library(string $route): void
    {
    }
}

final class PartnerBillingLogStub
{
    /** @var list<string> */
    public array $lines = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }
}

/**
 * Overrides Stripe quantity mutations so library event handlers stay DB-free.
 */
final class TestablePartnerSponsorshipBilling extends PartnerSponsorshipBilling
{
    /** @var list<array{program: array<string,mixed>, plan: array<string,mixed>, quantity: int, actor: int}> */
    public array $addCalls = [];

    /** @var list<array{program: array<string,mixed>, plan: array<string,mixed>, quantity: int, actor: int}> */
    public array $decrementCalls = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: string, 4: string, 5: string, 6: int}> */
    public array $mintCalls = [];

    /** @var array{ok: bool, error?: string, period_start?: string, period_end?: string} */
    public array $addResult = [
        'ok' => true,
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-09-01 00:00:00',
    ];

    /** @var array{ok: bool, error?: string} */
    public array $decrementResult = ['ok' => true];

    public int $mintReturn = 2;

    public function addQuantityForPlan(array $program, array $plan, $quantity, $actor_customer_id = 0)
    {
        $this->addCalls[] = [
            'program' => $program,
            'plan' => $plan,
            'quantity' => (int) $quantity,
            'actor' => (int) $actor_customer_id,
        ];

        return $this->addResult;
    }

    public function decrementQuantityForPlan(array $program, array $plan, $quantity, $actor_customer_id = 0)
    {
        $this->decrementCalls[] = [
            'program' => $program,
            'plan' => $plan,
            'quantity' => (int) $quantity,
            'actor' => (int) $actor_customer_id,
        ];

        return $this->decrementResult;
    }

    public function mintGrants($grant_key_prefix, $customer_role_id, $product_id, $billing_interval, $starts_at, $expires_at, $source_id = 0)
    {
        $this->mintCalls[] = [
            (string) $grant_key_prefix,
            (int) $customer_role_id,
            (int) $product_id,
            (string) $billing_interval,
            (string) $starts_at,
            (string) $expires_at,
            (int) $source_id,
        ];

        return $this->mintReturn;
    }
}

/**
 * @param array<string, object> $models
 * @return array{0: Registry, 1: PartnerBillingLoadStub, 2: array<string, PartnerBillingModelStub>}
 */
function partner_billing_registry(array $models = []): array
{
    $registry = new Registry();
    $load = new PartnerBillingLoadStub($registry, $models);
    $registry->set('load', $load);
    $registry->set('log', new PartnerBillingLogStub());
    $registry->set('db', new class {
        public function query(string $sql): object
        {
            return (object) ['num_rows' => 0, 'row' => [], 'rows' => []];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function countAffected(): int
        {
            return 0;
        }
    });

    return [$registry, $load, $models];
}

/**
 * Prerequisites:
 * - Pure static helper; no registry.
 *
 * Steps:
 * 1. Call grantKeyPrefix with a sponsorship id and period start.
 * 2. Assert the period-keyed prefix used by activation and invoice minting.
 */
test('Partner — grantKeyPrefix is period-keyed for sponsorship grants', function () {
    expect(PartnerSponsorshipBilling::grantKeyPrefix(42, '2026-08-01 00:00:00'))
        ->toBe('sponsorship:42:start:2026-08-01 00:00:00');
});

/**
 * Prerequisites:
 * - Pure static grant quantity normalization (period × billing interval).
 *
 * Steps:
 * 1. Call grantQuantity for each period/interval pair.
 * 2. Assert month-on-year multiplies by 12, year-on-month divides, same-unit keeps base.
 */
test('Partner — grantQuantity normalizes entitlement period against billing interval', function (
    int $base,
    ?string $entPeriod,
    string $billingInterval,
    int $expected,
) {
    expect(PartnerSponsorshipBilling::grantQuantity($base, $entPeriod, $billingInterval))->toBe($expected);
})->with([
    'pool entitlement unchanged' => [5, null, 'month', 5],
    'month on month' => [3, 'month', 'month', 3],
    'year on year' => [2, 'year', 'year', 2],
    'month entitlement on yearly bill' => [1, 'month', 'year', 12],
    'year entitlement on monthly bill' => [12, 'year', 'month', 1],
    'zero base stays zero' => [0, 'month', 'year', 0],
]);

/**
 * Prerequisites:
 * - Link raw row with empty billed_at.
 *
 * Steps:
 * 1. Call afterRevocation.
 * 2. Assert no decrement and no markLinkUnbilled.
 */
test('Partner — afterRevocation is a no-op when billed_at is empty', function () {
    $linkModel = new PartnerBillingModelStub();
    $programModel = new PartnerBillingModelStub(['getProgramById' => ['program_id' => 1]]);
    $sponsorshipModel = new PartnerBillingModelStub(['getPlanById' => ['program_plan_id' => 5]]);
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/program' => $programModel,
        'partner/sponsorship' => $sponsorshipModel,
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->afterRevocation([
        'link_id' => 10,
        'program_id' => 1,
        'program_plan_id' => 5,
        'billed_at' => null,
    ], 99);

    expect($billing->decrementCalls)->toBe([])
        ->and($linkModel->callsTo('markLinkUnbilled'))->toBe([])
        ->and($programModel->callsTo('getProgramById'))->toBe([]);
});

/**
 * Prerequisites:
 * - Billed link; decrement stub returns ok.
 *
 * Steps:
 * 1. Call afterRevocation with billed_at set.
 * 2. Assert decrement ran once and markLinkUnbilled cleared the link.
 */
test('Partner — afterRevocation clears billed_at only after a successful decrement', function () {
    $program = ['program_id' => 1, 'company_id' => 20, 'stripe_subscription_id' => 'sub_x'];
    $plan = ['program_plan_id' => 5, 'uuid' => 'plan-uuid', 'currency' => 'EUR'];
    $linkModel = new PartnerBillingModelStub();
    $programModel = new PartnerBillingModelStub(['getProgramById' => $program]);
    $sponsorshipModel = new PartnerBillingModelStub(['getPlanById' => $plan]);
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/program' => $programModel,
        'partner/sponsorship' => $sponsorshipModel,
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->decrementResult = ['ok' => true];
    $billing->afterRevocation([
        'link_id' => 10,
        'program_id' => 1,
        'program_plan_id' => 5,
        'billed_at' => '2026-08-01 12:00:00',
    ], 99);

    expect($billing->decrementCalls)->toHaveCount(1)
        ->and($billing->decrementCalls[0]['quantity'])->toBe(1)
        ->and($billing->decrementCalls[0]['actor'])->toBe(99)
        ->and($linkModel->callsTo('markLinkUnbilled')[0]['args'])->toBe([10]);
});

/**
 * Prerequisites:
 * - Billed link; decrement stub fails.
 *
 * Steps:
 * 1. Call afterRevocation.
 * 2. Assert decrement was attempted but markLinkUnbilled was not called.
 */
test('Partner — afterRevocation keeps billed_at when decrement fails', function () {
    $program = ['program_id' => 1, 'company_id' => 20];
    $plan = ['program_plan_id' => 5];
    $linkModel = new PartnerBillingModelStub();
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/program' => new PartnerBillingModelStub(['getProgramById' => $program]),
        'partner/sponsorship' => new PartnerBillingModelStub(['getPlanById' => $plan]),
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->decrementResult = ['ok' => false, 'error' => 'stripe_error'];
    $billing->afterRevocation([
        'link_id' => 10,
        'program_id' => 1,
        'program_plan_id' => 5,
        'billed_at' => '2026-08-01 12:00:00',
    ], 7);

    expect($billing->decrementCalls)->toHaveCount(1)
        ->and($linkModel->callsTo('markLinkUnbilled'))->toBe([]);
});

/**
 * Prerequisites:
 * - Program with two plan buckets; one plan missing; one empty link list.
 *
 * Steps:
 * 1. Call billInvitations.
 * 2. Assert empty buckets are skipped, missing plan returns false, success marks only billed ids.
 */
test('Partner — billInvitations skips empty buckets and marks links only after successful add', function () {
    $program = ['program_id' => 1, 'billing_mode' => 'per_invitation'];
    $plan = ['program_plan_id' => 5, 'stripe_price_id' => 'price_x'];
    $linkModel = new PartnerBillingModelStub();
    $sponsorshipModel = new PartnerBillingModelStub([
        'getPlanById' => static function (int $id) use ($plan) {
            return $id === 5 ? $plan : null;
        },
    ]);
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/sponsorship' => $sponsorshipModel,
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->addResult = [
        'ok' => true,
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-09-01 00:00:00',
    ];

    $results = $billing->billInvitations($program, [
        5 => [101, 102],
        6 => [201],
        7 => [],
    ], 55);

    expect($results)->toBe([5 => true, 6 => false])
        ->and($billing->addCalls)->toHaveCount(1)
        ->and($billing->addCalls[0]['quantity'])->toBe(2)
        ->and($billing->addCalls[0]['actor'])->toBe(55)
        ->and($linkModel->callsTo('markLinkBilled'))->toHaveCount(2)
        ->and($linkModel->callsTo('markLinkBilled')[0]['args'])->toBe([101])
        ->and($linkModel->callsTo('markLinkBilled')[1]['args'])->toBe([102]);
});

/**
 * Prerequisites:
 * - billInvitations when addQuantity fails.
 *
 * Steps:
 * 1. Call billInvitations with addResult ok=false.
 * 2. Assert plan result is false and no markLinkBilled calls.
 */
test('Partner — billInvitations does not mark links when quantity add fails', function () {
    $program = ['program_id' => 1];
    $plan = ['program_plan_id' => 5];
    $linkModel = new PartnerBillingModelStub();
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/sponsorship' => new PartnerBillingModelStub(['getPlanById' => $plan]),
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->addResult = ['ok' => false, 'error' => 'stripe_not_configured'];

    $results = $billing->billInvitations($program, [5 => [101]], 1);

    expect($results)->toBe([5 => false])
        ->and($linkModel->callsTo('markLinkBilled'))->toBe([]);
});

/**
 * Prerequisites:
 * - per_activation program; activated entry not yet billed.
 *
 * Steps:
 * 1. Call afterActivation with one entry.
 * 2. Assert addQuantity + markLinkBilled + mintGrants with period-keyed prefix.
 */
test('Partner — afterActivation bills per_activation links and mints period-keyed grants', function () {
    $program = [
        'program_id' => 1,
        'billing_mode' => 'per_activation',
        'stripe_subscription_id' => '',
    ];
    $plan = [
        'program_plan_id' => 5,
        'product_id' => 9,
        'billing_interval' => 'month',
        'stripe_price_id' => 'price_x',
        'stripe_subscription_item_id' => '',
    ];
    $linkModel = new PartnerBillingModelStub();
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/program' => new PartnerBillingModelStub(['getProgramById' => $program]),
        'partner/sponsorship' => new PartnerBillingModelStub(['getPlanById' => $plan]),
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);
    $billing->addResult = [
        'ok' => true,
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-09-01 00:00:00',
    ];

    $billing->afterActivation([
        [
            'link_id' => 10,
            'program_id' => 1,
            'program_plan_id' => 5,
            'customer_role_id' => 30,
            'sponsorship_id' => 42,
            'link' => ['billed_at' => null],
        ],
    ], 8);

    expect($billing->addCalls)->toHaveCount(1)
        ->and($linkModel->callsTo('markLinkBilled')[0]['args'])->toBe([10])
        ->and($billing->mintCalls)->toHaveCount(1)
        ->and($billing->mintCalls[0][0])->toBe('sponsorship:42:start:2026-08-01 00:00:00')
        ->and($billing->mintCalls[0][1])->toBe(30)
        ->and($billing->mintCalls[0][2])->toBe(9)
        ->and($billing->mintCalls[0][6])->toBe(42);
});

/**
 * Prerequisites:
 * - per_activation program; link already billed (idempotent period key path).
 *
 * Steps:
 * 1. Call afterActivation with billed_at set.
 * 2. Assert addQuantity is skipped; mintGrants still runs with synthesized period bounds.
 */
test('Partner — afterActivation skips quantity add when link already billed but still mints grants', function () {
    $program = [
        'program_id' => 1,
        'billing_mode' => 'per_activation',
        'stripe_subscription_id' => '',
    ];
    $plan = [
        'program_plan_id' => 5,
        'product_id' => 9,
        'billing_interval' => 'month',
        'stripe_price_id' => 'price_x',
    ];
    $linkModel = new PartnerBillingModelStub();
    [$registry] = partner_billing_registry([
        'partner/customer_link' => $linkModel,
        'partner/program' => new PartnerBillingModelStub(['getProgramById' => $program]),
        'partner/sponsorship' => new PartnerBillingModelStub(['getPlanById' => $plan]),
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);

    $billing->afterActivation([
        [
            'link_id' => 10,
            'program_id' => 1,
            'program_plan_id' => 5,
            'customer_role_id' => 30,
            'sponsorship_id' => 42,
            'link' => ['billed_at' => '2026-07-01 00:00:00'],
        ],
    ], 8);

    expect($billing->addCalls)->toBe([])
        ->and($linkModel->callsTo('markLinkBilled'))->toBe([])
        ->and($billing->mintCalls)->toHaveCount(1)
        ->and($billing->mintCalls[0][0])->toStartWith('sponsorship:42:start:')
        ->and($billing->mintCalls[0][3])->toBe('month');
});

/**
 * Prerequisites:
 * - per_invitation billing mode (quantity already billed at invite time).
 *
 * Steps:
 * 1. Call afterActivation.
 * 2. Assert no addQuantity; mintGrants still uses period-keyed prefix.
 */
test('Partner — afterActivation under per_invitation only mints grants', function () {
    $program = [
        'program_id' => 1,
        'billing_mode' => 'per_invitation',
        'stripe_subscription_id' => '',
    ];
    $plan = [
        'program_plan_id' => 5,
        'product_id' => 9,
        'billing_interval' => 'year',
        'stripe_price_id' => 'price_x',
    ];
    [$registry] = partner_billing_registry([
        'partner/customer_link' => new PartnerBillingModelStub(),
        'partner/program' => new PartnerBillingModelStub(['getProgramById' => $program]),
        'partner/sponsorship' => new PartnerBillingModelStub(['getPlanById' => $plan]),
    ]);
    $billing = new TestablePartnerSponsorshipBilling($registry);

    $billing->afterActivation([
        [
            'link_id' => 10,
            'program_id' => 1,
            'program_plan_id' => 5,
            'customer_role_id' => 30,
            'sponsorship_id' => 7,
            'link' => ['billed_at' => '2026-07-01 00:00:00'],
        ],
    ], 0);

    expect($billing->addCalls)->toBe([])
        ->and($billing->mintCalls)->toHaveCount(1)
        ->and($billing->mintCalls[0][0])->toStartWith('sponsorship:7:start:')
        ->and($billing->mintCalls[0][3])->toBe('year');
});
