<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/StripeWebhookTestDoubles.php';

beforeEach(function () {
    stripe_webhook_reset();
});

/**
 * Prerequisites:
 * - A non-POST request reaches the signature-only webhook controller.
 *
 * Steps:
 * 1. Invoke the public webhook entry with GET.
 * 2. Assert method_not_allowed and the 405 response header.
 * 3. Prove signature verification and billing models are untouched.
 */
test('Stripe webhook — method validation fails before signature verification or mutation', function () {
    [$controller, , $response, $load] = stripe_webhook_fixture(server: ['REQUEST_METHOD' => 'GET']);
    $controller->index();

    expect($response->json())->toBe(['error' => 'method_not_allowed'])
        ->and($response->headers)->toContain('HTTP/1.1 405 Method Not Allowed')
        ->and(\Stripe\Webhook::$calls)->toBe([])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Stripe rejects the raw webhook body or its signature.
 *
 * Steps:
 * 1. Queue the deterministic verifier exception.
 * 2. Invoke the webhook entry.
 * 3. Assert the stable public code and zero billing mutations.
 */
test('Stripe webhook — invalid payload and signature fail before billing mutation', function (
    Throwable $failure,
    string $expectedError,
) {
    \Stripe\Webhook::$queue = [$failure];
    [$controller, , $response, $load] = stripe_webhook_fixture();
    $controller->index();

    expect($response->json())->toBe(['error' => $expectedError])
        ->and($response->headers)->toContain('HTTP/1.1 400 Bad Request')
        ->and($load->loadedModels)->toBe([]);
})->with([
    'invalid JSON payload' => [new UnexpectedValueException('invalid payload'), 'invalid_payload'],
    'invalid Stripe signature' => [new \Stripe\Exception\SignatureVerificationException('invalid signature'), 'invalid_signature'],
]);

/**
 * Prerequisites:
 * - A verified but unhandled Stripe event reaches the controller.
 *
 * Steps:
 * 1. Return an unknown event from the verifier double.
 * 2. Invoke the webhook entry.
 * 3. Assert acknowledgement without loading or mutating billing state.
 */
test('Stripe webhook — unknown verified event is acknowledged without mutation', function () {
    \Stripe\Webhook::$queue = [(object) [
        'id' => 'evt_unknown',
        'type' => 'customer.unhandled',
        'data' => (object) ['object' => (object) []],
    ]];
    [$controller, , $response, $load] = stripe_webhook_fixture();
    $controller->index();

    expect($response->json())->toBe(['received' => true])
        ->and($load->loadedModels)->toBe([])
        ->and(\Stripe\Webhook::$calls)->toHaveCount(1);
});

/**
 * Prerequisites:
 * - A verified event fails inside its handler with provider-supplied prose.
 *
 * Steps:
 * 1. Route a subscription checkout to a failing provider retrieval.
 * 2. Invoke the webhook entry.
 * 3. Assert the response contains only handler_failed and no provider detail.
 */
test('Stripe webhook — handler failures return a sanitized stable error', function () {
    \Stripe\Webhook::$queue = [(object) [
        'type' => 'checkout.session.completed',
        'data' => (object) ['object' => (object) [
            'mode' => 'subscription',
            'subscription' => 'sub_missing',
            'metadata' => (object) ['company_id' => '20'],
        ]],
    ]];
    \Stripe\Subscription::$retrieveQueue = [new RuntimeException('provider detail must stay internal')];
    [$controller, , $response] = stripe_webhook_fixture();
    $controller->index();

    expect($response->json())->toBe(['error' => 'handler_failed'])
        ->and($response->output)->not->toContain('provider detail');
});
