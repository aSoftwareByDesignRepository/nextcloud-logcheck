<?php

declare(strict_types=1);

/**
 * Host unit runs: OCP stubs only (no Nextcloud DB).
 * Docker integration: load lib/base.php when under /var/www/html or LOGCHECK_INTEGRATION=1.
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}
if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}

$inDockerTree = str_starts_with(__DIR__, '/var/www/html/');
$loadNc = $inDockerTree || getenv('LOGCHECK_INTEGRATION') === '1';

$base = null;
if ($loadNc) {
	$candidates = [];
	$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
	if ($nextcloudRoot !== '') {
		$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
	}
	$candidates[] = __DIR__ . '/../../../lib/base.php';
	$candidates[] = '/var/www/html/lib/base.php';
	foreach ($candidates as $candidate) {
		if (is_file($candidate)) {
			$base = $candidate;
			break;
		}
	}
}

if ($base !== null) {
	require_once $base;
	$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
	if (is_file($integrationBootstrap)) {
		require_once $integrationBootstrap;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

if (class_exists(\DG\BypassFinals::class)) {
	\DG\BypassFinals::allowPaths([
		'*/apps/logcheck/lib/*',
		'*/logcheck/lib/*',
	]);
	\DG\BypassFinals::enable(bypassReadOnly: false);
}

if (!class_exists(\Test\TestCase::class)) {
	eval('namespace Test; class TestCase extends \\PHPUnit\\Framework\\TestCase {}');
}

if (!class_exists(\Symfony\Component\Console\Command\Command::class, false)) {
	// Must define a constructor — PHP 8+ errors on parent::__construct() when parent has none.
	eval('namespace Symfony\Component\Console\Command; class Command { public const SUCCESS = 0; public const FAILURE = 1; public function __construct() {} }');
}

// Host unit runs have no symfony/console (it ships inside the NC container).
// Stub only the members the commands touch so PHPUnit can mock/spy them.
if (!interface_exists(\Symfony\Component\Console\Input\InputInterface::class, false)) {
	eval('namespace Symfony\Component\Console\Input; interface InputInterface {
		public function getArgument(string $name): mixed;
		public function getOption(string $name): mixed;
		public function isInteractive(): bool;
	}');
}
if (!interface_exists(\Symfony\Component\Console\Output\OutputInterface::class, false)) {
	eval('namespace Symfony\Component\Console\Output; interface OutputInterface {
		public const VERBOSITY_NORMAL = 2;
		public function writeln($messages, int $options = 0): void;
		public function write($messages, bool $newline = false, int $options = 0): void;
		public function isDecorated(): bool;
		public function getVerbosity(): int;
	}');
}
if (!class_exists(\Symfony\Component\Console\Style\SymfonyStyle::class, false)) {
	eval('namespace Symfony\Component\Console\Style; class SymfonyStyle {
		public function __construct($input, $output) {}
		public function title($message): void {}
		public function section($message): void {}
		public function text($message): void {}
		public function note($message): void {}
		public function warning($message): void {}
		public function error($message): void {}
		public function success($message): void {}
		public function table(array $headers, array $rows): void {}
		public function newLine(int $count = 1): void {}
		public function writeln($message): void {}
		public function confirm(string $question, bool $default = true): bool { return $default; }
	}');
}

if ($base === null && !interface_exists(\OC\Hooks\Emitter::class, false)) {
	eval('namespace OC\\Hooks; interface Emitter {}');
}

$ocpStubs = dirname(__DIR__, 3) . '/scripts/phpunit-ocp-doctrine-stubs.php';
if ($base === null && is_file($ocpStubs)) {
	require_once $ocpStubs;
} elseif ($base === null) {
	if (!class_exists(\Doctrine\DBAL\ParameterType::class)) {
		eval('namespace Doctrine\\DBAL; final class ParameterType { public const NULL = 0; public const INTEGER = 1; public const STRING = 2; public const LARGE_OBJECT = 3; }');
	}
}
