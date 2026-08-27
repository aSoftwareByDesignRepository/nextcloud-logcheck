<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * Chunked log file reader (≤2 MiB). Offset advances only past complete lines
 * so partial trailing data is re-read on the next tick (no silent skip).
 */
final class FileTailer
{
	public const CHUNK_BYTES = 2097152; // 2 MiB

	/**
	 * @param array{path: string, offset: int, size: int, inode: string, fingerprint: string} $cursor
	 * @return array{
	 *   lines: list<string>,
	 *   new_offset: int,
	 *   size: int,
	 *   inode: string,
	 *   fingerprint: string,
	 *   rotated: bool,
	 *   unread_remain: bool
	 * }
	 */
	public function readChunk(string $path, array $cursor): array
	{
		if (!is_readable($path)) {
			throw new \RuntimeException('Cannot read the log file. Check permissions.');
		}

		$stat = @stat($path);
		if ($stat === false) {
			throw new \RuntimeException('Cannot read the log file. Check permissions.');
		}

		$size = (int)$stat['size'];
		$inode = (string)($stat['ino'] ?? '');
		$fingerprint = hash('sha256', $path . '|' . $inode . '|' . $size);

		$offset = (int)($cursor['offset'] ?? 0);
		$rotated = false;
		$prevInode = (string)($cursor['inode'] ?? '');
		$prevPath = (string)($cursor['path'] ?? '');

		if (($prevPath !== '' && $prevPath !== $path)
			|| ($prevInode !== '' && $inode !== '' && $prevInode !== $inode)
			|| $size < $offset) {
			$rotated = true;
			$offset = 0;
		}

		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('Cannot read the log file. Check permissions.');
		}

		try {
			if ($offset > 0) {
				fseek($fh, $offset);
			}
			// fread may return less than requested (stream wrappers, pipes); accumulate to CHUNK_BYTES.
			$raw = '';
			$need = self::CHUNK_BYTES;
			while ($need > 0) {
				$piece = fread($fh, $need);
				if ($piece === false || $piece === '') {
					break;
				}
				$raw .= $piece;
				$need -= strlen($piece);
			}
			$bytesRead = strlen($raw);
			$unreadRemain = ($offset + $bytesRead) < $size;

			$lines = [];
			$newOffset = $offset;

			if ($bytesRead === 0) {
				return [
					'lines' => [],
					'new_offset' => $offset,
					'size' => $size,
					'inode' => $inode,
					'fingerprint' => $fingerprint,
					'rotated' => $rotated,
					'unread_remain' => false,
				];
			}

			$lastNl = strrpos($raw, "\n");
			if ($lastNl === false) {
				if ($unreadRemain) {
					// Incomplete line mid-file — do not advance; wait for more bytes.
					$newOffset = $offset;
				} else {
					// EOF without newline — treat as final complete line.
					if ($raw !== '') {
						$lines[] = $raw;
					}
					$newOffset = $offset + $bytesRead;
				}
			} else {
				$complete = substr($raw, 0, $lastNl);
				$trailing = substr($raw, $lastNl + 1);
				foreach (explode("\n", $complete) as $line) {
					if ($line !== '') {
						$lines[] = $line;
					}
				}
				// Advance only through the last newline (re-read trailing partial next time).
				$newOffset = $offset + $lastNl + 1;

				if (!$unreadRemain && $trailing !== '') {
					// Final unterminated line at EOF is complete for our purposes.
					$lines[] = $trailing;
					$newOffset = $offset + $bytesRead;
				}
			}

			return [
				'lines' => $lines,
				'new_offset' => $newOffset,
				'size' => $size,
				'inode' => $inode,
				'fingerprint' => $fingerprint,
				'rotated' => $rotated,
				'unread_remain' => $unreadRemain && ($newOffset < $size),
			];
		} finally {
			fclose($fh);
		}
	}
}
