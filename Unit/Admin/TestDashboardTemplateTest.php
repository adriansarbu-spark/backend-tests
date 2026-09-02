<?php

declare(strict_types=1);

function admin_tests_twig(): string
{
    $templatePath = __DIR__ . '/../../../public/admin/view/template/tool/tests.twig';
    $template = file_get_contents($templatePath);
    expect($template)->not->toBeFalse();

    return (string) $template;
}

function admin_tests_language(): string
{
    $languagePath = __DIR__ . '/../../../public/admin/language/en-gb/tool/tests.php';
    $language = file_get_contents($languagePath);
    expect($language)->not->toBeFalse();

    return (string) $language;
}

test('admin tests dashboard template renders test history graph container', function () {
    $template = admin_tests_twig();
    $compact = (string) preg_replace('/\s+/', ' ', $template);

    expect($template)
        ->toContain('id="test-pass-history-graph"')
        ->toContain('id="test-pass-history-detail"')
        ->toContain('id="pass-history-range-toggle"')
        ->toContain('function initPassHistoryGraphDetailBindings(')
        ->toContain('__passHistoryGraphDetailLines')
        ->toContain('function applyPassHistoryRange(')
        ->toContain('PASS_HISTORY_RANGE_MS')
        ->toContain('PASS_HISTORY_MAX_X_DATE_LABELS')
        ->toContain('function buildPassHistoryXLabelIndices(')
        ->toContain('function historyPointDateLabel(')
        ->toContain('function renderPassHistoryGraph(')
        ->toContain('function summarizeHistoryPoint(')
        ->toContain('function computePassPercentageFromCounts(')
        ->toContain('function formatPassPercentageLabel(')
        ->toContain('function buildSkipMessageMap(')
        ->toContain('test-history-point-hit')
        ->toContain('test-history-point-group')
        ->toContain('test-skip-message')
        ->toContain('test_pass_history_json')
        ->and($compact)->toContain('applyPassHistoryRange();')
        ->and($template)->not->toContain('passPercentageDirect = Math.round(Number(item.pass_percentage)');
});

test('admin tests dashboard template renders run all button and run-all flow', function () {
    $template = admin_tests_twig();
    $language = admin_tests_language();
    $compact = (string) preg_replace('/\s+/', ' ', $template);

    expect($template)
        ->toContain('id="run-all"')
        ->toContain('button_run_all')
        ->toContain('function runAllRequest(')
        ->toContain('tool/tests/startAllRun')
        ->toContain("runRequest('all')")
        ->toContain('function clearRunBusy(')
        ->not->toContain('tool/tests/runBothNow')
        ->and($language)->toContain('$_[\'button_run_all\']')
        ->and($compact)
        ->toContain('pollTimers = { unit: null, feature: null, all: null }')
        ->toContain("status === 'partial_failed'")
        ->toContain("status === 'completed'")
        ->toContain("status === 'cancelled'")
        ->toContain("$('#run-all').on('click', function() { runAllRequest(); });");
});

test('admin tests dashboard skips file-level fallback when all targeted tests already passed', function () {
    $template = admin_tests_twig();

    expect($template)
        ->toContain('hasExplicitSkip')
        ->toContain('$fallbackRow')
        ->toContain('replace(/^[^\s]+::/');
});

test('admin tests dashboard template renders empty history state message', function () {
    $template = admin_tests_twig();
    $language = admin_tests_language();

    expect($template)
        ->toContain('textTestHistoryEmpty')
        ->toContain('textTestHistoryEmptyRange')
        ->toContain('text_test_history_detail_hint')
        ->and($language)
        ->toContain('$_[\'text_test_history_empty\']')
        ->toContain('$_[\'text_test_history_empty_range\']')
        ->toContain('$_[\'text_test_history_range_30d\']')
        ->toContain('$_[\'text_test_history_detail_hint\']');
});

test('admin tests dashboard template renders history export controls', function () {
    $template = admin_tests_twig();
    $language = admin_tests_language();

    expect($template)
        ->toContain('id="history-export-start"')
        ->toContain('id="history-export-end"')
        ->toContain('id="history-export-btn"')
        ->toContain('id="history-export-message"')
        ->toContain('function showHistoryExportMessage(')
        ->toContain('function normalizeJsonErrorMessage(')
        ->toContain('function exportTestPassHistory(')
        ->toContain('tool/tests/exportPassHistory')
        ->and($language)->toContain('$_[\'button_export\']');
});
