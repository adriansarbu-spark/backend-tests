<?php

declare(strict_types=1);

test('admin tests dashboard template renders test history graph container', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);

    expect($template)->not->toBeFalse();
    expect($template)->toContain('id="test-pass-history-graph"');
    expect($template)->toContain('id="pass-history-range-toggle"');
    expect($template)->toContain('function applyPassHistoryRange()');
    expect($template)->toContain('PASS_HISTORY_RANGE_MS');
    expect($template)->toContain('function renderPassHistoryGraph(history, emptyMessage)');
    expect($template)->toContain('function summarizeHistoryPoint(item)');
    expect($template)->toContain('.test-history-point-hit');
    expect($template)->toContain("cursor: pointer;");
    expect($template)->toContain('.test-history-point.is-hovered');
    expect($template)->toContain('<polyline fill="none" stroke="#337ab7" stroke-width="2" points="');
    expect($template)->toContain("class=\"test-history-point-hit\"");
    expect($template)->toContain("class=\"test-history-point\"");
    expect($template)->toContain("$hit.on('mouseenter focus'");
    expect($template)->toContain("$hit.on('mouseleave blur'");
    expect($template)->toContain("var tooltip = 'Date: ' + formatHistoryTimestamp(item.timestamp)");
    expect($template)->toContain("Total tests: ' + summary.total");
    expect($template)->toContain("Pass percentage: ' + summary.passPercentage + '%';");
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

test('admin tests dashboard template renders empty history state message', function () {
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);
    $languagePath = __DIR__ . '/../../../public/admin/language/en-gb/tool/tests.php';
    $language = file_get_contents($languagePath);

    expect($template)->not->toBeFalse();
    expect($language)->not->toBeFalse();
    expect($template)->toContain("window.textTestHistoryEmpty = '{{ text_test_history_empty|e('js') }}';");
    expect($template)->toContain("window.textTestHistoryEmptyRange = '{{ text_test_history_empty_range|e('js') }}';");
    expect($language)->toContain('$_[\'text_test_history_empty\'] = \'No test history available yet.\';');
    expect($language)->toContain('$_[\'text_test_history_empty_range\']');
    expect($language)->toContain('$_[\'text_test_history_range_30d\']');
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
