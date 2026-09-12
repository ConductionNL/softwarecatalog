<?php

/**
 * Unit tests for EolSyncService — config resolution, graceful degradation,
 * and the end-to-end match-and-stamp orchestration.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-softwarecatalog-performs-no-direct-http-to-the-eol-feed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers every degradation path plus the happy-path orchestration.
 */
class EolSyncServiceTest extends TestCase {

	private const DEFAULT_CONFIG = [
		'enabled' => true,
		'register' => 'integriq',
		'productSchema' => 'eol_product',
		'cycleSchema' => 'eol_cycle',
		'intervalSeconds' => 86400,
	];

	/**
	 * A fixed time factory so status timestamps are deterministic.
	 *
	 * @return ITimeFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function timeFactory(): ITimeFactory {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-23T12:00:00+00:00'));
		return $timeFactory;
	}//end timeFactory()

	/**
	 * Disabled config degrades without ever touching ObjectService.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-missing-register-degrades-to-manual-only-not-an-error
	 * @return void
	 */
	public function testDisabledConfigDegradesGracefully(): void {
		$config = self::DEFAULT_CONFIG;
		$config['enabled'] = false;

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn($config);
		// ObjectService must never be requested when disabled.
		$settingsService->expects($this->never())->method('getObjectService');
		$settingsService->expects($this->once())->method('setEolSyncStatus')->with(
			$this->callback(function (array $status): bool {
				return $status['available'] === false && $status['reason'] === 'disabled'
					&& $status['matched'] === 0 && $status['skipped'] === 0;
			})
		);

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertFalse($status['available']);
		$this->assertSame('disabled', $status['reason']);
	}//end testDisabledConfigDegradesGracefully()

	/**
	 * OpenRegister absent degrades gracefully — no exception, no object
	 * touched.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-core-lifecycle-capability-is-unaffected-by-feed-absence
	 * @return void
	 */
	public function testOpenRegisterNotInstalledDegradesGracefully(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->method('isOpenRegisterInstalled')->willReturn(false);
		$settingsService->expects($this->never())->method('getObjectService');

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertFalse($status['available']);
		$this->assertSame('openregister-not-installed', $status['reason']);
	}//end testOpenRegisterNotInstalledDegradesGracefully()

	/**
	 * The configured register/schema failing to resolve degrades gracefully
	 * — no exception propagates to the caller.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-missing-register-degrades-to-manual-only-not-an-error
	 * @return void
	 */
	public function testUnresolvableRegisterDegradesGracefully(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->method('isOpenRegisterInstalled')->willReturn(true);
		$settingsService->method('getRegisterIdForObjectType')->willReturn(1);
		$settingsService->method('getSchemaIdForObjectType')->willReturn(2);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willThrowException(new \RuntimeException('register not found'));
		$settingsService->method('getObjectService')->willReturn($objectService);

		$objectService->expects($this->never())->method('saveObject');

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertFalse($status['available']);
		$this->assertSame('eol-register-or-schema-not-found', $status['reason']);
	}//end testUnresolvableRegisterDegradesGracefully()

	/**
	 * Module/moduleVersie schema not configured degrades gracefully (a
	 * fresh install where stackiq itself is not yet configured).
	 *
	 * @return void
	 */
	public function testModuleSchemaNotConfiguredDegradesGracefully(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->method('isOpenRegisterInstalled')->willReturn(true);
		$settingsService->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$settingsService->method('getRegisterIdForObjectType')->willReturn(null);

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertFalse($status['available']);
		$this->assertSame('module-schema-not-configured', $status['reason']);
	}//end testModuleSchemaNotConfiguredDegradesGracefully()

