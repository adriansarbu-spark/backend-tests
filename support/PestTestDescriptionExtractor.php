<?php

declare(strict_types=1);

use Pest\Support\Str;

/**
 * Scans Pest test() calls and docblocks; maps Pest evaluable keys like list-tests output.
 */
final class PestTestDescriptionExtractor
{
	/**
	 * @return array<string, array<string, string>> relative path tests/...php => [ __pest_evaluable_* => text ]
	 */
	public static function buildByFileMap(string $projectRoot): array
	{
		$testsDir = rtrim($projectRoot, '/') . '/tests';
		if (!is_dir($testsDir)) {
			return array();
		}

		$byFile = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
		);

		/** @var SplFileInfo $info */
		foreach ($iterator as $info) {
			if (!$info->isFile() || $info->getExtension() !== 'php') {
				continue;
			}
			$path = $info->getPathname();
			$rel = 'tests/' . ltrim(substr($path, strlen($testsDir)), '/');
			$map = self::extractDescriptionsFromPestFile(file_get_contents($path) ?: '');
			if ($map !== array()) {
				$byFile[$rel] = $map;
			}
		}

		ksort($byFile);

		return $byFile;
	}

	/**
	 * @return array<string, string>
	 */
	private static function extractDescriptionsFromPestFile(string $src): array
	{
		$out = array();
		$len = strlen($src);
		$offset = 0;

		while ($offset < $len) {
			$pos = strpos($src, 'test(', $offset);
			if ($pos === false) {
				break;
			}
			if ($pos > 0 && preg_match('/[a-zA-Z0-9_\\\\]/', $src[$pos - 1])) {
				$offset = $pos + 5;
				continue;
			}

			$p = $pos + 5;
			while ($p < $len && ctype_space($src[$p])) {
				$p++;
			}
			if ($p >= $len) {
				break;
			}
			$q = $src[$p];
			if ($q !== "'" && $q !== '"') {
				$offset = $pos + 5;
				continue;
			}
			$p++;
			$title = '';
			while ($p < $len) {
				if ($src[$p] === '\\' && $p + 1 < $len) {
					$title .= $src[$p] . $src[$p + 1];
					$p += 2;
					continue;
				}
				if ($src[$p] === $q) {
					break;
				}
				$title .= $src[$p];
				$p++;
			}
			if ($p >= $len || $src[$p] !== $q) {
				$offset = $pos + 5;
				continue;
			}

			$title = stripcslashes($title);
			$beforeTest = substr($src, 0, $pos);
			$doc = self::trailingDocblockBeforeTest($beforeTest);
			if ($doc !== null && $doc !== '') {
				$key = Str::evaluable($title);
				$out[$key] = $doc;
			}

			$offset = $p + 1;
		}

		return $out;
	}

	private static function trailingDocblockBeforeTest(string $beforeTest): ?string
	{
		if (!preg_match_all('/\/\*\*([\s\S]*?)\*\//', $beforeTest, $set, PREG_OFFSET_CAPTURE)) {
			return null;
		}
		$n = count($set[0]);
		for ($j = $n - 1; $j >= 0; $j--) {
			$full = $set[0][$j][0];
			$start = $set[0][$j][1];
			$end = $start + strlen($full);
			$gap = substr($beforeTest, $end);
			if (trim($gap) === '') {
				return self::cleanDocblockInner($set[1][$j][0]);
			}
		}

		return null;
	}

	private static function cleanDocblockInner(string $inner): string
	{
		$lines = preg_split('/\r\n|\r|\n/', $inner) ?: array();
		$clean = array();
		foreach ($lines as $line) {
			$clean[] = preg_replace('/^\s*\* ?/', '', $line);
		}

		return trim(implode("\n", $clean));
	}
}
