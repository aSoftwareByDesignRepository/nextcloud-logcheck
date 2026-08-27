<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\LogLineLevel;
use PHPUnit\Framework\TestCase;

final class LogLineLevelTest extends TestCase
{
	public function testNcLevelFromLineParsesNumericLevel(): void
	{
		self::assertSame(3, LogLineLevel::ncLevelFromLine('{"level":3,"message":"boom"}'));
		self::assertSame(0, LogLineLevel::ncLevelFromLine('{"level":0,"message":"dbg"}'));
		self::assertSame(4, LogLineLevel::ncLevelFromLine('{"level":4,"message":"fatal"}'));
	}

	public function testNcLevelFromLineParsesStringLevel(): void
	{
		self::assertSame(2, LogLineLevel::ncLevelFromLine('{"level":"warning","message":"x"}'));
		self::assertSame(3, LogLineLevel::ncLevelFromLine('{"level":"error","message":"x"}'));
	}

	public function testNcLevelFromLineReturnsUnknownForNonJson(): void
	{
		self::assertSame(-1, LogLineLevel::ncLevelFromLine('plain text'));
		self::assertSame(-1, LogLineLevel::ncLevelFromLine('{not json'));
		self::assertSame(-1, LogLineLevel::ncLevelFromLine(''));
	}

	public function testMinNcLevelForViewerChips(): void
	{
		self::assertNull(LogLineLevel::minNcLevelForViewer(LogLineLevel::VIEWER_ALL));
		self::assertSame(2, LogLineLevel::minNcLevelForViewer(LogLineLevel::VIEWER_WARN));
		self::assertSame(3, LogLineLevel::minNcLevelForViewer(LogLineLevel::VIEWER_ERROR));
		self::assertSame(4, LogLineLevel::minNcLevelForViewer(LogLineLevel::VIEWER_FATAL));
	}

	public function testLineMatchesViewer(): void
	{
		$error = '{"level":3,"message":"err"}';
		$warn = '{"level":2,"message":"warn"}';
		$info = '{"level":1,"message":"info"}';

		self::assertTrue(LogLineLevel::lineMatchesViewer($error, LogLineLevel::VIEWER_ALL));
		self::assertTrue(LogLineLevel::lineMatchesViewer($error, LogLineLevel::VIEWER_ERROR));
		self::assertFalse(LogLineLevel::lineMatchesViewer($warn, LogLineLevel::VIEWER_ERROR));
		self::assertTrue(LogLineLevel::lineMatchesViewer($warn, LogLineLevel::VIEWER_WARN));
		self::assertFalse(LogLineLevel::lineMatchesViewer($info, LogLineLevel::VIEWER_WARN));
		self::assertFalse(LogLineLevel::lineMatchesViewer('not json', LogLineLevel::VIEWER_WARN));
	}

	public function testClampViewerMinLevel(): void
	{
		self::assertSame(0, LogLineLevel::clampViewerMinLevel(-3));
		self::assertSame(0, LogLineLevel::clampViewerMinLevel(0));
		self::assertSame(5, LogLineLevel::clampViewerMinLevel(99));
	}
}
