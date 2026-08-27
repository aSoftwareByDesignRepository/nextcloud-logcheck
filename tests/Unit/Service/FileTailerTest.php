<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\FileTailer;
use PHPUnit\Framework\TestCase;

class FileTailerTest extends TestCase
{
	private string $tmpDir;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/lck-tailer-' . bin2hex(random_bytes(4));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->tmpDir);
	}

	public function testIncompleteTrailingLineMidChunkCompletedExactlyOnce(): void
	{
		$path = $this->tmpDir . '/midchunk.log';
		$unique = 'UNIQUE_' . bin2hex(random_bytes(8));
		$chunk = FileTailer::CHUNK_BYTES;

		// Fill almost one chunk with complete lines; leave ~20 bytes of the unique line in-chunk.
		$unit = "L\n";
		$n = intdiv($chunk - 20, strlen($unit));
		$complete = str_repeat($unit, $n);
		$completeLen = strlen($complete);
		$remainInChunk = $chunk - $completeLen;
		self::assertGreaterThan(0, $remainInChunk);
		self::assertLessThan(strlen($unique), $remainInChunk, 'unique must cross the chunk boundary');

		$full = $complete . $unique . "\nTRAILER\n";
		file_put_contents($path, $full);
		self::assertGreaterThan($chunk, filesize($path));

		$tailer = new FileTailer();
		$cursor = [
			'path' => $path,
			'offset' => 0,
			'size' => 0,
			'inode' => '',
			'fingerprint' => '',
		];

		$first = $tailer->readChunk($path, $cursor);
		self::assertTrue($first['unread_remain']);
		self::assertNotContains($unique, $first['lines']);
		self::assertSame($completeLen, $first['new_offset']);
		foreach ($first['lines'] as $line) {
			self::assertSame('L', $line);
		}

		$second = $tailer->readChunk($path, array_merge($cursor, [
			'offset' => $first['new_offset'],
			'size' => $first['size'],
			'inode' => $first['inode'],
			'fingerprint' => $first['fingerprint'],
		]));

		$joined = array_merge($first['lines'], $second['lines']);
		$hits = array_values(array_filter($joined, static fn(string $l): bool => $l === $unique || str_contains($l, $unique)));
		self::assertCount(1, $hits, 'unique line must appear exactly once across both reads');
		self::assertSame($unique, $hits[0]);
		self::assertContains('TRAILER', $second['lines']);
	}

	public function testTruncateResetsOffset(): void
	{
		$path = $this->tmpDir . '/rotate.log';
		file_put_contents($path, "one\ntwo\nthree\n");
		$tailer = new FileTailer();
		$first = $tailer->readChunk($path, [
			'path' => $path,
			'offset' => 0,
			'size' => 0,
			'inode' => '',
			'fingerprint' => '',
		]);
		self::assertSame(['one', 'two', 'three'], $first['lines']);
		$advanced = $first['new_offset'];
		self::assertGreaterThan(0, $advanced);

		file_put_contents($path, "fresh\n");
		$after = $tailer->readChunk($path, [
			'path' => $path,
			'offset' => $advanced,
			'size' => $first['size'],
			'inode' => $first['inode'],
			'fingerprint' => $first['fingerprint'],
		]);
		self::assertTrue($after['rotated']);
		self::assertSame(['fresh'], $after['lines']);
		self::assertSame(strlen("fresh\n"), $after['new_offset']);
	}

	public function testEofWithoutNewlineYieldsFinalLine(): void
	{
		$path = $this->tmpDir . '/eof.log';
		file_put_contents($path, "complete\nfinal-without-nl");
		$tailer = new FileTailer();
		$result = $tailer->readChunk($path, [
			'path' => $path,
			'offset' => 0,
			'size' => 0,
			'inode' => '',
			'fingerprint' => '',
		]);
		self::assertSame(['complete', 'final-without-nl'], $result['lines']);
		self::assertSame(filesize($path), $result['new_offset']);
		self::assertFalse($result['unread_remain']);
	}

	public function testEmptyReadAtEof(): void
	{
		$path = $this->tmpDir . '/empty.log';
		file_put_contents($path, "only\n");
		$tailer = new FileTailer();
		$first = $tailer->readChunk($path, [
			'path' => $path,
			'offset' => 0,
			'size' => 0,
			'inode' => '',
			'fingerprint' => '',
		]);
		$second = $tailer->readChunk($path, [
			'path' => $path,
			'offset' => $first['new_offset'],
			'size' => $first['size'],
			'inode' => $first['inode'],
			'fingerprint' => $first['fingerprint'],
		]);
		self::assertSame([], $second['lines']);
		self::assertSame($first['new_offset'], $second['new_offset']);
	}
}
