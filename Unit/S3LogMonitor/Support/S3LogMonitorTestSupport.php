<?php

declare(strict_types=1);

require_once DIR_SYSTEM . 'library/s3logmonitor/S3ObjectListerInterface.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/RuleProviderInterface.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorResultRow.php';
require_once DIR_SYSTEM . 'library/s3logmonitor/S3LogMonitorEmailNotifier.php';

class FakeS3ObjectLister implements S3ObjectListerInterface {
	/** @var array<string,array<string,mixed>> */
	private $responses;

	/** @var array<int,array<string,mixed>> */
	public $calls = array();

	/**
	 * @param array<string,array<string,mixed>> $responses prefix => listObjectsV2 response
	 */
	public function __construct(array $responses) {
		$this->responses = $responses;
	}

	public function listObjectsV2(array $params) {
		$this->calls[] = $params;
		$prefix = isset($params['Prefix']) ? (string)$params['Prefix'] : '';

		if (!isset($this->responses[$prefix])) {
			return array('Contents' => array());
		}

		return $this->responses[$prefix];
	}
}

/**
 * In-memory DB stand-in for S3 log monitor unit tests (no real inserts).
 */
class S3LogMonitorFakeDb {
	/** @var array<int,string> */
	public $queries = array();

	/** @var bool */
	private $foldersTableExists = true;

	/** @var bool */
	private $runsTableExists = true;

	/** @var array<int,array<string,mixed>> */
	private $folderRows = array();

	public function __construct(array $folderRows = array(), $foldersTableExists = true, $runsTableExists = true) {
		$this->folderRows = $folderRows;
		$this->foldersTableExists = (bool)$foldersTableExists;
		$this->runsTableExists = (bool)$runsTableExists;
	}

	public function query($sql) {
		$this->queries[] = (string)$sql;

		if (strpos($sql, "SHOW TABLES LIKE 's3_log_monitor_folders'") !== false) {
			return $this->result($this->foldersTableExists ? 1 : 0);
		}
		if (strpos($sql, "SHOW TABLES LIKE 's3_log_monitor_runs'") !== false) {
			return $this->result($this->runsTableExists ? 1 : 0);
		}
		if (strpos($sql, 'FROM `s3_log_monitor_folders`') !== false && strpos($sql, 'SELECT *') !== false) {
			return (object)array(
				'num_rows' => count($this->folderRows),
				'rows'     => $this->folderRows,
				'row'      => $this->folderRows[0] ?? array(),
			);
		}

		return (object)array(
			'num_rows' => 0,
			'rows'     => array(),
			'row'      => array(),
		);
	}

	public function escape($value) {
		return str_replace("'", "''", (string)$value);
	}

	public function getLastId() {
		return 99;
	}

	public function hasInsertQuery() {
		foreach ($this->queries as $sql) {
			if (stripos($sql, 'INSERT INTO') !== false) {
				return true;
			}
		}

		return false;
	}

	private function result($numRows) {
		return (object)array(
			'num_rows' => (int)$numRows,
			'rows'     => array(),
			'row'      => array(),
		);
	}
}

class S3LogMonitorFakeRegistry {
	/** @var S3LogMonitorFakeDb */
	private $db;

	public function __construct(S3LogMonitorFakeDb $db) {
		$this->db = $db;
	}

	public function get($key) {
		if ($key === 'db') {
			return $this->db;
		}

		return null;
	}
}

class S3LogMonitorStaticRuleProvider implements RuleProviderInterface {
	/** @var S3LogMonitorRule[] */
	private $rules;

	/**
	 * @param S3LogMonitorRule[] $rules
	 */
	public function __construct(array $rules) {
		$this->rules = $rules;
	}

	public function getRules() {
		return $this->rules;
	}
}

class S3LogMonitorRecordingRunStore {
	/** @var bool */
	public $started = false;

	/** @var string */
	public $startEmail = '';

	/** @var int */
	public $runId = 42;

	/** @var array<int,array<string,mixed>> */
	public $checks = array();

	/** @var bool */
	public $finished = false;

	/** @var bool */
	public $emailSent = false;

	public function tablesExist() {
		return true;
	}

	public function startRun($email) {
		$this->started = true;
		$this->startEmail = (string)$email;

		return $this->runId;
	}

	public function insertCheck($runId, S3LogMonitorResultRow $row, $checkedAt = null) {
		$this->checks[] = array(
			'run_id'           => (int)$runId,
			'folder_id'        => (int)$row->rule->folder_id,
			'status'           => $row->status,
			'allowed_days'     => (int)$row->rule->allowed_days,
			'last_upload_date' => $row->last_upload_date,
		);
	}

	public function finishRun($runId, array $summary, $emailSent) {
		$this->finished = true;
		$this->emailSent = (bool)$emailSent;
	}
}

class S3LogMonitorRecordingEmailNotifier extends S3LogMonitorEmailNotifier {
	/** @var string[] */
	public $recipients = array();

	public function notify($registry, $recipientEmail, $languageId, array $summary, array $rows) {
		$this->recipients[] = (string)$recipientEmail;

		return true;
	}
}

function s3LogMonitorResetConfigCache() {
	$reflection = new ReflectionClass(S3LogMonitorConfig::class);
	foreach (array('values', 'secretsLoaded') as $propertyName) {
		if (!$reflection->hasProperty($propertyName)) {
			continue;
		}
		$property = $reflection->getProperty($propertyName);
		$property->setAccessible(true);
		$property->setValue(null, $propertyName === 'values' ? null : false);
	}
}

function s3LogMonitorSetConfigValues(array $values) {
	s3LogMonitorResetConfigCache();
	$reflection = new ReflectionClass(S3LogMonitorConfig::class);
	$property = $reflection->getProperty('values');
	$property->setAccessible(true);
	$property->setValue(null, $values);
}
