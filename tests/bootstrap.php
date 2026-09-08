<?php

/**
 * Bootstrap file for PHPUnit tests
 *
 * @category Test
 * @package  OCA\Stackiq\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://SoftwareCatalog.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// THE OpenRegister CONTRACT INTERFACES, OPTED INTO RATHER THAN AUTOLOADED.
//
// conduction/hydra-gates claims `OCA\OpenRegister\Contract\` as a RUNTIME psr-4
// prefix, so consumers get these interfaces implicitly. That prefix is LONGER
// than openregister's own `OCA\OpenRegister\` -> `lib/`, and PSR-4 is
// longest-prefix-wins, so whichever app's autoloader registers first defines
// OpenRegister's contract for the whole process (ConductionNL/.github#531).
//
// Loaded here, immediately after the autoloader and before the OpenRegister
// stubs below, for the same reason those stubs are loaded early: what is
// declared first wins, and the contract must exist before anything implementing
// it is declared.
//
// interface_exists() is order-independent — it asks whether the interface is
// RESOLVABLE, not who registered first. Appending a fallback autoloader does
// NOT work: spl_autoload_register appends relative to registration order, and
// that order across independently loaded apps is what nobody controls.
foreach (['ObjectEntityInterface', 'ObjectServiceInterface'] as $contract) {
	if (interface_exists('\\OCA\\OpenRegister\\Contract\\' . $contract) === false) {
		$shipped = __DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts/' . $contract . '.php';
		if (file_exists($shipped) === true) {
			require_once $shipped;
		}
	}
}

// OpenRegister test stubs. The real OCA\OpenRegister\Db\ObjectEntity has
// __call magic getters that PHPUnit cannot configure on a mock, so the unit
// tests use the explicit stub in tests/Stubs/. It is loaded HERE, BEFORE
// Nextcloud's app bootstrap, so the stub class wins over the real OR class
// when PHPUnit later resolves `OCA\OpenRegister\Db\ObjectEntity` for mock
// generation. We do NOT use a composer `autoload-dev` PSR-4 mapping for the
// foreign `OCA\OpenRegister\` namespace — that would shadow the real
// OpenRegister classes in any deployment whose vendor/ retains dev autoload
// entries (breaking every OR-backed app, see PR #232 / issue #230).
foreach (glob(__DIR__ . '/Stubs/{,**/}*.php', GLOB_BRACE) ?: [] as $stub) {
	require_once $stub;
}

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed (the workspace
 * checkout above apps-extra/ has a 0-byte config/config.php) still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB and 6 GB of swap in one
 * openregister run on 2026-09-08). So the decision has to be made BEFORE
 * base.php is loaded, and the only cheap signal is the `installed` flag in
 * config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function stackiq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}//end stackiq_nc_root_is_installed()

// Bootstrap Nextcloud if not already done. Only an INSTALLED root is booted;
// a bare source tree runs in pure-unit mode with the composer autoload and the
// stubs above. NC's tests/autoload.php requires lib/base.php itself, so it
// sits behind the same guard, and so do the OC_App calls that need a booted
// server.
if (!defined('OC_CONSOLE')) {
	$stackiqNcRoot = realpath(__DIR__ . '/../../..');
	if ($stackiqNcRoot !== false && file_exists($stackiqNcRoot . '/lib/base.php') === true) {
		if (stackiq_nc_root_is_installed($stackiqNcRoot) === true) {
			try {
				require_once $stackiqNcRoot . '/lib/base.php';

				// Load Test\TestCase and other NC test classes (NC convention).
				if (file_exists($stackiqNcRoot . '/tests/autoload.php') === true) {
					require_once $stackiqNcRoot . '/tests/autoload.php';
				}
			} catch (\Throwable $e) {
				// The root passed the installed check but base.php still
				// failed (unreachable database, broken app, ...). `OC::$server`
				// is a typed static that already holds a half-built container,
				// so falling through to "composer autoload only" would be a lie
				// that costs gigabytes. Stop the run and say why.
				fwrite(
					STDERR,
					sprintf(
						"[stackiq/tests/bootstrap] Nextcloud root at %s could not be initialised (%s).\n"
						. "  A half-booted server cannot be undone, so the run stops here rather than pretending to be pure-unit.\n"
						. "  Fix the instance, or define OC_CONSOLE (phpunit-unit.xml uses tests/bootstrap-unit.php) for pure-unit mode.\n",
						$stackiqNcRoot,
						$e->getMessage()
					)
				);
				exit(1);
			}

			// Load all enabled apps, then our specific app, then clear hooks
			// for testing.
			\OC_App::loadApps();
			\OC_App::loadApp('stackiq');
			OC_Hook::clear();
		} else {
			fwrite(
				STDERR,
				sprintf(
					"[stackiq/tests/bootstrap] Nextcloud root at %s is not an installed instance (config/config.php lacks installed => true); "
					. "skipping lib/base.php and running with composer autoload only (pure-unit mode).\n",
					$stackiqNcRoot
				)
			);
		}
	}

	unset($stackiqNcRoot);
}
