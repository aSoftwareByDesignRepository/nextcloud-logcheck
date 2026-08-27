<?php

declare(strict_types=1);

namespace OCA\LogCheck\Exception;

final class ForbiddenException extends \RuntimeException
{
	public function __construct(string $message = 'Not authorized.', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
