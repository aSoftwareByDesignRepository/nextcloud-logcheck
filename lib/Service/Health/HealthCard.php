<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\LogCheck\Service\Health;

/**
 * One Health dashboard card (HCK-CORE-1 Card DTO).
 */
final class HealthCard
{
	/**
	 * @param list<array{id: string, label: string, href: string|null}> $actions
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $state,
		public readonly string $label,
		public readonly string $detail = '',
		public readonly array $actions = [],
	) {
		if (!HealthCardState::isValid($this->state)) {
			throw new \InvalidArgumentException('Invalid health card state: ' . $this->state);
		}
	}

	/**
	 * @return array{
	 *   id: string,
	 *   title: string,
	 *   state: string,
	 *   label: string,
	 *   detail: string,
	 *   actions: list<array{id: string, label: string, href: string|null}>
	 * }
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'state' => $this->state,
			'label' => $this->label,
			'detail' => $this->detail,
			'actions' => $this->actions,
		];
	}
}
