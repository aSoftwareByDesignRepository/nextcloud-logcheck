<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCP\Security\ICrypto;

/**
 * Encrypts channel secrets at rest; masks for UI without leaking token suffixes.
 */
final class SecretStore
{
	public function __construct(
		private readonly ICrypto $crypto,
	) {
	}

	public function encrypt(string $plaintext): string
	{
		return $this->crypto->encrypt($plaintext);
	}

	public function decrypt(string $ciphertext): string
	{
		return $this->crypto->decrypt($ciphertext);
	}

	/**
	 * Host-only mask — never echo URL path/query (Slack tokens live in the path).
	 */
	public function mask(?string $plaintext): ?string
	{
		if ($plaintext === null || $plaintext === '') {
			return null;
		}
		$host = parse_url($plaintext, PHP_URL_HOST);
		if (is_string($host) && $host !== '') {
			return 'Saved (' . $host . ')';
		}
		return 'Saved';
	}

	public function tryDecrypt(?string $ciphertext): ?string
	{
		if ($ciphertext === null || $ciphertext === '') {
			return null;
		}
		try {
			return $this->decrypt($ciphertext);
		} catch (\Throwable) {
			return null;
		}
	}
}
