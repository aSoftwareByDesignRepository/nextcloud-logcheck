<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

use OCA\LogCheck\Exception\ValidationException;

/**
 * Argus MF-02 SSRF policy for Slack/webhook URLs.
 */
final class SsrfGuard
{
	/**
	 * Validate URL before outbound HTTP. Does not follow redirects.
	 */
	public function assertAllowed(string $url, bool $allowPrivate = false): void
	{
		$this->pin($url, $allowPrivate);
	}

	/**
	 * Resolve + validate; return connection pin (IP + original host) for SafeHttpClient.
	 *
	 * @return array{url: string, host: string, ip: string, port: int, path: string}
	 */
	public function pin(string $url, bool $allowPrivate = false): array
	{
		$target = $this->parseAllowedUrl($url);

		$ips = $this->resolveIps($target['host']);
		if ($ips === []) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_HOST_UNRESOLVED');
		}

		$chosen = null;
		foreach ($ips as $ip) {
			if ($this->isAlwaysBlocked($ip)) {
				throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
			}
			if ($this->isBlockedIp($ip) && !$allowPrivate) {
				throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
			}
			if ($chosen === null) {
				$chosen = $ip;
			}
		}
		if ($chosen === null) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}

		return [
			'url' => $url,
			'host' => $target['host'],
			'ip' => $chosen,
			'port' => $target['port'],
			'path' => $target['path'],
		];
	}

	/**
	 * Like pin(), but the caller supplies the connect address: the URL's host is
	 * kept for TLS/SNI/Host while the dial goes to $ip (admin-pinned probe
	 * target — e.g. check the external address while internal DNS answers a
	 * split-horizon name). The literal IP passes the same block policy as a
	 * resolved one — allowPrivate still never admits metadata endpoints.
	 *
	 * @return array{url: string, host: string, ip: string, port: int, path: string}
	 */
	public function pinTo(string $url, string $ip, bool $allowPrivate = false): array
	{
		$target = $this->parseAllowedUrl($url);
		$ip = trim($ip);
		if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}
		if ($this->isAlwaysBlocked($ip) || ($this->isBlockedIp($ip) && !$allowPrivate)) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}
		return [
			'url' => $url,
			'host' => $target['host'],
			'ip' => $ip,
			'port' => $target['port'],
			'path' => $target['path'],
		];
	}

	/**
	 * Shared URL-shape validation for pin()/pinTo(): https only, no userinfo,
	 * no metadata host names; returns normalized host + port + path.
	 *
	 * @return array{host: string, port: int, path: string}
	 */
	private function parseAllowedUrl(string $url): array
	{
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}
		$scheme = strtolower((string)$parts['scheme']);
		if ($scheme !== 'https') {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}

		$host = (string)$parts['host'];
		// parse_url keeps brackets on IPv6 literals ("[::1]") — strip for IP/DNS checks.
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		$hostAscii = function_exists('idn_to_ascii')
			? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host)
			: $host;
		$hostLower = strtolower($hostAscii);

		if ($hostLower === 'metadata.google.internal'
			|| $hostLower === 'metadata'
			|| str_ends_with($hostLower, '.metadata.google.internal')) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}

		$port = isset($parts['port']) ? (int)$parts['port'] : 443;
		if ($port <= 0 || $port > 65535) {
			throw new ValidationException('Webhook URL is not allowed.', ['url' => 'Webhook URL is not allowed.'], 'LCK_INVALID_URL');
		}
		$path = (string)($parts['path'] ?? '/');
		if ($path === '') {
			$path = '/';
		}
		if (!empty($parts['query'])) {
			$path .= '?' . $parts['query'];
		}

		return [
			'host' => $hostAscii,
			'port' => $port,
			'path' => $path,
		];
	}

	/** @return list<string> */
	private function resolveIps(string $host): array
	{
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return [$host];
		}
		$ips = [];
		$records = @dns_get_record($host, DNS_A + DNS_AAAA);
		if (is_array($records)) {
			foreach ($records as $rec) {
				if (!empty($rec['ip'])) {
					$ips[] = (string)$rec['ip'];
				}
				if (!empty($rec['ipv6'])) {
					$ips[] = (string)$rec['ipv6'];
				}
			}
		}
		// dns_get_record does unicast DNS only — it misses /etc/hosts and mDNS
		// (.local home servers). gethostbynamel goes through nsswitch, the same
		// resolution curl applies at connect time. IPv4-only; still a strict
		// superset fix, never a downgrade (results are pinned + re-validated).
		$nss = @gethostbynamel($host);
		if (is_array($nss)) {
			foreach ($nss as $ip) {
				$ips[] = (string)$ip;
			}
		}
		return array_values(array_unique($ips));
	}

	private function isAlwaysBlocked(string $ip): bool
	{
		$normalized = strtolower($ip);
		// Cloud instance metadata / fabric (blocked even when allow_private_webhooks).
		$always = [
			'169.254.169.254', // AWS / GCP / most clouds
			'::ffff:169.254.169.254',
			'168.63.129.16', // Azure IMDS / wire server
			'::ffff:168.63.129.16',
			'100.100.100.200', // Alibaba Cloud metadata
			'::ffff:100.100.100.200',
			'metadata.google.internal',
		];
		if (in_array($normalized, $always, true)) {
			return true;
		}
		return str_starts_with($normalized, 'fd00:ec2::');
	}

	private function isBlockedIp(string $ip): bool
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($ip);
			if ($long === false) {
				return true;
			}
			$ranges = [
				['0.0.0.0', '0.255.255.255'],
				['10.0.0.0', '10.255.255.255'],
				['100.64.0.0', '100.127.255.255'],
				['127.0.0.0', '127.255.255.255'],
				['169.254.0.0', '169.254.255.255'],
				['172.16.0.0', '172.31.255.255'],
				['192.168.0.0', '192.168.255.255'],
				['224.0.0.0', '239.255.255.255'], // multicast
				['255.255.255.255', '255.255.255.255'], // broadcast
			];
			foreach ($ranges as [$start, $end]) {
				$s = ip2long($start);
				$e = ip2long($end);
				if ($s !== false && $e !== false && $long >= $s && $long <= $e) {
					return true;
				}
			}
			return false;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$bin = inet_pton($ip);
			if ($bin === false || strlen($bin) !== 16) {
				return true;
			}
			$normalized = strtolower($ip);
			// Loopback
			if ($normalized === '::1' || $normalized === '0:0:0:0:0:0:0:1') {
				return true;
			}
			// Link-local fe80::/10 (fe80–febf) — mask top 10 bits, not prefix string
			$word0 = unpack('n', substr($bin, 0, 2))[1];
			if (($word0 & 0xffc0) === 0xfe80) {
				return true;
			}
			// Deprecated site-local fec0::/10 (fec0–feff) — fail closed
			if (($word0 & 0xffc0) === 0xfec0) {
				return true;
			}
			// Unique local fc00::/7 (fc00–fdff)
			if (str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd')) {
				return true;
			}
			// IPv4-mapped — apply IPv4 private/metadata rules to the embedded address
			if (str_starts_with($normalized, '::ffff:')) {
				$v4 = substr($normalized, 7);
				return $this->isAlwaysBlocked($v4) || $this->isBlockedIp($v4);
			}
			// NAT64 64:ff9b::/96 — decode embedded IPv4 and re-apply IPv4 policy
			if (($word0 === 0x64) && (unpack('n', substr($bin, 2, 2))[1] === 0xff9b)) {
				$v4bin = substr($bin, 12, 4);
				$v4 = inet_ntop($v4bin);
				if (!is_string($v4)) {
					return true;
				}
				return $this->isAlwaysBlocked($v4) || $this->isBlockedIp($v4);
			}
			// Unspecified / discard / multicast — never a valid webhook target
			if ($normalized === '::' || str_starts_with($normalized, 'ff')) {
				return true;
			}
			// Global unicast and other public IPv6 are allowed (Momos SF: do not
			// treat “not private” IPv6 as blocked — that breaks dual-stack Slack/hooks).
			return false;
		}

		// Unparseable address family — fail closed
		return true;
	}
}

