<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\ForbiddenException;
use OCA\LogCheck\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Entitlement = NC system admin OR App Admin. Access Open is forbidden.
 */
final class AccessService
{
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IDBConnection $db,
	) {
	}

	public function isNcAdmin(string $uid): bool
	{
		return $this->groupManager->isAdmin($uid);
	}

	public function isAppAdmin(string $uid): bool
	{
		return in_array($uid, $this->appAdminUids(), true);
	}

	public function isEntitled(string $uid): bool
	{
		return $this->isNcAdmin($uid) || $this->isAppAdmin($uid);
	}

	public function assertEntitled(string $uid): void
	{
		if (!$this->isEntitled($uid)) {
			throw new ForbiddenException('Not authorized.');
		}
	}

	/** @return list<string> */
	public function appAdminUids(): array
	{
		$settings = $this->loadAccessFromDb();
		$admins = $settings['app_admins'] ?? [];
		if (!is_array($admins)) {
			return [];
		}
		$out = [];
		foreach ($admins as $uid) {
			if (is_string($uid) && $uid !== '') {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/** @return list<string> */
	public function entitledUids(): array
	{
		$uids = [];
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			foreach ($adminGroup->getUsers() as $user) {
				$uids[$user->getUID()] = true;
			}
		}
		foreach ($this->appAdminUids() as $uid) {
			if ($this->userManager->userExists($uid)) {
				$uids[$uid] = true;
			}
		}
		return array_keys($uids);
	}

	/** Hard cap on App Admin list size. */
	public const APP_ADMINS_MAX = 100;

	/**
	 * @param array{mode?: mixed, app_admins?: mixed} $access
	 * @return array{mode: string, app_admins: list<string>}
	 */
	public function normalizeAccess(array $access): array
	{
		$mode = isset($access['mode']) ? (string)$access['mode'] : 'restricted';
		if ($mode === 'open' || $mode === 'all' || $mode === 'public') {
			throw new ValidationException(
				'Open access is not allowed.',
				['access.mode' => 'Open access is not allowed.'],
				'LCK_FORBIDDEN'
			);
		}
		if ($mode !== 'restricted') {
			throw new ValidationException(
				'Invalid access mode.',
				['access.mode' => 'Invalid access mode.'],
				'LCK_VALIDATION'
			);
		}

		$raw = $access['app_admins'] ?? [];
		if (!is_array($raw)) {
			throw new ValidationException('Invalid app admins.', ['access.app_admins' => 'Invalid app admins.']);
		}
		if (count($raw) > self::APP_ADMINS_MAX) {
			throw new ValidationException(
				'Too many app admins.',
				['access.app_admins' => 'Too many app admins.'],
				'LCK_VALIDATION'
			);
		}
		$admins = [];
		foreach ($raw as $uid) {
			if (!is_string($uid) || $uid === '') {
				continue;
			}
			if (!$this->userManager->userExists($uid)) {
				throw new ValidationException(
					'Unknown user.',
					['access.app_admins' => 'Unknown user'],
					'LCK_VALIDATION'
				);
			}
			$admins[] = $uid;
		}
		return [
			'mode' => 'restricted',
			'app_admins' => array_values(array_unique($admins)),
		];
	}

	public function userDisplayName(string $uid): string
	{
		$user = $this->userManager->get($uid);
		if ($user instanceof IUser) {
			return $user->getDisplayName();
		}
		return $uid;
	}

	/** @return array{mode?: string, app_admins?: list<string>} */
	private function loadAccessFromDb(): array
	{
		if (!$this->db->tableExists('lck_settings')) {
			return ['mode' => 'restricted', 'app_admins' => []];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('payload')
			->from('lck_settings')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row) || !isset($row['payload'])) {
			return ['mode' => 'restricted', 'app_admins' => []];
		}
		$decoded = json_decode((string)$row['payload'], true);
		if (!is_array($decoded)) {
			return ['mode' => 'restricted', 'app_admins' => []];
		}
		$access = $decoded['access'] ?? [];
		return is_array($access) ? $access : ['mode' => 'restricted', 'app_admins' => []];
	}
}
