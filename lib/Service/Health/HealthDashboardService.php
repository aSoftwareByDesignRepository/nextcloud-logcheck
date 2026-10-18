<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

use OCA\LogCheck\AppInfo\Application;
use OCA\LogCheck\Service\SettingsService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Runs all V1 Health probes with per-probe isolation (one failure ≠ blank page).
 */
final class HealthDashboardService
{
	/** Every V1 probe id, in dashboard order — single source for validation + UI. */
	public const PROBE_IDS = ['log', 'jobs', 'php', 'db', 'disk', 'https', 'updates'];

	/** @var list<HealthProbeInterface> */
	private readonly array $probes;

	public function __construct(
		LogHealthProbe $log,
		JobsHealthProbe $jobs,
		PhpHealthProbe $php,
		DbHealthProbe $db,
		DiskHealthProbe $disk,
		HttpsHealthProbe $https,
		UpdatesHealthProbe $updates,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IFactory $l10nFactory,
	) {
		$this->probes = [$log, $jobs, $php, $db, $disk, $https, $updates];
	}

	/** Card titles shared by the health grid and the check toggles in Rules. */
	public static function cardTitles(IL10N $l10n): array
	{
		return [
			'log' => $l10n->t('Log alerts'),
			'jobs' => $l10n->t('Background jobs'),
			'php' => $l10n->t('PHP'),
			'db' => $l10n->t('Database'),
			'disk' => $l10n->t('Data disk'),
			'https' => $l10n->t('HTTPS'),
			'updates' => $l10n->t('Updates'),
		];
	}

	/**
	 * @return array{
	 *   cards: list<array{
	 *     id: string,
	 *     title: string,
	 *     state: string,
	 *     label: string,
	 *     detail: string,
	 *     actions: list<array{id: string, label: string, href: string|null}>
	 *   }>,
	 *   summary_state: string,
	 *   summary_label: string
	 * }
	 */
	public function dashboard(): array
	{
		$cards = $this->cards();
		$summary = self::summarize($cards);
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$labels = [
			HealthCardState::OK => $l10n->t('Everything looks fine'),
			HealthCardState::DEGRADED => $l10n->t('Some checks need attention'),
			HealthCardState::CRITICAL => $l10n->t('Something needs fixing'),
			HealthCardState::UNKNOWN => $l10n->t('Some checks are unknown'),
		];
		return [
			'cards' => $cards,
			'summary_state' => $summary,
			'summary_label' => $labels[$summary] ?? $l10n->t('Unknown'),
		];
	}

	/**
	 * @return list<array{
	 *   id: string,
	 *   title: string,
	 *   state: string,
	 *   label: string,
	 *   detail: string,
	 *   actions: list<array{id: string, label: string, href: string|null}>
	 * }>
	 */
	public function cards(): array
	{
		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$disabled = [];
		try {
			$list = $this->settingsService->getRawSettings()['health_disabled_probes'] ?? [];
			if (is_array($list)) {
				$disabled = array_fill_keys(array_map('strval', $list), true);
			}
		} catch (\Throwable $e) {
			// non-fatal: unavailable settings (e.g. mid-migration) must never
			// blank the dashboard — fall through and run every probe.
			$this->logger->info('HealthCheck could not read disabled-check list', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
		$out = [];
		foreach ($this->probes as $probe) {
			if (isset($disabled[$probe->id()])) {
				continue;
			}
			try {
				$out[] = $probe->probe()->toArray();
			} catch (\Throwable $e) {
				// best-effort: a failing probe must not blank the dashboard; the
				// card below renders the error state instead of the probe result.
				$this->logger->warning('HealthCheck health probe failed', [
					'app' => 'logcheck',
					'probe' => $probe->id(),
					'exception' => $e,
				]);
				$out[] = (new HealthCard(
					$probe->id(),
					ucfirst($probe->id()),
					HealthCardState::UNKNOWN,
					$l10n->t('Unknown'),
					$l10n->t('This check could not run.'),
				))->toArray();
			}
		}
		return $out;
	}

	/**
	 * Worst-state rollup for the page summary (never invents ok from empty).
	 *
	 * @param list<array{state?: string}> $cards
	 */
	public static function summarize(array $cards): string
	{
		if ($cards === []) {
			return HealthCardState::UNKNOWN;
		}
		$rank = [
			HealthCardState::OK => 0,
			HealthCardState::UNKNOWN => 1,
			HealthCardState::DEGRADED => 2,
			HealthCardState::CRITICAL => 3,
		];
		$worst = HealthCardState::OK;
		$worstRank = 0;
		foreach ($cards as $card) {
			$state = (string)($card['state'] ?? HealthCardState::UNKNOWN);
			if (!isset($rank[$state])) {
				$state = HealthCardState::UNKNOWN;
			}
			if ($rank[$state] > $worstRank) {
				$worstRank = $rank[$state];
				$worst = $state;
			}
		}
		return $worst;
	}
}
