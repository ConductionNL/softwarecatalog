<?php

/**
 * Regression tests for catalog object-type register/schema resolution (#375).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
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
 * Locks the resolution of every catalog object type to the voorzieningen
 * register + its `<type>_schema`.
 *
 * Regression for #375: `beoordeeling` (and several sibling catalog types) were
 * absent from getSchemaIdForObjectType()'s key map, and getRegisterIdForObjectType()
 * only mapped the voorzieningen register for organisatie/contactpersoon — so the
 * whole ratings feature (submit, aggregate AND moderation, which all resolve the
 * beoordeeling target through these two methods) read "register/schema not
 * configured" on every call.
 */
class SettingsServiceCatalogTypeResolutionTest extends TestCase {

	/**
	 * Build a SettingsService whose voorzieningen_config carries the register
	 * and a `<type>_schema` id for each catalog type.
	 *
	 * @return SettingsService
	 */
	private function makeService(): SettingsService {
		$voorzieningenConfig = json_encode(
			[
				'register' => '11',
				'organisatie_schema' => '39',
				'contactpersoon_schema' => '38',
				'module_schema' => '50',
				'compliancy_schema' => '51',
				'moduleVersie_schema' => '52',
				'dienst_schema' => '36',
				'gebruik_schema' => '40',
				'contract_schema' => '41',
				'koppeling_schema' => '42',
				'suite_schema' => '35',
				'kwetsbaarheid_schema' => '37',
				'sector_schema' => '34',
				'beoordeeling_schema' => '43',
			]
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($voorzieningenConfig): string {
				if ($key === 'voorzieningen_config') {
					return $voorzieningenConfig;
				}

				return $default;
			}
		);
		$config->method('hasKey')->willReturn(true);

		// The unknown-type path falls through to the resolver, which probes the
		// installed-apps list; mock it so that probe does not hit a null haystack.
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $appManager,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);

	}//end makeService()

	/**
	 * `beoordeeling` — the config key the ratings feature resolves through —
	 * must map to schema 43 and register 11 for the `software-review` type.
	 * The object TYPE moved with the schema slug; the config KEY did not.
	 *
	 * @return void
	 */
	public function testBeoordeelingResolvesRegisterAndSchema(): void {
		$service = $this->makeService();

		$this->assertSame(43, $service->getSchemaIdForObjectType('software-review'), 'schema id');
		$this->assertSame(11, $service->getRegisterIdForObjectType('software-review'), 'register id');

	}//end testBeoordeelingResolvesRegisterAndSchema()

	/**
	 * Every catalog type present in the config resolves to a non-null register
	 * and schema — none silently reads as "not configured".
	 *
	 * @return void
	 */
	public function testEveryCatalogTypeResolves(): void {
		$service = $this->makeService();

		$types = [
			'module' => 50,
			'catalogService' => 36,
			'usage' => 40,
			'catalogContract' => 41,
			'connection' => 42,
			'suite' => 35,
			'vulnerability' => 37,
			'sector' => 34,
			'compliancy' => 51,
			'moduleVersion' => 52,
			'software-review' => 43,
		];

		foreach ($types as $type => $schemaId) {
			$this->assertSame($schemaId, $service->getSchemaIdForObjectType($type), "schema for {$type}");
			$this->assertSame(11, $service->getRegisterIdForObjectType($type), "register for {$type}");
		}

	}//end testEveryCatalogTypeResolves()

	/**
	 * A type with no config key stays unresolved (no false positive).
	 *
	 * @return void
	 */
	public function testUnknownTypeStaysNull(): void {
		$service = $this->makeService();

		$this->assertNull($service->getSchemaIdForObjectType('doesNotExist'));
		$this->assertNull($service->getRegisterIdForObjectType('doesNotExist'));

	}//end testUnknownTypeStaysNull()

}//end class
