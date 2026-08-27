<?php

declare(strict_types=1);

/**
 * Minimal Nextcloud template helpers for Support us render tests.
 */

if (!function_exists('p')) {
	function p(string $string): void
	{
		print(htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'));
	}
}

if (!function_exists('print_unescaped')) {
	function print_unescaped(string $string): void
	{
		print($string);
	}
}
