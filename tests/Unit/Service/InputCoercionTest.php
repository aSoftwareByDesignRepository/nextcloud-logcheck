<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Exception\ValidationException;
use OCA\LogCheck\Service\InputCoercion;
use PHPUnit\Framework\TestCase;

class InputCoercionTest extends TestCase
{
	/**
	 * The proven form-encoding hole: PHP (bool)"false" is true. Every
	 * string that humans and browsers send for "off" must map to false.
	 */
	public static function falseInputs(): array
	{
		return [
			'bool' => [false],
			'int zero' => [0],
			'zero' => ['0'],
			'false string' => ['false'],
			'FALSE string' => ['FALSE'],
			'no' => ['no'],
			'off' => ['off'],
			'empty string' => [''],
			'float zero' => [0.0],
		];
	}

	/**
	 * @dataProvider falseInputs
	 */
	public function testAsBoolRejectsFalseValuesAsFalse(mixed $input): void
	{
		self::assertFalse(InputCoercion::asBool($input, 'flag'));
	}

	public static function trueInputs(): array
	{
		return [
			'bool' => [true],
			'int one' => [1],
			'one' => ['1'],
			'true string' => ['true'],
			'TRUE string' => ['TRUE'],
			'yes' => ['yes'],
			'on' => ['on'],
			'padded' => ['  true  '],
			'float one' => [1.0],
		];
	}

	/**
	 * @dataProvider trueInputs
	 */
	public function testAsBoolParsesTrueValues(mixed $input): void
	{
		self::assertTrue(InputCoercion::asBool($input, 'flag'));
	}

	public static function garbageInputs(): array
	{
		return [
			'banana' => ['banana'],
			'two' => [2],
			'negative' => [-1],
			'array' => [[1]],
			'empty array' => [[]],
			'object' => [new \stdClass()],
			'null' => [null],
			'float two' => [2.5],
		];
	}

	/**
	 * @dataProvider garbageInputs
	 */
	public function testAsBoolRejectsGarbageFailClosed(mixed $input): void
	{
		$this->expectException(ValidationException::class);
		InputCoercion::asBool($input, 'flag');
	}

	public function testAsBoolErrorCarriesFieldKey(): void
	{
		try {
			InputCoercion::asBool('banana', 'watch_enabled');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('LCK_VALIDATION', $e->getErrorCode());
			self::assertArrayHasKey('watch_enabled', $e->getFieldErrors());
		}
	}

	public function testAsIntParsesIntsAndDigitStrings(): void
	{
		self::assertSame(3, InputCoercion::asInt(3, 'n'));
		self::assertSame(3, InputCoercion::asInt('3', 'n'));
		self::assertSame(0, InputCoercion::asInt(0, 'n'));
		self::assertSame(900, InputCoercion::asInt(' 900 ', 'n'));
		self::assertSame(-2, InputCoercion::asInt(-2, 'n'));
		self::assertSame(4, InputCoercion::asInt(4.0, 'n'));
	}

	public static function garbageInts(): array
	{
		return [
			'banana' => ['banana'],
			'empty' => [''],
			'bool' => [true],
			'array' => [[3]],
			'null' => [null],
			'fraction string' => ['3.5'],
			'fraction float' => [3.5],
			'mixed' => ['3x'],
		];
	}

	/**
	 * @dataProvider garbageInts
	 */
	public function testAsIntRejectsGarbageFailClosed(mixed $input): void
	{
		$this->expectException(ValidationException::class);
		InputCoercion::asInt($input, 'n');
	}

	public function testAsStringAcceptsScalarsRejectsArrays(): void
	{
		self::assertSame('abc', InputCoercion::asString('abc', 'f'));
		self::assertSame('5', InputCoercion::asString(5, 'f'));
		$this->expectException(ValidationException::class);
		InputCoercion::asString(['a'], 'f');
	}
}
