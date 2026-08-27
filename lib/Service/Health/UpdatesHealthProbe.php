<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCP\IAppConfig;
use OCP\IConfig;
use OCA\LogCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Core update availability from NC cache only (Argus R-H03 / NN-H20).
 * Read-only lastupdateResult / lastupdatedat — no outbound updater traffic from this app.
 */
final class UpdatesHealthProbe implements HealthProbeInterface
{
	/** Cache older than this without a successful shape → unknown (not ok). */
	public const STALE_CACHE_SECONDS = 86400 * 14;

	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return list<array{id: string, label: string, href: string|null}> */
	private function overviewAction(): array
	{
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$href = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'overview']) . '#version';
		return [[
			'id' => 'admin-overview',
			'label' => $l10n->t('Open admin overview'),
			'href' => $href,
		]];
	}

	public function id(): string
	{
		return 'updates';
	}

	public function probe(): HealthCard
	{
		if (!$this->config->getSystemValueBool('has_internet_connection', true)) {
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Offline'),
				$this->l10nFactory->get(Application::APP_ID)->t('Internet connection is disabled for this instance.'),
			);
		}

		$lastAt = $this->appConfig->getValueInt('core', 'lastupdatedat', 0);
		$raw = $this->config->getAppValue('core', 'lastupdateResult', '');

		if ($lastAt <= 0 || $raw === '') {
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Not checked yet'),
				$this->l10nFactory->get(Application::APP_ID)->t('Nextcloud has not cached an update check yet. Open Admin settings → Overview.'),
				$this->overviewAction(),
			);
		}

		if ((time() - $lastAt) > self::STALE_CACHE_SECONDS) {
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Check outdated'),
				$this->l10nFactory->get(Application::APP_ID)->t('The cached update check is older than two weeks.'),
				$this->overviewAction(),
			);
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Update cache could not be read.'),
			);
		}

		// Honest shape: require a string "version" key (may be empty = no update).
		if (!array_key_exists('version', $data) || is_array($data['version'])) {
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::UNKNOWN,
				$this->l10nFactory->get(Application::APP_ID)->t('Unknown'),
				$this->l10nFactory->get(Application::APP_ID)->t('Update cache could not be read.'),
			);
		}

		$version = is_string($data['version']) ? trim($data['version']) : '';
		$versionString = isset($data['versionstring']) && is_string($data['versionstring'])
			? trim($data['versionstring'])
			: $version;

		if ($version !== '') {
			$safe = preg_replace('/[^a-zA-Z0-9.\-\s]/', '', $versionString) ?? '';
			$safe = $safe !== '' ? $safe : $this->l10nFactory->get(Application::APP_ID)->t('a newer version');
			return new HealthCard(
				'updates',
				'Updates',
				HealthCardState::DEGRADED,
				$this->l10nFactory->get(Application::APP_ID)->t('Update available'),
				$this->l10nFactory->get(Application::APP_ID)->t('Nextcloud core: %s. App updates: check Apps in Admin settings.', [$safe]),
				$this->overviewAction(),
			);
		}

		return new HealthCard(
			'updates',
			'Updates',
			HealthCardState::OK,
			$this->l10nFactory->get(Application::APP_ID)->t('Up to date'),
			$this->l10nFactory->get(Application::APP_ID)->t('No core update in the last check. App updates: check Apps in Admin settings.'),
		);
	}
}