	/**
	 * The happy path: one mapped module with one matching moduleVersie is
	 * stamped and saved; the status reports matched/skipped counts.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-the-scheduled-job-runs-the-match
	 * @return void
	 */
	public function testSuccessfulRunMatchesStampsAndReportsStatus(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->method('isOpenRegisterInstalled')->willReturn(true);
		$settingsService->method('getRegisterIdForObjectType')->willReturn(10);
		$settingsService->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['module', 20],
				['moduleVersion', 21],
			]
		);

		$module = ['id' => 'module-uuid-1', 'eolProductSlug' => 'postgresql'];
		$moduleVersion = ['id' => 'mv-uuid-1', 'module' => 'module-uuid-1', 'version' => '16.2', 'shortDescription' => 'keep me'];
		$cycle = ['product' => 'postgresql', 'cycle' => '16', 'eol' => '2028-11-09'];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($module, $moduleVersion): array {
				if (($query['@self']['schema'] ?? null) === 20) {
					return [$module];
				}
				if (($query['@self']['schema'] ?? null) === 21) {
					return [$moduleVersion];
				}
				return [];
			}
		);
		$objectService->method('findAll')->willReturn([$cycle]);

		$savedObjects = [];
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$savedObjects) {
				$savedObjects[] = $object;
				return $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
			}
		);

		$settingsService->method('getObjectService')->willReturn($objectService);

		$recordedStatus = null;
		$settingsService->expects($this->once())->method('setEolSyncStatus')->with(
			$this->callback(function (array $status) use (&$recordedStatus): bool {
				$recordedStatus = $status;
				return true;
			})
		);

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertTrue($status['available']);
		$this->assertSame(1, $status['matched']);
		$this->assertSame(0, $status['skipped']);
		$this->assertSame($recordedStatus, $status);

		$this->assertCount(1, $savedObjects);
		$this->assertSame('2028-11-09', $savedObjects[0]['dateEndSupport']);
		$this->assertSame('endoflife.date', $savedObjects[0]['eolSource']);
		$this->assertSame('keep me', $savedObjects[0]['shortDescription']);
	}//end testSuccessfulRunMatchesStampsAndReportsStatus()

	/**
	 * A module with no eolProductSlug is never read or written — the
	 * matcher must include zero unmapped modules (spec "no read, no
	 * write").
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#scenario-a-mapped-module-is-eligible-for-matching
	 * @return void
	 */
	public function testUnmappedModuleIsNeverProcessed(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->method('isOpenRegisterInstalled')->willReturn(true);
		$settingsService->method('getRegisterIdForObjectType')->willReturn(10);
		$settingsService->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['module', 20],
				['moduleVersion', 21],
			]
		);

		$unmappedModule = ['id' => 'module-uuid-2', 'eolProductSlug' => ''];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($unmappedModule): array {
				if (($query['@self']['schema'] ?? null) === 20) {
					return [$unmappedModule];
				}
				// The moduleVersie schema (21) must never be queried for an
				// unmapped module.
				$this->fail('moduleVersie must not be read for an unmapped module');
			}
		);
		$objectService->expects($this->never())->method('findAll');
		$objectService->expects($this->never())->method('saveObject');

		$settingsService->method('getObjectService')->willReturn($objectService);

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$status = $service->run();

		$this->assertTrue($status['available']);
		$this->assertSame(0, $status['matched']);
		$this->assertSame(0, $status['skipped']);
	}//end testUnmappedModuleIsNeverProcessed()

	/**
	 * getConfig()/updateConfig()/getStatus() are thin delegators onto
	 * SettingsService — the actual persistence/merge behaviour is covered
	 * by SettingsServiceEolConfigTest.
	 *
	 * @return void
	 */
	public function testConfigAndStatusDelegateToSettingsService(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->expects($this->once())->method('getEolSyncConfig')->willReturn(self::DEFAULT_CONFIG);
		$settingsService->expects($this->once())->method('updateEolSyncConfig')->with(['enabled' => false])
			->willReturn(['success' => true, 'config' => self::DEFAULT_CONFIG]);
		$settingsService->expects($this->once())->method('getEolSyncStatus')->willReturn(
			['available' => true, 'reason' => null, 'matched' => 3, 'skipped' => 1, 'lastRunAt' => '2026-07-23T12:00:00+00:00']
		);

		$service = new EolSyncService(
			settingsService: $settingsService,
			matcher: new EolMatcherService(),
			timeFactory: $this->timeFactory(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(self::DEFAULT_CONFIG, $service->getConfig());
		$this->assertSame(['success' => true, 'config' => self::DEFAULT_CONFIG], $service->updateConfig(['enabled' => false]));
		$this->assertSame(3, $service->getStatus()['matched']);
	}//end testConfigAndStatusDelegateToSettingsService()
}//end class
