<?php

/**
 * Unit tests for SettingsService's EOL sync config/status persistence.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Round-trips the EOL sync config/status blobs through an in-memory
 * IAppConfig double so the real (non-mocked) SettingsService logic is
 * exercised end to end.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them. `phpcs.xml` only scans `lib`, so this file has never been
 * measured by the gate and carried 34 findings of exactly that shape.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SettingsServiceEolConfigTest extends TestCase {

	/**
	 * Build a SettingsService backed by an in-memory IAppConfig store.
	 *
	 * @param array $store Reference to the backing key/value store.
	 *
	 * @return SettingsService
	 */
	private function makeService(array &$store): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end makeService()

	/**
	 * With nothing configured yet, getEolSyncConfig() returns the
	 * documented defaults matching what endoflife-date-source provisions,
	 * and the feature is disabled by default.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-register-and-schema-names-are-configurable-not-hardcoded
	 * @return void
	 */
	public function testDefaultsMatchEndoflifeDateSourceProvisioning(): void {
		$store = [];
		$service = $this->makeService($store);

		$config = $service->getEolSyncConfig();

		$this->assertFalse($config['enabled']);
		// These three are the slugs Integriq actually provisions today. They
		// were `openconnector`, `eolProduct` and `eolCycle`; the register moved
		// in the fleet rename and the two schemas moved to snake_case. A stale
		// default here does not fail loudly — an unresolvable schema reads as
		// "this module has no EOL data" — so the assertion is the guard.
		$this->assertSame('integriq', $config['register']);
		$this->assertSame('eol_product', $config['productSchema']);
		$this->assertSame('eol_cycle', $config['cycleSchema']);
		$this->assertSame(86400, $config['intervalSeconds']);
	}//end testDefaultsMatchEndoflifeDateSourceProvisioning()

	/**
	 * updateEolSyncConfig() persists overrides and getEolSyncConfig() then
	 * reflects them — register/schema names are settings, not constants
	 * (design.md Decision 5).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-register-and-schema-names-are-configurable-not-hardcoded
	 * @return void
	 */
	public function testUpdateConfigPersistsAndRoundTrips(): void {
		$store = [];
		$service = $this->makeService($store);

		$result = $service->updateEolSyncConfig(
			[
				'enabled' => true,
				'register' => 'custom-register',
				'productSchema' => 'custom-product',
				'cycleSchema' => 'custom-cycle',
			]
		);

		$this->assertTrue($result['success']);

		$reloaded = $service->getEolSyncConfig();
		$this->assertTrue($reloaded['enabled']);
		$this->assertSame('custom-register', $reloaded['register']);
		$this->assertSame('custom-product', $reloaded['productSchema']);
		$this->assertSame('custom-cycle', $reloaded['cycleSchema']);
		// Untouched key keeps its default.
		$this->assertSame(86400, $reloaded['intervalSeconds']);
	}//end testUpdateConfigPersistsAndRoundTrips()

	/**
	 * A partial update (e.g. only toggling `enabled`) never clobbers the
	 * other already-persisted fields.
	 *
	 * @return void
	 */
	public function testPartialUpdatePreservesOtherFields(): void {
		$store = [];
		$service = $this->makeService($store);

		$service->updateEolSyncConfig(['register' => 'custom-register', 'enabled' => true]);
		$service->updateEolSyncConfig(['enabled' => false]);

		$config = $service->getEolSyncConfig();
		$this->assertFalse($config['enabled']);
		$this->assertSame('custom-register', $config['register']);
	}//end testPartialUpdatePreservesOtherFields()

	/**
	 * Before any sync has run, status defaults to unavailable/never-run —
	 * distinct from "configured but zero matches yet" (design.md
	 * Decision 6).
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 * @return void
	 */
	public function testDefaultStatusIsUnavailableNeverRun(): void {
		$store = [];
		$service = $this->makeService($store);

		$status = $service->getEolSyncStatus();

		$this->assertFalse($status['available']);
		$this->assertSame('not-yet-run', $status['reason']);
		$this->assertSame(0, $status['matched']);
		$this->assertNull($status['lastRunAt']);
	}//end testDefaultStatusIsUnavailableNeverRun()

	/**
	 * setEolSyncStatus()/getEolSyncStatus() round-trip a recorded run
	 * outcome.
	 *
	 * @return void
	 */
	public function testStatusRoundTripsAfterARun(): void {
		$store = [];
		$service = $this->makeService($store);

		$service->setEolSyncStatus(
			[
				'available' => true,
				'reason' => null,
				'matched' => 4,
				'skipped' => 2,
				'lastRunAt' => '2026-07-23T12:00:00+00:00',
			]
		);

		$status = $service->getEolSyncStatus();
		$this->assertTrue($status['available']);
		$this->assertSame(4, $status['matched']);
		$this->assertSame(2, $status['skipped']);
		$this->assertSame('2026-07-23T12:00:00+00:00', $status['lastRunAt']);
	}//end testStatusRoundTripsAfterARun()
}//end class
