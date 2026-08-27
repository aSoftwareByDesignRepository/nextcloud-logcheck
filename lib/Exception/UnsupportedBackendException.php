<?php

declare(strict_types=1);

namespace OCA\LogCheck\Exception;

final class UnsupportedBackendException extends \RuntimeException
{
	public function __construct(
		private readonly string $logType,
		string $message = 'HealthCheck only supports file-based logging.',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getLogType(): string
	{
		return $this->logType;
	}
}
