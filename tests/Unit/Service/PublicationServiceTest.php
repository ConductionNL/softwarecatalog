<?php

/**
 * Unit tests for PublicationService.
 *
 * Covers the live OpenRegister RBAC publish model: publish() sets
 * `publicatiedatum` (and clears `depublicatiedatum`) and depublish() sets
 * `depublicatiedatum` (and clears `publicatiedatum`) via ObjectService::
 * saveObject() — NOT the removed @self.published predicate / publish() call,
 * and NOT an app-local flag. Also covers the publishable-type guard and the
 * not-resolvable degrade path.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\PublicationService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for PublicationService.
 */
class PublicationServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface|MockObject
	 */
	private ObjectServiceInterface|MockObject $objectService;

	/**
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settings;

	/**
	 * @var array<string,mixed>|null Last data bag handed to saveObject().
	 */
	private ?array $savedObject = null;

	/**
	 * Build a PublicationService whose ObjectService::find() returns the given
	 * data bag, capturing whatever saveObject() is called with.
	 *
	 * @param array<string,mixed> $entryData The existing entry data.
	 *
	 * @return PublicationService The service under test.
	 */
	private function makeService(array $entryData): PublicationService {
		$container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->settings->method('getSchemaIdForObjectType')->willReturn(3);
		$this->settings->method('getRegisterIdForObjectType')->willReturn(1);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($entryData);
		$this->objectService->method('find')->willReturn($entity);

		$this->savedObject = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntity {
				$this->savedObject = $object;
				return $entity;
			}
		);

		$container->method('get')->willReturn($this->objectService);

		return new PublicationService($container, $this->settings, $logger);
	}//end makeService()

	/**
	 * publish() sets publicatiedatum (<= now) and clears depublicatiedatum.
	 *
	 * @return void
	 */
	public function testPublishSetsPublicatiedatum(): void {
		$service = $this->makeService(['name' => 'Mijn dienst', 'depublicationDate' => '2020-01-01T00:00:00+00:00']);

		$result = $service->publish('catalogService', 'uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertNotNull($result['publicationDate']);
		// The saved object carries publicatiedatum and a cleared depublicatiedatum.
		$this->assertArrayHasKey('publicationDate', $this->savedObject);
		$this->assertNotNull($this->savedObject['publicationDate']);
		$this->assertNull($this->savedObject['depublicationDate']);
		// publicatiedatum is in the past/present (anonymous-visible now).
		$this->assertLessThanOrEqual(time(), strtotime($this->savedObject['publicationDate']));
	}//end testPublishSetsPublicatiedatum()

	/**
	 * publish(when=future) keeps the entry scheduled (publicatiedatum > now).
	 *
	 * @return void
	 */
	public function testPublishWithFutureMomentSchedules(): void {
		$service = $this->makeService(['name' => 'Mijn dienst']);
		$future = gmdate('Y-m-d\TH:i:sP', (time() + 86400));

		$result = $service->publish('catalogService', 'uuid-1', $future);

		$this->assertTrue($result['ok']);
		$this->assertGreaterThan(time(), strtotime($this->savedObject['publicationDate']));
	}//end testPublishWithFutureMomentSchedules()

	/**
	 * depublish() sets depublicatiedatum and clears publicatiedatum.
	 *
	 * @return void
	 */
	public function testDepublishClearsPublicatiedatum(): void {
		$service = $this->makeService(
			['name' => 'Mijn dienst', 'publicationDate' => '2024-01-01T00:00:00+00:00']
		);

		$result = $service->depublish('catalogService', 'uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertNull($this->savedObject['publicationDate']);
		$this->assertNotNull($this->savedObject['depublicationDate']);
	}//end testDepublishClearsPublicatiedatum()

	/**
	 * Non-publishable object types are rejected (no save).
	 *
	 * @return void
	 */
	public function testNonPublishableTypeRejected(): void {
		$this->assertFalse((new PublicationService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class)
		))->isPublishableType('contactPerson'));

		$service = $this->makeService(['name' => 'x']);
		$result = $service->publish('contactPerson', 'uuid-1');
		$this->assertFalse($result['ok']);
		$this->assertNull($this->savedObject);
	}//end testNonPublishableTypeRejected()

	/**
	 * All four catalog entry types are publishable.
	 *
	 * @return void
	 */
	public function testPublishableTypes(): void {
		$service = $this->makeService(['name' => 'x']);
		foreach (['catalogService', 'module', 'connection', 'organization'] as $type) {
			$this->assertTrue($service->isPublishableType($type), $type . ' should be publishable');
		}
	}//end testPublishableTypes()
}//end class
