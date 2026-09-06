<?php

/**
 * The organisation lookup falls back to OpenRegister's shared projection.
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
 * Locks the ORDER of the organisation lookup, which is the whole change.
 *
 * `organization` was the fleet's most persistent slug collision: a schema slug
 * is global per organisation, so this app and opencatalogi both declaring one
 * meant `SchemaMapper::find()` returned whichever row it reached first.
 * OpenRegister's `nc-organisation` projection is the single owner.
 *
 * It resolves LAST. Putting it first was the obvious shape and the wrong one:
 * the projection always exists once OpenRegister ships it, so it would win on
 * an instance that has not yet run `openregister:organisations:adopt`, and that
 * instance's rows still live in the local schema. Every organisation picker
 * would come back empty, with nothing reporting why. The first two tests below
 * are the same lookup with and without a local schema, which is the only way to
 * tell "resolves last" from "resolves at all".
 */
class SettingsServiceSharedOrganisationTest extends TestCase {

	/**
	 * Build a SettingsService whose config carries the given organisation
	 * schema id, and whose container answers with a schema mapper (or throws).
	 *
	 * @param string|null $localSchemaId The configured `organisatie_schema`, or
	 *                                   null to configure none.
	 * @param int|null    $projectionId  The id the schema mapper returns for
	 *                                   `nc-organisation`, or null for a mapper
	 *                                   that throws (no such schema).
	 * @param bool        $openRegister  Whether OpenRegister is installed.
	 *
	 * @return SettingsService The service under test.
	 */
	private function makeService(
		?string $localSchemaId,
		?int $projectionId,
		bool $openRegister=true
	): SettingsService {
		$voorzieningen = ['register' => '11'];
		if ($localSchemaId !== null) {
			$voorzieningen['organisatie_schema'] = $localSchemaId;
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default='') use ($voorzieningen): string {
				if ($key === 'voorzieningen_config') {
					return json_encode($voorzieningen);
				}

				return $default;
			}
		);
		$config->method('hasKey')->willReturn(true);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(
			$openRegister === true ? ['openregister'] : []
		);
		$appManager->method('isInstalled')->willReturn($openRegister);
		// Comfortably above MIN_OPENREGISTER_VERSION, so the version compare in
		// isOpenRegisterInstalled() is not what these tests are measuring.
		$appManager->method('getAppVersion')->willReturn('99.0.0');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($projectionId): object {
				if ($id !== 'OCA\OpenRegister\Db\SchemaMapper') {
					throw new \RuntimeException('unexpected service '.$id);
				}

				if ($projectionId === null) {
					// What an OpenRegister with no such schema actually does:
					// `find()` throws rather than returning null.
					throw new \RuntimeException('schema not found');
				}

				$schema = new class($projectionId) {

					/**
					 * @param int $id The schema id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * @return int The schema id.
					 */
					public function getId(): int {
						return $this->id;
					}
				};

				return new class($schema) {

					/**
					 * @param object $schema The schema to answer with.
					 */
					public function __construct(private object $schema) {
					}

					/**
					 * @param string $slug The slug looked up.
					 *
					 * @return object The schema.
					 */
					public function find(string $slug): object {
						return $this->schema;
					}
				};
			}
		);

		return new SettingsService(
			config: $config,
			request: $this->createMock(IRequest::class),
			container: $container,
			appManager: $appManager,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);

	}//end makeService()

	/**
	 * An instance that still has its own organisation schema keeps resolving to
	 * it. Its rows live there, and the projection cannot see them.
	 *
	 * @return void
	 */
	public function testALocalSchemaWinsOverTheProjection(): void {
		$service = $this->makeService(localSchemaId: '39', projectionId: 777);

		$this->assertSame(
			39,
			$service->getSchemaIdForObjectType('organization'),
			'the local schema must win while it exists'
		);

	}//end testALocalSchemaWinsOverTheProjection()

	/**
	 * With no local schema configured, which is a fresh install or one whose
	 * local schema has been pruned after adoption, the lookup lands on the
	 * shared projection instead of reading as "not configured".
	 *
	 * @return void
	 */
	public function testWithNoLocalSchemaItResolvesToTheProjection(): void {
		$service = $this->makeService(localSchemaId: null, projectionId: 777);

		$this->assertSame(
			777,
			$service->getSchemaIdForObjectType('organization'),
			'with nothing local, the projection answers'
		);

	}//end testWithNoLocalSchemaItResolvesToTheProjection()

	/**
	 * An OpenRegister too old to carry the projection resolves to null rather
	 * than to a fabricated id, so the caller reports "not configured" the way it
	 * did before this fallback existed.
	 *
	 * @return void
	 */
	public function testAMissingProjectionResolvesToNullNotAnError(): void {
		$service = $this->makeService(localSchemaId: null, projectionId: null);

		$this->assertNull(
			$service->getSchemaIdForObjectType('organization'),
			'a missing projection resolves to null'
		);

	}//end testAMissingProjectionResolvesToNullNotAnError()

	/**
	 * Without OpenRegister the projection is not even asked for. The container
	 * mock throws on any other service id, so a lookup that reached it would
	 * surface as an error rather than as this assertion.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheProjectionIsNotConsulted(): void {
		$service = $this->makeService(
			localSchemaId: null,
			projectionId: 777,
			openRegister: false
		);

		$this->assertNull(
			$service->getSchemaIdForObjectType('organization'),
			'no OpenRegister, no projection'
		);

	}//end testWithoutOpenRegisterTheProjectionIsNotConsulted()

}//end class
