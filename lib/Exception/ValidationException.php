<?php

declare(strict_types=1);

namespace OCA\LogCheck\Exception;

final class ValidationException extends \RuntimeException
{
	/** @param array<string, string> $fieldErrors */
	public function __construct(
		string $message,
		private readonly array $fieldErrors = [],
		private readonly string $errorCode = 'LCK_VALIDATION',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	/** @return array<string, string> */
	public function getFieldErrors(): array
	{
		return $this->fieldErrors;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
