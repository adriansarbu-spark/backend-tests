<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once __DIR__ . '/../../Support/ApiAuthHelper.php';

test('auth login returns access token for TEST_USER_1', function () {
    $required = [
        'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
        'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
        'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
        'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
        'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
    ];

    foreach ($required as $key => $value) {
        if (!is_string($value) || trim($value) === '') {
            $this->markTestSkipped("Missing required test config constant: {$key}");
        }
    }

    $token = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    expect($token)->toStartWith('Bearer ');
});

