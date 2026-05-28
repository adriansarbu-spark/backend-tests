<?php

declare(strict_types=1);

test('admin tests dashboard template renders test history graph container', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);

    expect($template)->not->toBeFalse();
    expect($template)->toContain('id="test-pass-history-graph"');
    expect($template)->toContain('id="test-pass-history-detail"');
    expect($template)->toContain('function initPassHistoryGraphDetailBindings()');
    expect($template)->toContain('__passHistoryGraphDetailLines');
    expect($template)->toContain('id="pass-history-range-toggle"');
    expect($template)->toContain('function applyPassHistoryRange()');
    expect($template)->toContain('PASS_HISTORY_RANGE_MS');
    expect($template)->toContain('PASS_HISTORY_MAX_X_DATE_LABELS = 5');
    expect($template)->toContain('function buildPassHistoryXLabelIndices(points)');
    expect($template)->toContain('function historyPointDateLabel(item)');
    expect($template)->toContain('var xLabels = buildPassHistoryXLabelIndices(points);');
    expect($template)->toContain('function renderPassHistoryGraph(history, emptyMessage)');
    expect($template)->toContain('function summarizeHistoryPoint(item)');
    expect($template)->toContain('.test-history-point-hit');
    expect($template)->toContain("cursor: pointer;");
    expect($template)->toContain('.test-history-point-group:hover .test-history-point');
    expect($template)->toContain('.test-history-point-group:focus-within .test-history-point');
    expect($template)->toContain('<polyline fill="none" stroke="#337ab7" stroke-width="2" points="');
    expect($template)->toContain("class=\"test-history-point-hit\"");
    expect($template)->toContain("class=\"test-history-point\"");
    expect($template)->toContain('tabindex="0"');
    expect($template)->toContain("window.__passHistoryGraphDetailLines = points.map(function(item) {");
    expect($template)->toContain("return 'Date: ' + formatHistoryTimestamp(item.timestamp)");
    expect($template)->toContain('+ \'\\nTotal tests: \' + summary.total');
    expect($template)->toContain('+ \'\\nPass percentage: \' + summary.passPercentage + \'%\';');
    expect($template)->toContain('window.testPassHistory = {{ test_pass_history_json|raw }};');
    expect($template)->toContain('applyPassHistoryRange();');
});

test('admin tests dashboard template renders run all button and run-all flow', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);
    $languagePath = __DIR__ . '/../../../public/admin/language/en-gb/tool/tests.php';
    $language = file_get_contents($languagePath);

    expect($template)->not->toBeFalse();
    expect($language)->not->toBeFalse();
    expect($template)->toContain('id="run-all"');
    expect($template)->toContain('{{ button_run_all }}');
    expect($language)->toContain('$_[\'button_run_all\'] = \'Run All\';');
    expect($template)->toContain('function runAllRequest()');
    expect($template)->toContain("url = 'index.php?route=tool/tests/startAllRun&user_token=' + encodeURIComponent(userToken);");
    expect($template)->not->toContain("url: 'index.php?route=tool/tests/runBothNow&user_token={{ user_token }}'");
    expect($template)->toContain('runRequest(\'all\');');
    expect($template)->toContain("var pollTimers = { unit: null, feature: null, all: null };");
    expect($template)->toContain("status === 'partial_failed'");
    expect($template)->toContain("status === 'completed'");
    expect($template)->toContain("status === 'cancelled'");
    expect($template)->toContain("function clearRunBusy(type)");
    expect($template)->toContain("clearRunBusy(type);");
    expect($template)->toContain("$('#run-all').on('click', function() {\n  runAllRequest();\n});");
});

test('admin tests dashboard skips file-level fallback when all targeted tests already passed', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);

    expect($template)->not->toBeFalse();
    expect($template)->toContain('var hasExplicitSkip = false');
    expect($template)->toContain('if (!$fallbackRow)');
    expect($template)->toContain("normalized.replace(/^[^\\s]+::/, '')");
});

test('admin tests dashboard template renders empty history state message', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);
    $languagePath = __DIR__ . '/../../../public/admin/language/en-gb/tool/tests.php';
    $language = file_get_contents($languagePath);

    expect($template)->not->toBeFalse();
    expect($language)->not->toBeFalse();
    expect($template)->toContain("window.textTestHistoryEmpty = '{{ text_test_history_empty|e('js') }}';");
    expect($template)->toContain("window.textTestHistoryEmptyRange = '{{ text_test_history_empty_range|e('js') }}';");
    expect($template)->toContain('{{ text_test_history_detail_hint }}');
    expect($language)->toContain('$_[\'text_test_history_empty\'] = \'No test history available yet.\';');
    expect($language)->toContain('$_[\'text_test_history_empty_range\']');
    expect($language)->toContain('$_[\'text_test_history_range_30d\']');
    expect($language)->toContain('$_[\'text_test_history_detail_hint\']');
});

test('admin tests dashboard template renders history export controls', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);
    $languagePath = __DIR__ . '/../../../public/admin/language/en-gb/tool/tests.php';
    $language = file_get_contents($languagePath);

    expect($template)->not->toBeFalse();
    expect($language)->not->toBeFalse();
    expect($template)->toContain('id="history-export-start"');
    expect($template)->toContain('id="history-export-end"');
    expect($template)->toContain('id="history-export-btn"');
    expect($template)->toContain('id="history-export-message"');
    expect($template)->toContain('function showHistoryExportMessage(message)');
    expect($template)->toContain('function normalizeJsonErrorMessage(json)');
    expect($template)->toContain('function exportTestPassHistory()');
    expect($template)->toContain("url: 'index.php?route=tool/tests/exportPassHistory&user_token=' + encodeURIComponent(userToken),");
    expect($language)->toContain('$_[\'button_export\'] = \'Export\';');
});
