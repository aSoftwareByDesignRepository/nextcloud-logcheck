<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Exception;

/**
 * Curl transport failure carrying the curl errno + error text so callers can
 * classify (DNS vs TLS vs connect vs timeout) without parsing message strings.
 */
final class HttpTransportException extends \RuntimeException
{
	public function __construct(
		string $message,
		private readonly int $curlErrno = 0,
		private readonly string $curlError = '',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function curlErrno(): int
	{
		return $this->curlErrno;
	}

	public function curlError(): string
	{
		return $this->curlError;
	}
}
