<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/../../Support/ApiAuthHelper.php';

test('auth login returns access token for TEST_USER_1', function () {
    assertTestConfigKeysOrSkip([
        'AUTH_URL',
        'CLIENT_ID',
        'CLIENT_SECRET',
        'TEST_USER_1_EMAIL',
        'TEST_USER_1_PASSWORD',
    ]);

    $token = ApiAuthHelper::bearerTokenFor(
        resolvedTestConfigValue('TEST_USER_1_EMAIL'),
        resolvedTestConfigValue('TEST_USER_1_PASSWORD')
    );
    expect($token)->toStartWith('Bearer ');
});
