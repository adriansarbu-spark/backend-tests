<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

beforeEach(function () {
    $ctrl = buildDocumentsController();
    $this->parse = (new ReflectionMethod(ControllerPublicAPIV1Documents::class, 'parseCcNotificationEmails'))
        ->getClosure($ctrl);
    $this->split = (new ReflectionMethod(ControllerPublicAPIV1Documents::class, 'splitCcNotificationEmails'))
        ->getClosure($ctrl);
});

test('parseCcNotificationEmails returns null for null input', function () {
    expect(($this->parse)(null))->toBeNull();
});

test('parseCcNotificationEmails returns null for empty string', function () {
    expect(($this->parse)(''))->toBeNull();
});

test('parseCcNotificationEmails normalizes a single valid email', function () {
    expect(($this->parse)('User@Example.COM'))->toBe('user@example.com');
});

test('parseCcNotificationEmails deduplicates and lowercases semicolon-separated emails', function () {
    expect(($this->parse)('A@x.ro; B@y.ro; a@X.RO'))->toBe('a@x.ro;b@y.ro');
});

test('parseCcNotificationEmails accepts array of emails', function () {
    expect(($this->parse)(['one@a.com', 'two@b.com']))->toBe('one@a.com;two@b.com');
});

test('parseCcNotificationEmails returns false for invalid email', function () {
    expect(($this->parse)('not-an-email'))->toBeFalse();
});

test('parseCcNotificationEmails returns false for array with non-string entry', function () {
    expect(($this->parse)(['good@a.com', ['nested']]))->toBeFalse();
});

test('parseCcNotificationEmails returns false when exceeding max count', function () {
    $emails = [];
    for ($i = 1; $i <= 11; $i++) {
        $emails[] = "user{$i}@example.com";
    }
    expect(($this->parse)(implode(';', $emails)))->toBeFalse();
});

test('parseCcNotificationEmails returns false when exceeding max length', function () {
    $long = str_repeat('a', 1000) . '@example.com';
    expect(($this->parse)($long))->toBeFalse();
});

test('splitCcNotificationEmails returns empty array for null', function () {
    expect(($this->split)(null))->toBe([]);
});

test('splitCcNotificationEmails returns empty array for empty string', function () {
    expect(($this->split)(''))->toBe([]);
});

test('splitCcNotificationEmails splits semicolon-separated string', function () {
    expect(($this->split)('a@x.ro;b@y.ro'))->toBe(['a@x.ro', 'b@y.ro']);
});
