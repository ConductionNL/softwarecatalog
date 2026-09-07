<?php
/**
 * Re-points a stored EOL sync configuration at the register and schema slugs
 * that still exist.
 *
 * The EOL feature reads endoflife.date lifecycle data out of a register the
 * sibling Integriq app provisions. It addresses that register and its two
 * schemas by SLUG, held in the `eol_sync_config` app-config blob, and all three
 * slugs have moved:
 *
 *   - the register `openconnector` became `integriq` in the fleet rename
 *   - the schemas `eolProduct` and `eolCycle` became `eol_product` and
 *     `eol_cycle`, the only two camelCase slugs in that register
 *
 * The defaults in `SettingsService` move with them, so a fresh install and an
 * install that never opted in are already correct. This step is for the install
 * whose admin DID opt in: the blob then holds the old literals, the default
 * never applies, and the configuration silently addresses schemas that no
 * longer answer to those names.
 *
 * WHY IT FAILS SILENTLY, WHICH IS WHY THIS STEP EXISTS. An unresolvable
 * register or schema reads as "this module has no EOL data" and not as an
 * error: `EolSyncService` logs and skips the module. A stale slug therefore
 * produces a feature that runs, reports success, and stamps nothing.
 *
 * NON-DESTRUCTIVE AND IDEMPOTENT. It rewrites a value ONLY when what is stored
 * is exactly one of the old literals, so an admin who deliberately points the
 * feature at their own register is never touched, and a second run finds
 * nothing to do. It never throws: it also runs under `<install>`, where an
 * escaping exception aborts the install and the app never enables at all.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rewrites the stale slugs in a stored `eol_sync_config`.
 *
 * @spec openspec/specs/eol-feed-integration/spec.md
 */
class RepointEolSyncConfig implements IRepairStep {

	/**
	 * The app-config key holding the EOL sync configuration blob.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'eol_sync_config';

	/**
	 * Config field => [old value => new value].
	 *
	 * Guarded per field rather than by a blanket string replace, so a value that
	 * merely contains an old slug is never touched.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const SLUG_MAP = [
		'register' => ['openconnector' => 'integriq'],
		'productSchema' => ['eolProduct' => 'eol_product'],
		'cycleSchema' => ['eolCycle' => 'eol_cycle'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig App configuration.
	 * @param LoggerInterface $logger    Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getName(): string {
		return 'Re-point the EOL sync configuration at the current register and schema slugs';
	}//end getName()

	/**
	 * Rewrite the old slugs in a decoded configuration.
	 *
	 * Pure, so the decision table is testable without app config. Returns the
	 * rewritten configuration and how many fields changed.
	 *
	 * @param array<string, mixed> $config The decoded configuration.
	 *
	 * @return array{config: array<string, mixed>, changed: int}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function repoint(array $config): array {
		$changed = 0;

		foreach (self::SLUG_MAP as $field => $map) {
			if (array_key_exists($field, $config) === false) {
				continue;
			}

			$current = $config[$field];
			if (is_string($current) === false) {
				continue;
			}

			$replacement = ($map[$current] ?? null);
			if ($replacement === null) {
				continue;
			}

			$config[$field] = $replacement;
			$changed++;
		}

		return ['config' => $config, 'changed' => $changed];
	}//end repoint()

	/**
	 * Re-point a stored configuration, if there is one and it is stale.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function run(IOutput $output): void {
		try {
			$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RepointEolSyncConfig: could not read the EOL sync configuration; leaving it alone.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		if ($raw === '') {
			$output->info('RepointEolSyncConfig: no stored configuration; the defaults already name the current slugs.');
			return;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->warning(
				'RepointEolSyncConfig: the stored EOL sync configuration is not a JSON object; leaving it alone.'
			);
			return;
		}

		$result = $this->repoint(config: $decoded);
		if ($result['changed'] === 0) {
			$output->info('RepointEolSyncConfig: the stored configuration already names the current slugs.');
			return;
		}

		$encoded = json_encode($result['config']);
		if (is_string($encoded) === false) {
			$this->logger->warning(
				'RepointEolSyncConfig: could not re-encode the EOL sync configuration; leaving it alone.'
			);
			return;
		}

		try {
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $encoded);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RepointEolSyncConfig: could not write the EOL sync configuration.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$output->info(
			sprintf(
				'RepointEolSyncConfig: %d stale slug(s) re-pointed in the stored configuration.',
				$result['changed']
			)
		);
	}//end run()
}//end class
