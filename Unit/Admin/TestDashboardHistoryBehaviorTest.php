<?php

declare(strict_types=1);

test('controller exposes all suite through async start endpoint', function () {
    $controllerPath = __DIR__ . '/../../../public/admin/controller/tool/tests.php';
    $controller = file_get_contents($controllerPath);

    expect($controller)->not->toBeFalse();
    expect($controller)->toContain('private const SUITE_ALL = \'all\';');
    expect($controller)->toContain('public function startAllRun()');
    expect($controller)->toContain('$this->startAsyncRun(self::SUITE_ALL);');
    expect($controller)->not->toContain('public function runBothNow()');
    expect($controller)->not->toContain('public function finalizeRunAllHistory()');
});

test('queue suite run accepts all and prepares split suite state', function () {
    $controllerPath = __DIR__ . '/../../../public/admin/controller/tool/tests.php';
    $controller = file_get_contents($controllerPath);

    expect($controller)->not->toBeFalse();
    expect($controller)->toContain('array(self::SUITE_UNIT, self::SUITE_FEATURE, self::SUITE_ALL)');
    expect($controller)->toContain('$meta[\'unit_targets\'] = $unitTargets;');
    expect($controller)->toContain('$meta[\'feature_targets\'] = $featureTargets;');
    expect($controller)->toContain('$state[\'feature\'] = array(');
    expect($controller)->toContain('$state[\'unit\'] = array(');
});

test('dashboard run-all calls async start-all endpoint', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);

    expect($template)->not->toBeFalse();
    expect($template)->toContain("url = 'index.php?route=tool/tests/startAllRun&user_token=' + encodeURIComponent(userToken);");
    expect($template)->not->toContain("url: 'index.php?route=tool/tests/runBothNow&user_token={{ user_token }}'");
    expect($template)->toContain("$('#run-all').on('click', function() {\n  runAllRequest();\n});");
    expect($template)->toContain("escapeHtml(fileNode.file) + '</code> ';");
    expect($template)->toContain('runRequest(\'all\');');
});

test('cli worker supports all suite switch branch', function () {
    $cliPath = __DIR__ . '/../../../public/admin/cli/run-tests-job.php';
    $cli = file_get_contents($cliPath);

    expect($cli)->not->toBeFalse();
    expect($cli)->toContain("switch (\$suiteType)");
    expect($cli)->toContain("case 'all':");
    expect($cli)->toContain("\$unitResult = runSuiteTargets(\$service, 'unit'");
    expect($cli)->toContain("\$featureResult = runSuiteTargets(\$service, 'feature'");
    $allCaseBlock = (string) strstr($cli, "case 'all':");
    expect(strpos($allCaseBlock, '$unitResult = runSuiteTargets($service, \'unit\'') < strpos($allCaseBlock, '$featureResult = runSuiteTargets($service, \'feature\''))->toBeTrue();
    expect($cli)->toContain("\$historyPayload = array(");
    expect($cli)->toContain("'suite' => 'ALL'");
    expect($cli)->toContain("array('passed', 'failed', 'partial_failed', 'completed', 'error', 'errored', 'cancelled')");
    expect($cli)->toContain('filterHistoryToLastDays($history, TestPassHistoryService::DASHBOARD_HISTORY_MAX_DAYS)');
});

test('cli worker writes incremental state after each target folder', function () {
    $cliPath = __DIR__ . '/../../../public/admin/cli/run-tests-job.php';
    $cli = file_get_contents($cliPath);

    expect($cli)->not->toBeFalse();
    expect($cli)->toContain('function persistRunningState(');
    expect($cli)->toContain('$onFolderComplete = null');
    expect($cli)->toContain('if (is_callable($onFolderComplete))');
    expect($cli)->toContain('$writeSingleSuiteProgress');
    expect($cli)->toContain('$onUnitFolderComplete');
    expect($cli)->toContain('$onFeatureFolderComplete');
    $allCaseBlock = (string) strstr($cli, "case 'all':");
    expect(strpos($allCaseBlock, '$onUnitFolderComplete') < strpos($allCaseBlock, '$onFeatureFolderComplete'))->toBeTrue();
    expect($cli)->toContain("'runs' => array_values(\$providerRuns)");
});

test('admin tests controller loads dashboard history window and trims run poll payload', function () {
    $controllerPath = __DIR__ . '/../../../public/admin/controller/tool/tests.php';
    $controller = file_get_contents($controllerPath);

    expect($controller)->not->toBeFalse();
    expect($controller)->toContain('loadHistoryForDashboard($historyWarning)');
    expect($controller)->toContain("\$state['test_pass_history'] = \$this->getTestPassHistoryService()->filterHistoryToLastDays(");
    expect($controller)->toContain('TestPassHistoryService::DASHBOARD_HISTORY_MAX_DAYS');
});

test('run-both reuses same suite execution helper as runUnitNow and runFeatureNow', function () {
    $controllerPath = __DIR__ . '/../../../public/admin/controller/tool/tests.php';
    $controller = file_get_contents($controllerPath);

    expect($controller)->not->toBeFalse();
    expect($controller)->toContain('$json = $this->buildSuiteRunResult($suiteType, $this->request->post, $errorMessage);');
    expect($controller)->toContain('private function buildSuiteRunResult($suiteType, $input, &$errorMessage = \'\')');
    expect($controller)->toContain('$runResult = $this->executeWithProvider($executor, $suiteType, $targetPath);');
});

test('controller resolves Pest suite from Tests\\Unit or Tests\\Feature prefix after optional P\\ strip', function () {
    $controllerPath = __DIR__ . '/../../../public/admin/controller/tool/tests.php';
    $controller = file_get_contents($controllerPath);

    expect($controller)->not->toBeFalse();
    expect($controller)->toContain("strncmp(\$className, 'P\\\\', 2) === 0");
    expect($controller)->toContain("strncmp(\$className, 'Tests\\\\Unit\\\\', strlen('Tests\\\\Unit\\\\')) === 0");
    expect($controller)->toContain("strncmp(\$className, 'Tests\\\\Feature\\\\', strlen('Tests\\\\Feature\\\\')) === 0");
    expect($controller)->toContain('$identifiers = array_values(array_unique($identifiers));');
    expect($controller)->toContain('array_unique(is_array($tests) ? $tests : array(), SORT_STRING)');
});
