<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\MuteRegexValidator;
use PHPUnit\Framework\TestCase;

class MuteRegexValidatorTest extends TestCase
{
	public function testRejectsTooLong(): void
	{
		$v = new MuteRegexValidator();
		$this->expectException(ValidationException::class);
		$v->assertSafe(str_repeat('a', 201));
	}

	public function testRejectsDangerousNested(): void
	{
		$v = new MuteRegexValidator();
		$this->expectException(ValidationException::class);
		$v->assertSafe('(a+)+');
	}

	public function testRejectsQuantifiedAlternation(): void
	{
		$v = new MuteRegexValidator();
		$this->expectException(ValidationException::class);
		$v->assertSafe('(a|ab)+');
	}

	public function testRejectsRecursion(): void
	{
		$v = new MuteRegexValidator();
		$this->expectException(ValidationException::class);
		$v->assertSafe('(?R)evil');
	}

	public function testAcceptsSimple(): void
	{
		$v = new MuteRegexValidator();
		$v->assertSafe('NotFound');
		$v->assertSafe('permission denied');
		$v->assertSafe('files_external');
		$this->addToAssertionCount(1);
	}
}
