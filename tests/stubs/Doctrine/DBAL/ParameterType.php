<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

/** Minimal stub for unit tests when doctrine/dbal is not installed. */
class ParameterType
{
	public const NULL = 0;
	public const INTEGER = 1;
	public const STRING = 2;
	public const LARGE_OBJECT = 3;
	public const BOOLEAN = 5;
	public const BINARY = 16;
	public const ASCII = 19;
}
