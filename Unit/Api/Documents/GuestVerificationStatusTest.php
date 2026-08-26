<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

test('checkin_submitted maps to in_progress', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('checkin_submitted'))->toBe('in_progress');
});

test('checkin_declined maps to declined', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('checkin_declined'))->toBe('declined');
});

test('failed maps to failed', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('failed'))->toBe('failed');
});

test('idle maps to null', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('idle'))->toBeNull();
});

test('committed maps to null', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('committed'))->toBeNull();
});

test('signing maps to null', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('signing'))->toBeNull();
});

test('completed maps to null', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState('completed'))->toBeNull();
});

test('empty string maps to null', function () {
    expect(ControllerPublicAPIV1Documents::guestVerificationStatusFromState(''))->toBeNull();
});
