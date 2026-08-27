<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * OpenAPI paths must cover every api#* route (Momos improper inventory).
 */
class OpenApiContractTest extends TestCase
{
	public function testOpenApiCoversAllApiRoutes(): void
	{
		$root = dirname(__DIR__, 3);
		$routes = require $root . '/appinfo/routes.php';
		$openapi = json_decode((string)file_get_contents($root . '/openapi.json'), true);
		self::assertIsArray($openapi);
		self::assertArrayHasKey('paths', $openapi);

		$apiUrls = [];
		foreach ($routes['routes'] as $route) {
			$name = (string)($route['name'] ?? '');
			if (!str_starts_with($name, 'api#')) {
				continue;
			}
			$url = (string)($route['url'] ?? '');
			$verb = strtolower((string)($route['verb'] ?? 'get'));
			$apiUrls[$url][] = $verb;
		}
		self::assertNotEmpty($apiUrls, 'expected api routes');

		foreach ($apiUrls as $url => $verbs) {
			self::assertArrayHasKey($url, $openapi['paths'], 'OpenAPI missing path ' . $url);
			foreach ($verbs as $verb) {
				self::assertArrayHasKey(
					$verb,
					$openapi['paths'][$url],
					"OpenAPI missing $verb for $url"
				);
			}
		}

		foreach (array_keys($openapi['paths']) as $path) {
			self::assertArrayHasKey($path, $apiUrls, 'OpenAPI has extra path not in routes.php: ' . $path);
		}
	}
}
