<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

class SettingsSectionCatalogTest extends TestCase
{
	public function testDefaultAndSections(): void
	{
		self::assertSame('alerts', SettingsSectionCatalog::DEFAULT_SECTION);
		self::assertSame(['alerts', 'rules', 'people', 'support'], SettingsSectionCatalog::SECTIONS);
		self::assertSame('alerts|rules|people|support', SettingsSectionCatalog::routeRequirement());
	}

	public function testLegacyAliases(): void
	{
		self::assertSame('home', SettingsSectionCatalog::LEGACY_ALIASES['status']);
		self::assertSame('alerts', SettingsSectionCatalog::LEGACY_ALIASES['channels']);
		self::assertSame('people', SettingsSectionCatalog::LEGACY_ALIASES['access']);
	}

	public function testRoutesUseCatalogRequirement(): void
	{
		$routes = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		self::assertNotFalse($routes);
		self::assertStringContainsString('SettingsSectionCatalog::routeRequirement()', $routes);
	}
}
