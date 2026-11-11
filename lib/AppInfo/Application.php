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
use OCA\LogCheck\Service\AccessService;
use OCA\LogCheck\Support\NavGate;
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

		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\LogCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\LogCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\LogCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\LogCheck\ConfigTransfer\TransferHooks {
			return new \OCA\LogCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\LogCheck\Service\SettingsService::class),
				$c->get(AccessService::class),
				$c->get(\OCA\LogCheck\Service\MuteRegexValidator::class),
				$c->get(\OCA\LogCheck\Service\TopologyGuard::class),
				$c->get(\OCA\LogCheck\Service\ChannelTestProof::class),
				$c->get(UpgradeBackupService::class),
				$c->get(\OCA\LogCheck\Service\AuditService::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\LogCheck\ConfigTransfer\TransferPendingStore::class, fn ($c) => new \OCA\LogCheck\ConfigTransfer\TransferPendingStore($c->get(IRootFolder::class), $c->get(IConfig::class), 'logcheck'));
		$context->registerService(\OCA\LogCheck\ConfigTransfer\TransferReceiptStore::class, fn ($c) => new \OCA\LogCheck\ConfigTransfer\TransferReceiptStore($c->get(IConfig::class), 'logcheck'));
		$context->registerService(\OCA\LogCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\LogCheck\ConfigTransfer\TransferService {
			return new \OCA\LogCheck\ConfigTransfer\TransferService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferReceiptStore::class),
				'logcheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\LogCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\LogCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\LogCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferService::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\LogCheck\Controller\TransferApiController::class, function ($c): \OCA\LogCheck\Controller\TransferApiController {
			return new \OCA\LogCheck\Controller\TransferApiController(
				'logcheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\LogCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});

		$context->registerService(SupportUsLinks::class, function (): SupportUsLinks {
			return new SupportUsLinks();
		});
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		// Menu entry only for entitled users — a static <navigation> entry
		// would advertise the app to users the entitlement gate denies.
		NavGate::register(
			$app,
			'logcheck.page.index',
			'app.svg',
			'HealthCheck',
			55,
			static fn(string $uid): bool => $app->get(AccessService::class)->isEntitled($uid),
		);
	}
}
