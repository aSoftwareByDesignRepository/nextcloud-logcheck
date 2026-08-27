<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\PayloadBuilder;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
	public function testNn01PasswordNotInPayloadWhenExcerptsOff(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);

		$builder = new PayloadBuilder($url, $apps);
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['files' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'files',
					'sample_message' => 'login failed password=supersecret',
				],
			],
		];
		$payload = $builder->build('evt1', $acc, ['include_message_excerpts' => false, 'min_level' => 3], 300);
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('password=', $json);
		self::assertStringNotContainsString('supersecret', $json);
		self::assertNull($payload['top_fingerprints'][0]['sample_message']);
	}

	public function testRedactionWhenExcerptsOn(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$builder = new PayloadBuilder($url, $apps);
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['files' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'files',
					'sample_message' => 'login failed password=supersecret',
				],
			],
		];
		$payload = $builder->build('evt1', $acc, [
			'include_message_excerpts' => true,
			'excerpt_max_chars' => 200,
			'min_level' => 3,
		], 300);
		self::assertStringContainsString('password=***', (string)$payload['top_fingerprints'][0]['sample_message']);
		self::assertStringNotContainsString('supersecret', (string)$payload['top_fingerprints'][0]['sample_message']);
	}

	public function testRedactsJwtAndApiKeyWhenExcerptsOn(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$builder = new PayloadBuilder($url, $apps);
		$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signaturexx';
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['files' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'files',
					'sample_message' => 'api_key=abcd1234token ' . $jwt,
				],
			],
		];
		$payload = $builder->build('evt1', $acc, [
			'include_message_excerpts' => true,
			'excerpt_max_chars' => 400,
			'min_level' => 3,
		], 300);
		$sample = (string)$payload['top_fingerprints'][0]['sample_message'];
		self::assertStringContainsString('api_key=***', $sample);
		self::assertStringContainsString('jwt=***', $sample);
		self::assertStringNotContainsString('abcd1234token', $sample);
		self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $sample);
	}

	public function testExcerptOnIncludesSampleMessage(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$builder = new PayloadBuilder($url, $apps);
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['files' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'files',
					'sample_message' => 'plain error text',
				],
			],
		];
		$on = $builder->build('evt1', $acc, [
			'include_message_excerpts' => true,
			'excerpt_max_chars' => 200,
			'min_level' => 3,
		], 300);
		$off = $builder->build('evt1', $acc, [
			'include_message_excerpts' => false,
			'min_level' => 3,
		], 300);
		self::assertSame('plain error text', $on['top_fingerprints'][0]['sample_message']);
		self::assertNull($off['top_fingerprints'][0]['sample_message']);
	}

	/** Momos: Nextcloud log messages often embed JSON — redaction must cover "password":"…" */
	public function testRedactsJsonStylePasswordAndToken(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$builder = new PayloadBuilder($url, $apps);
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['user_ldap' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'user_ldap',
					'sample_message' => '{"password":"jsonSecret99","token":"tok_leak_me"}',
				],
			],
		];
		$payload = $builder->build('evt1', $acc, [
			'include_message_excerpts' => true,
			'excerpt_max_chars' => 400,
			'min_level' => 3,
		], 300);
		$sample = (string)$payload['top_fingerprints'][0]['sample_message'];
		self::assertStringNotContainsString('jsonSecret99', $sample);
		self::assertStringNotContainsString('tok_leak_me', $sample);
		self::assertStringContainsString('***', $sample);
	}

	public function testRedactsOauthAndCloudTokens(): void
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$builder = new PayloadBuilder($url, $apps);
		$acc = [
			'total_matched' => 1,
			'total_muted' => 0,
			'truncated' => false,
			'by_level' => ['3' => 1],
			'by_app' => ['files' => 1],
			'fingerprints' => [
				'abc' => [
					'count' => 1,
					'level' => 3,
					'app' => 'files',
					'sample_message' => 'access_token=atk_secret refresh_token=rtk_secret '
						. 'xoxb-1234567890-abcdefghij AKIAIOSFODNN7EXAMPLE '
						. 'ghp_abcdefghijklmnopqrstuvwxyz0123456789',
				],
			],
		];
		$payload = $builder->build('evt1', $acc, [
			'include_message_excerpts' => true,
			'excerpt_max_chars' => 500,
			'min_level' => 3,
		], 300);
		$sample = (string)$payload['top_fingerprints'][0]['sample_message'];
		self::assertStringNotContainsString('atk_secret', $sample);
		self::assertStringNotContainsString('rtk_secret', $sample);
		self::assertStringNotContainsString('xoxb-1234567890-abcdefghij', $sample);
		self::assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $sample);
		self::assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz0123456789', $sample);
	}
}
