<?php

declare(strict_types=1);

namespace OCA\LogCheck\Exception;

final class ConflictException extends \RuntimeException
{
	public function __construct(string $message = 'Settings changed elsewhere — reload and try again.', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
