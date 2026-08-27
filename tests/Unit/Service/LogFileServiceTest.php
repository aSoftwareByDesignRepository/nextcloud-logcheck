<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Service\AuditService;
use OCA\LogCheck\Service\CursorStore;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\LogBackendService;
use OCA\LogCheck\Service\LogFileService;
use OCA\LogCheck\Service\LogLineLevel;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LogFileServiceTest extends TestCase
{
	private string $dir;
	private string $logPath;
	/** @var IConfig&MockObject */
	private IConfig $config;
	/** @var LogBackendService&MockObject */
	private LogBackendService $backend;
	/** @var CursorStore&MockObject */
	private CursorStore $cursor;
	/** @var LeaseService&MockObject */
	private LeaseService $lease;
	/** @var AccessService&MockObject */
	private AccessService $access;
	/** @var AuditService&MockObject */
	private AuditService $audit;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/lck-log-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		$this->logPath = $this->dir . '/nextcloud.log';
		file_put_contents($this->logPath, "alpha one\nbeta two\ngamma three\nsecret token=abc\n");

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturnCallback(function (string $key, $default = '') {
			return match ($key) {
				'datadirectory' => $this->dir,
				'logfile' => $this->logPath,
				'log_type' => 'file',
				default => $default,
			};
		});
		$this->backend = $this->createMock(LogBackendService::class);
		$this->backend->method('assertFileBackend');
		$this->backend->method('resolveLogPath')->willReturnCallback(fn () => $this->logPath);
		$this->cursor = $this->createMock(CursorStore::class);
		$this->lease = $this->createMock(LeaseService::class);
		$this->access = $this->createMock(AccessService::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function svc(): LogFileService
	{
		return new LogFileService($this->config, $this->backend, $this->cursor, $this->lease, $this->access, $this->audit);
	}

	public function testMetaHidesPathForNonAdmin(): void
	{
		$meta = $this->svc()->meta(false);
		self::assertTrue($meta['available']);
		self::assertSame('nextcloud.log', $meta['name']);
		self::assertNull($meta['path']);
		self::assertGreaterThan(0, $meta['size']);
	}

	public function testMetaRevealsPathForNcAdmin(): void
	{
		$meta = $this->svc()->meta(true);
		self::assertSame($this->logPath, $meta['path']);
	}

	public function testReadTailReturnsNewestLines(): void
	{
		$out = $this->svc()->readTail(4096, 10);
		$texts = array_column($out['lines'], 'text');
		self::assertContains('alpha one', $texts);
		self::assertContains('secret token=abc', $texts);
		self::assertFalse($out['truncated']);
	}

	public function testReadTailRespectsMaxLines(): void
	{
		$out = $this->svc()->readTail(4096, 2);
		self::assertCount(2, $out['lines']);
		self::assertTrue($out['truncated']);
		self::assertSame('gamma three', $out['lines'][0]['text']);
		self::assertSame('secret token=abc', $out['lines'][1]['text']);
	}

	public function testReadTailFiltersByViewerMinLevel(): void
	{
		$lines = [
			'{"level":1,"message":"info","app":"core"}',
			'{"level":2,"message":"warn","app":"core"}',
			'{"level":3,"message":"error","app":"core"}',
			'{"level":4,"message":"fatal","app":"core"}',
		];
		file_put_contents($this->logPath, implode("\n", $lines) . "\n");

		$out = $this->svc()->readTail(4096, 500, null, LogLineLevel::VIEWER_ERROR);
		$texts = array_column($out['lines'], 'text');
		self::assertTrue($out['filter_active']);
		self::assertSame(LogLineLevel::VIEWER_ERROR, $out['viewer_min_level']);
		self::assertCount(2, $texts);
		self::assertStringContainsString('"error"', $texts[0]);
		self::assertStringContainsString('"fatal"', $texts[1]);
	}

	public function testReadBeforeFiltersByViewerMinLevel(): void
	{
		$chunk = [];
		for ($i = 0; $i < 40; $i++) {
			$chunk[] = json_encode(['level' => 1, 'message' => 'info-' . $i, 'app' => 'core'], JSON_THROW_ON_ERROR);
		}
		$chunk[] = '{"level":3,"message":"error-tail","app":"core"}';
		file_put_contents($this->logPath, implode("\n", $chunk) . "\n");

		$tail = $this->svc()->readTail(2048, 5, null, LogLineLevel::VIEWER_ALL);
		self::assertNotEmpty($tail['lines']);

		$before = $this->svc()->readBefore($tail['from_offset'], 2048, 50, null, LogLineLevel::VIEWER_ERROR);
		foreach ($before['lines'] as $line) {
			$level = LogLineLevel::ncLevelFromLine($line['text']);
			self::assertGreaterThanOrEqual(3, $level);
		}
		self::assertTrue($before['filter_active']);
	}

	public function testSearchFiltersMatchesByViewerMinLevel(): void
	{
		file_put_contents($this->logPath, implode("\n", [
			'{"level":2,"message":"needle-warn","app":"core"}',
			'{"level":3,"message":"needle-error","app":"core"}',
		]) . "\n");

		$out = $this->svc()->search('needle', false, 10, 65536, null, null, LogLineLevel::VIEWER_ERROR);
		self::assertCount(1, $out['matches']);
		self::assertStringContainsString('needle-error', $out['matches'][0]['text']);
		self::assertTrue($out['filter_active']);
		self::assertSame(1, $out['lines_matched']);
	}

	public function testReadBeforeReturnsEarlierWindow(): void
	{
		// Floor for maxBytes is 1024 — build a file larger than one window.
		$lines = [];
		for ($i = 0; $i < 200; $i++) {
			$lines[] = sprintf('line-%03d-%s', $i, str_repeat('x', 40));
		}
		file_put_contents($this->logPath, implode("\n", $lines) . "\n");

		$tail = $this->svc()->readTail(1024, 8);
		self::assertTrue($tail['truncated']);
		self::assertGreaterThan(0, $tail['from_offset']);

		$before = $this->svc()->readBefore($tail['from_offset'], 1024, 8);
		self::assertNotEmpty($before['lines']);
		self::assertLessThanOrEqual($tail['from_offset'], $before['to_offset']);
		$tailTexts = array_column($tail['lines'], 'text');
		$beforeTexts = array_column($before['lines'], 'text');
		self::assertNotContains($tailTexts[0], $beforeTexts);
	}

	public function testResolveDownloadAllowsSmallFile(): void
	{
		$this->access->method('isNcAdmin')->with('admin')->willReturn(true);
		$this->audit->expects(self::once())->method('log')->with('admin', 'log_downloaded', self::callback(static function (array $d): bool {
			return ($d['name'] ?? '') === 'nextcloud.log' && isset($d['size']);
		}));
		$out = $this->svc()->resolveDownload(null, 'admin');
		self::assertArrayHasKey('path', $out);
		self::assertSame(basename($this->logPath), $out['name']);
	}

	public function testResolveDownloadForbiddenForAppAdmin(): void
	{
		$this->access->method('isNcAdmin')->with('bob')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->svc()->resolveDownload(null, 'bob');
	}

	public function testSearchLiteralCaseInsensitive(): void
	{
		$out = $this->svc()->search('BETA', false);
		self::assertCount(1, $out['matches']);
		self::assertSame('beta two', $out['matches'][0]['text']);
	}

	public function testSearchRejectsEmptyNeedle(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc()->search('   ');
	}

	public function testSearchRejectsOverlongNeedle(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc()->search(str_repeat('a', LogFileService::NEEDLE_MAX_LEN + 1));
	}

	public function testStartFreshRequiresNcAdmin(): void
	{
		$this->access->method('isNcAdmin')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->svc()->startFresh('bob', LogFileService::CONFIRM_START_FRESH);
	}

	public function testStartFreshRequiresConfirmWord(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->expectException(ValidationException::class);
		$this->svc()->startFresh('admin', 'please');
	}

	public function testStartFreshRenamesAndReseedsCursor(): void
	{
		$this->access->method('isNcAdmin')->with('admin')->willReturn(true);
		$this->lease->expects(self::once())->method('acquire')->willReturn(true);
		$this->lease->expects(self::once())->method('release');
		$this->cursor->expects(self::once())->method('initializeAtEof')->with($this->logPath);
		$this->audit->expects(self::once())->method('log')->with('admin', 'log_start_fresh', self::callback(static function (array $d): bool {
			return isset($d['live']) && $d['live'] === 'nextcloud.log' && isset($d['archive']);
		}));

		$result = $this->svc()->startFresh('admin', LogFileService::CONFIRM_START_FRESH);
		self::assertTrue($result['ok']);
		self::assertNotSame('', $result['archive_name']);
		self::assertFileExists($this->dir . '/' . $result['archive_name']);
		self::assertFileExists($this->logPath);
		self::assertSame(0, filesize($this->logPath));
		self::assertStringContainsString('alpha one', (string)file_get_contents($this->dir . '/' . $result['archive_name']));
	}

	public function testStartFreshBusyWhenLeaseHeld(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->lease->method('acquire')->willReturn(false);
		try {
			$this->svc()->startFresh('admin', LogFileService::CONFIRM_START_FRESH);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('LCK_BUSY', $e->getErrorCode());
		}
		self::assertFileExists($this->logPath);
		self::assertGreaterThan(0, filesize($this->logPath));
	}

	public function testDeleteRequiresConfirmAndResetsCursor(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->lease->method('acquire')->willReturn(true);
		$this->cursor->expects(self::once())->method('upsert')->with(self::callback(function (array $c): bool {
			return $c['path'] === $this->logPath && $c['offset'] === 0 && $c['size'] === 0;
		}));
		$this->audit->expects(self::once())->method('log')->with('admin', 'log_deleted', ['name' => 'nextcloud.log']);

		$result = $this->svc()->deleteLog('admin', LogFileService::CONFIRM_DELETE);
		self::assertTrue($result['ok']);
		self::assertFileDoesNotExist($this->logPath);
	}

	public function testDeleteForbiddenForAppAdmin(): void
	{
		$this->access->method('isNcAdmin')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->svc()->deleteLog('appadmin', LogFileService::CONFIRM_DELETE);
	}

	public function testClipsVeryLongLines(): void
	{
		$long = str_repeat('X', LogFileService::LINE_CLIP_CHARS + 50);
		file_put_contents($this->logPath, $long . "\n");
		$out = $this->svc()->readTail();
		self::assertTrue($out['lines'][0]['clipped']);
		self::assertLessThanOrEqual(LogFileService::LINE_CLIP_CHARS + 3, strlen($out['lines'][0]['text']));
	}

	public function testListFilesIncludesRotatedAndArchiveSiblings(): void
	{
		file_put_contents($this->dir . '/nextcloud.log.1', "old one\n");
		$archive = 'nextcloud.log.lck-rotated-20260826-120000';
		file_put_contents($this->dir . '/' . $archive, "archived\n");
		file_put_contents($this->dir . '/evil.log', "nope\n");
		file_put_contents($this->dir . '/nextcloud.log.99', "too high\n");
		file_put_contents($this->dir . '/audit.log', "other\n");

		$list = $this->svc()->listFiles();
		$ids = array_column($list['files'], 'id');
		self::assertSame('nextcloud.log', $list['live']);
		self::assertContains('nextcloud.log', $ids);
		self::assertContains('nextcloud.log.1', $ids);
		self::assertContains($archive, $ids);
		self::assertNotContains('evil.log', $ids);
		self::assertNotContains('nextcloud.log.99', $ids);
		self::assertNotContains('audit.log', $ids);
		self::assertTrue($list['files'][0]['is_live']);
	}

	public function testReadTailOfRotatedSibling(): void
	{
		file_put_contents($this->dir . '/nextcloud.log.1', "rotated-alpha\nrotated-beta\n");
		$out = $this->svc()->readTail(4096, 10, 'nextcloud.log.1');
		$texts = array_column($out['lines'], 'text');
		self::assertContains('rotated-alpha', $texts);
		self::assertFalse($out['is_live']);
		self::assertSame('rotated', $out['role']);
		self::assertSame('nextcloud.log.1', $out['file']);
	}

	public function testReadTailRejectsPathTraversalFileId(): void
	{
		$this->expectException(ForbiddenException::class);
		$this->svc()->readTail(4096, 10, '../evil.log');
	}

	public function testReadTailRejectsUnrelatedBasename(): void
	{
		file_put_contents($this->dir . '/evil.log', "secret\n");
		$this->expectException(ForbiddenException::class);
		$this->svc()->readTail(4096, 10, 'evil.log');
	}

	public function testSearchOnArchiveDoesNotTouchCursor(): void
	{
		$archive = 'nextcloud.log.lck-rotated-20260826-130000';
		file_put_contents($this->dir . '/' . $archive, "find-me-please\n");
		$this->cursor->expects(self::never())->method('upsert');
		$this->cursor->expects(self::never())->method('initializeAtEof');
		$out = $this->svc()->search('find-me', false, 10, 65536, $archive);
		self::assertCount(1, $out['matches']);
		self::assertFalse($out['is_live']);
	}

	public function testDeleteCopyRemovesArchiveWithoutCursorWrite(): void
	{
		$archive = 'nextcloud.log.lck-rotated-20260826-140000';
		$path = $this->dir . '/' . $archive;
		file_put_contents($path, "old\n");
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->cursor->expects(self::never())->method('upsert');
		$this->cursor->expects(self::never())->method('initializeAtEof');
		$this->lease->expects(self::never())->method('acquire');
		$this->audit->expects(self::once())->method('log')->with('admin', 'log_copy_deleted', self::callback(static function (array $d) use ($archive): bool {
			return ($d['name'] ?? '') === $archive && ($d['role'] ?? '') === 'archive';
		}));

		$result = $this->svc()->deleteCopy('admin', LogFileService::CONFIRM_DELETE_COPY, $archive);
		self::assertTrue($result['ok']);
		self::assertFileDoesNotExist($path);
		self::assertFileExists($this->logPath);
	}

	public function testDeleteCopyRejectsLiveFile(): void
	{
		$this->access->method('isNcAdmin')->willReturn(true);
		$this->expectException(ValidationException::class);
		$this->svc()->deleteCopy('admin', LogFileService::CONFIRM_DELETE_COPY, 'nextcloud.log');
	}

	public function testDeleteCopyRequiresNcAdmin(): void
	{
		$archive = 'nextcloud.log.lck-rotated-20260826-150000';
		file_put_contents($this->dir . '/' . $archive, "old\n");
		$this->access->method('isNcAdmin')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->svc()->deleteCopy('bob', LogFileService::CONFIRM_DELETE_COPY, $archive);
	}

	public function testAllowlistHelperAcceptsBoundedRotateIndexes(): void
	{
		$svc = $this->svc();
		self::assertTrue($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log'));
		self::assertTrue($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.1'));
		self::assertTrue($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.50'));
		self::assertFalse($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.0'));
		self::assertFalse($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.51'));
		self::assertFalse($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.gz'));
		self::assertTrue($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.lck-rotated-20260101-000000'));
		self::assertFalse($svc->isAllowlistedBasename('nextcloud.log', 'nextcloud.log.lck-rotated-bad'));
	}

	public function testSymlinkEscapeOutsideLogDirIsRejected(): void
	{
		$outside = sys_get_temp_dir() . '/lck-outside-' . bin2hex(random_bytes(4));
		file_put_contents($outside, "escaped\n");
		$link = $this->dir . '/nextcloud.log.1';
		if (!@symlink($outside, $link)) {
			@unlink($outside);
			self::markTestSkipped('symlink not supported in this environment');
		}
		try {
			$this->svc()->readTail(4096, 10, 'nextcloud.log.1');
			self::fail('expected ForbiddenException for symlink escape');
		} catch (ForbiddenException $e) {
			self::assertSame('Not authorized.', $e->getMessage());
		} finally {
			@unlink($link);
			@unlink($outside);
		}
	}
}
