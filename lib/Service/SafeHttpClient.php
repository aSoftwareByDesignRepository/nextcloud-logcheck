<?php

declare(strict_types=1);

namespace OCA\LogCheck\Service;

/**
 * SSRF-safe HTTPS client. Curl is required: CURLOPT_RESOLVE pins the validated IP
 * while preserving TLS/SNI hostname checks. No stream/hostname reconnect fallback
 * (DNS-rebinding TOCTOU).
 */
final class SafeHttpClient
{
	public function __construct(
		private readonly SsrfGuard $ssrfGuard,
	) {
	}

	/**
	 * @return array{status: int, body: string}
	 */
	public function postJson(string $url, array $payload, bool $allowPrivate = false): array
	{
		if (!function_exists('curl_init')) {
			throw new \RuntimeException('HTTPS delivery requires PHP curl. Webhook failed (HTTP 0).');
		}
		$pin = $this->ssrfGuard->pin($url, $allowPrivate);
		$body = json_encode($payload, JSON_THROW_ON_ERROR);
		return $this->requestViaCurl($pin, 'POST', $body, 10, 5);
	}

	/**
	 * HTTPS GET to this instance's status.php only (Argus R-H02 / NN-H09).
	 * Host must match $expectedHost; path must end with /status.php under optional webroot;
	 * no query/fragment/userinfo. Private IPs allowed only after those checks.
	 *
	 * @return array{status: int, body: string}
	 */
	public function getInstanceStatus(string $absoluteStatusUrl, string $expectedHost): array
	{
		if (!function_exists('curl_init')) {
			throw new \RuntimeException('HTTPS self-check requires PHP curl.');
		}
		$parts = parse_url($absoluteStatusUrl);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}
		if (strtolower((string)$parts['scheme']) !== 'https') {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}

		$host = (string)$parts['host'];
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		$hostAscii = function_exists('idn_to_ascii')
			? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host)
			: $host;
		$expected = $expectedHost;
		if (str_starts_with($expected, '[') && str_ends_with($expected, ']')) {
			$expected = substr($expected, 1, -1);
		}
		$expectedAscii = function_exists('idn_to_ascii')
			? (idn_to_ascii($expected, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $expected)
			: $expected;
		if (!hash_equals(strtolower($expectedAscii), strtolower($hostAscii))) {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}

		$path = (string)($parts['path'] ?? '');
		if (!self::isAllowedStatusPath($path)) {
			throw new \InvalidArgumentException('Instance status URL is not allowed.');
		}

		// Rebuild canonical URL (no query) so pin cannot be confused by junk.
		$port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
		$hostForUrl = self::hostForUrl($hostAscii);
		$canonical = 'https://' . $hostForUrl . $port . $path;
		$pin = $this->ssrfGuard->pin($canonical, true);
		// Soft budget: total 1.5s so Health page stays responsive (HCK NFR).
		return $this->requestViaCurl($pin, 'GET', null, 1, 1);
	}

	/**
	 * Allow /status.php or /{webroot}/status.php (single-segment webroot, no ..).
	 */
	public static function isAllowedStatusPath(string $path): bool
	{
		if ($path === '/status.php') {
			return true;
		}
		// e.g. /nextcloud/status.php — one non-dot path segment, no traversal.
		if (preg_match('#^/([A-Za-z0-9_-]+)/status\.php$#', $path, $m) !== 1) {
			return false;
		}
		$segment = $m[1];
		return $segment !== '' && $segment !== '.' && $segment !== '..';
	}

	public static function hostForUrl(string $hostAscii): string
	{
		if (filter_var($hostAscii, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return '[' . $hostAscii . ']';
		}
		return $hostAscii;
	}

	/**
	 * @param array{url: string, host: string, ip: string, port: int, path: string} $pin
	 * @return array{status: int, body: string}
	 */
	private function requestViaCurl(array $pin, string $method, ?string $body, int $timeout, int $connectTimeout): array
	{
		$ch = curl_init($pin['url']);
		if ($ch === false) {
			throw new \RuntimeException($method === 'POST'
				? 'Webhook failed (HTTP 0).'
				: 'HTTPS request failed (HTTP 0).');
		}
		// Curl RESOLVE: IPv6 pin address must be bracketed; hostname stays unbracketed.
		$resolveIp = $pin['ip'];
		if (str_contains($resolveIp, ':') && !str_starts_with($resolveIp, '[')) {
			$resolveIp = '[' . $resolveIp . ']';
		}
		$resolve = sprintf('%s:%d:%s', $pin['host'], $pin['port'], $resolveIp);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connectTimeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => 0,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_RESOLVE => [$resolve],
			CURLOPT_HTTPHEADER => [
				'Accept: */*',
			],
		];
		if ($method === 'POST') {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = $body ?? '';
			$opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
		} else {
			$opts[CURLOPT_HTTPGET] = true;
		}
		curl_setopt_array($ch, $opts);
		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$errno = curl_errno($ch);
		curl_close($ch);
		if ($errno !== 0 || $response === false) {
			throw new \RuntimeException($method === 'POST'
				? 'Webhook failed (HTTP 0).'
				: 'HTTPS request failed (HTTP 0).');
		}
		return ['status' => $status, 'body' => is_string($response) ? mb_substr($response, 0, 2048) : ''];
	}

	public function assertSuccess(array $result): void
	{
		$status = (int)($result['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException(sprintf('Webhook failed (HTTP %d).', $status));
		}
	}
}
