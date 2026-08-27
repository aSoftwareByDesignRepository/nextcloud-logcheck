<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\App\IAppManager;
use OCA\LogCheck\Service\UpgradeBackupService;
use OCA\LogCheck\Repair\BackupBeforeUpdate;
use OCA\LogCheck\Middleware\EntitlementMiddleware;
use OCA\LogCheck\Notification\Notifier;
use OCA\LogCheck\Repair\EnsureLogCheckSchema;
use OCA\LogCheck\Repair\UninstallDropTables;
use OCA\LogCheck\Support\SupportUsLinks;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'logcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerMiddleware(EntitlementMiddleware::class);
		$context->registerNotifierService(Notifier::class);

		$context->registerService(EnsureLogCheckSchema::class, function ($c): EnsureLogCheckSchema {
			return new EnsureLogCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});


		$context->registerService(SupportUsLinks::class, function (): SupportUsLinks {
			return new SupportUsLinks();
		});
	}

	public function boot(IBootContext $context): void
	{
	}
}
