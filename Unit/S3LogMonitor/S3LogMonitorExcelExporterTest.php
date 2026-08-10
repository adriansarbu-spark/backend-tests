<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tests_config.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorConfig.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorRule.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorReport.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorExcelExporter.php';

test('s3 log monitor excel exporter writes summary and details sheets', function () {
	$rulePass = new S3LogMonitorRule(array('base_path' => 'dr/hsm/hsmdr', 'window_days' => 7, 'label' => 'HSM DR'));
	$ruleFail = new S3LogMonitorRule(array('base_path' => 'prod/ejbca/ejbca01', 'window_days' => 1, 'label' => 'EJBCA Prod'));
	$rows = array(
		new S3LogMonitorResultRow($rulePass, 'pass', '', '2026-06-17', '2026-06-17T00:01:07+00:00'),
		new S3LogMonitorResultRow($ruleFail, 'fail', 'No log uploaded within the expected freshness window.', null),
	);
	$report = new S3LogMonitorReport();
	$checkedAt = new DateTimeImmutable('2026-06-17 07:00:00', new DateTimeZone('UTC'));
	$summary = $report->buildSummary($rows, $checkedAt);

	$outputDir = sys_get_temp_dir() . '/s3-log-monitor-test-' . bin2hex(random_bytes(4));
	$outputPath = $outputDir . '/report.xlsx';

	$exporter = new S3LogMonitorExcelExporter();
	$writtenPath = $exporter->exportToFile($summary, $rows, $outputPath);

	expect($writtenPath)->toBe($outputPath);
	expect(is_file($outputPath))->toBeTrue();

	$spreadsheet = $exporter->buildSpreadsheet($summary, $rows);
	expect($spreadsheet->getSheetCount())->toBe(2);
	expect($spreadsheet->getSheet(0)->getTitle())->toBe('Summary');
	expect($spreadsheet->getSheet(1)->getTitle())->toBe('Details');
	expect($spreadsheet->getSheet(1)->getCell('A2')->getValue())->toBe('EJBCA Prod');
	expect($spreadsheet->getSheet(1)->getCell('F2')->getValue())->toBe('FAIL');
	expect($spreadsheet->getSheet(1)->getCell('A3')->getValue())->toBe('HSM DR');
	expect($spreadsheet->getSheet(1)->getCell('F3')->getValue())->toBe('PASS');

	@unlink($outputPath);
	@rmdir($outputDir);
});

test('s3 log monitor excel exporter renders warning summary and severity ordering', function () {
	$rule = new S3LogMonitorRule(array('base_path' => 'logs/example', 'label' => 'Example'));
	$rows = array(
		new S3LogMonitorResultRow($rule, 'pass'),
		new S3LogMonitorResultRow($rule, 'warning', 'No recent logs'),
		new S3LogMonitorResultRow($rule, 'error', 'S3 unavailable'),
		new S3LogMonitorResultRow($rule, 'fail', 'Too old'),
	);
	$summary = (new S3LogMonitorReport())->buildSummary(
		$rows,
		new DateTimeImmutable('2026-07-21 07:00:00', new DateTimeZone('UTC'))
	);
	$spreadsheet = (new S3LogMonitorExcelExporter())->buildSpreadsheet($summary, $rows);
	$summarySheet = $spreadsheet->getSheet(0);
	$detailsSheet = $spreadsheet->getSheet(1);

	expect($summarySheet->getCell('B2')->getValue())->toBe('Failed');
	expect($summarySheet->getCell('E2')->getValue())->toBe(1);
	expect($detailsSheet->getCell('G1')->getValue())->toBe('Message');
	expect($detailsSheet->getCell('F2')->getValue())->toBe('FAIL');
	expect($detailsSheet->getCell('F3')->getValue())->toBe('ERROR');
	expect($detailsSheet->getCell('F4')->getValue())->toBe('WARNING');
	expect($detailsSheet->getCell('F5')->getValue())->toBe('PASS');
	expect($detailsSheet->getStyle('F3')->getFill()->getStartColor()->getARGB())
		->not->toBe($detailsSheet->getStyle('F4')->getFill()->getStartColor()->getARGB());
});
