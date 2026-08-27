<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\FilterService;
use OCA\LogCheck\Service\FingerprintService;
use PHPUnit\Framework\TestCase;

class FilterServiceTest extends TestCase
{
	private FilterService $filter;

	protected function setUp(): void
	{
		$this->filter = new FilterService(new FingerprintService());
	}

	public function testMinLevelFiltersWarnWhenDefaultError(): void
	{
		$r = $this->filter->evaluate(['level' => 2, 'app' => 'files', 'message' => 'x'], ['min_level' => 3, 'mutes' => []]);
		self::assertFalse($r['matched']);
	}

	public function testSelfMuteLogcheck(): void
	{
		$r = $this->filter->evaluate(['level' => 3, 'app' => 'logcheck', 'message' => 'fail'], ['min_level' => 3, 'mutes' => []]);
		self::assertFalse($r['matched']);
		self::assertTrue($r['muted']);
	}

	public function testInvalidRegexFlagsSanitized(): void
	{
		// Dangerous/unknown flags (e.g. legacy /e) must be stripped; mute still matches with safe flags.
		$r = $this->filter->evaluate(
			['level' => 3, 'app' => 'dav', 'message' => 'NotFound here'],
			[
				'min_level' => 3,
				'mutes' => [['type' => 'regex', 'value' => 'NotFound', 'flags' => 'ie']],
			]
		);
		self::assertFalse($r['matched']);
		self::assertTrue($r['muted']);

		// Flags that sanitize to empty fall back to 'i'
		$r2 = $this->filter->evaluate(
			['level' => 3, 'app' => 'dav', 'message' => 'NotFound here'],
			[
				'min_level' => 3,
				'mutes' => [['type' => 'regex', 'value' => 'notfound', 'flags' => 'eee']],
			]
		);
		self::assertTrue($r2['muted']);
	}

	public function testAppMute(): void
	{
		$r = $this->filter->evaluate(
			['level' => 3, 'app' => 'files_external', 'message' => 'err'],
			['min_level' => 3, 'mutes' => [['type' => 'app', 'value' => 'files_external']]]
		);
		self::assertFalse($r['matched']);
		self::assertTrue($r['muted']);
	}

	public function testMatchedWhenAboveMinLevel(): void
	{
		$r = $this->filter->evaluate(
			['level' => 3, 'app' => 'files', 'message' => 'boom'],
			['min_level' => 3, 'mutes' => []]
		);
		self::assertTrue($r['matched']);
		self::assertFalse($r['muted']);
		self::assertSame('files', $r['app']);
	}

	public function testRegexMute(): void
	{
		$r = $this->filter->evaluate(
			['level' => 3, 'app' => 'dav', 'message' => 'Sabre\\DAV\\Exception\\NotFound'],
			['min_level' => 3, 'mutes' => [['type' => 'regex', 'value' => 'NotFound', 'flags' => 'i']]]
		);
		self::assertFalse($r['matched']);
		self::assertTrue($r['muted']);
	}
}
