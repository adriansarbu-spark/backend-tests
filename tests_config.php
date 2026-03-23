<?php

// Reuse the main application config so all directory constants (DIR_SYSTEM,
// DIR_APPLICATION, etc.) are defined exactly as in production.
require_once __DIR__ . '/../public/config.php';

// Load core engine classes used by controllers in tests.
require_once DIR_SYSTEM . 'engine/registry.php';
require_once DIR_SYSTEM . 'engine/controller.php';

// Path to the public API controllers used in tests (v1 public API).
define('PUBLIC_API', DIR_APPLICATION . 'controller/publicapi/v1/');

define('API_URL', API_SERVER . 'v1/');



define('SKIP_INTEGRATION_TESTS', false);


define('AUTH_URL', 'https://auth.dev.simplifi.ro/realms/Simplifi/protocol/openid-connect/token');

define('TEST_USER_1_EMAIL', 'alexandru.zamfir+test@simplifi.ro');
define('TEST_USER_1_PASSWORD', 'VCK8rfk-jec.kuy9fth');

define('TEST_USER_2_EMAIL', 'alexandru.zamfir+test2@simplifi.ro');
define('TEST_USER_2_PASSWORD', 'wbj2zrw5jec1rgy@HAW');


define('CLIENT_ID', 'qualys-scanner-client');
define('CLIENT_SECRET', 'WnvhDJnb1sQtXvWq5NQkP7wkkHECsiCx');