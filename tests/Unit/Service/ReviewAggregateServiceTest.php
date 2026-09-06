<?php

/**
 * Unit tests for ReviewAggregateService (catalog-ratings, stackiq#375).
 *
 * Covers the approved-only aggregate: pending/rejected reviews for the SAME
 * subject are excluded; approved reviews for a DIFFERENT subject are
 * excluded; and a subject with zero approved reviews reports a null
 * average and zero count rather than erroring.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for the approved-only review aggregate/read path.
 */
class ReviewAggregateServiceTest extends TestCase {
	/**
	 * The aggregate counts only approved reviews for the matching subject —
	 * a pending review for the SAME module is excluded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	 */
	public function testAggregateCountsOnlyApprovedReviews(): void {
		$approved = $this->entity([
			'id' => 'r-1', 'name' => 'Solid', 'rating' => 8, 'status' => 'approved',
			'modules' => ['module-uuid-1'],
		]);
		$pending = $this->entity([
			'id' => 'r-2', 'name' => 'Meh', 'rating' => 2, 'status' => 'pending',
			'modules' => ['module-uuid-1'],
		]);
		$objectService = $this->objectService([$approved, $pending]);

		$service = new ReviewAggregateService($this->container($objectService), $this->settings(), $this->logger());

		$result = $service->getAggregate('module', 'module-uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
		$this->assertSame(8.0, $result['average']);
	}//end testAggregateCountsOnlyApprovedReviews()

	/**
	 * The aggregate excludes approved reviews for a DIFFERENT subject.
	 *
	 * @return void
	 */
	public function testAggregateExcludesOtherSubjects(): void {
		$otherModule = $this->entity([
			'id' => 'r-3', 'name' => 'Unrelated', 'rating' => 10, 'status' => 'approved',
			'modules' => ['some-other-module'],
		]);
		$objectService = $this->objectService([$otherModule]);

		$service = new ReviewAggregateService($this->container($objectService), $this->settings(), $this->logger());

		$result = $service->getAggregate('module', 'module-uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertSame(0, $result['count']);
		$this->assertNull($result['average']);
	}//end testAggregateExcludesOtherSubjects()

	/**
	 * A module with zero approved reviews reports a null average and a zero
	 * count rather than erroring.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	 */
	public function testAggregateWithZeroApprovedReviews(): void {
		$objectService = $this->objectService([]);

		$service = new ReviewAggregateService($this->container($objectService), $this->settings(), $this->logger());

		$result = $service->getAggregate('module', 'module-uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertSame(0, $result['count']);
		$this->assertNull($result['average']);
	}//end testAggregateWithZeroApprovedReviews()

	/**
	 * A `dienst` subject is matched against the `diensten` relation field,
	 * not `modules`.
	 *
	 * @return void
	 */
	public function testAggregateMatchesDienstSubjectViaDienstenField(): void {
		$review = $this->entity([
			'id' => 'r-4', 'name' => 'Great service', 'rating' => 6, 'status' => 'approved',
			'diensten' => ['dienst-uuid-1'],
		]);
		$objectService = $this->objectService([$review]);

		$service = new ReviewAggregateService($this->container($objectService), $this->settings(), $this->logger());

		$result = $service->getAggregate('catalogService', 'dienst-uuid-1');

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
		$this->assertSame(6.0, $result['average']);
	}//end testAggregateMatchesDienstSubjectViaDienstenField()

	/**
	 * An invalid subject type is rejected without querying.
	 *
	 * @return void
	 */
	public function testInvalidSubjectTypeRejected(): void {
		$service = new ReviewAggregateService($this->container($this->objectService([])), $this->settings(), $this->logger());

		$result = $service->getAggregate('organization', 'org-uuid-1');

		$this->assertFalse($result['ok']);
		$this->assertSame('invalid subject type', $result['reason']);
	}//end testInvalidSubjectTypeRejected()

	/**
	 * Build an ObjectService mock whose searchObjects returns $found.
	 *
	 * @param array<int,mixed> $found The search result.
	 *
	 * @return ObjectServiceInterface The mock.
	 */
	private function objectService(array $found): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn($found);
		return $objectService;
	}//end objectService()

	/**
	 * Build an ObjectEntity stub returning the given data bag + uuid.
	 *
	 * @param array<string,mixed> $data The data bag (with 'id').
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn((string)($data['id'] ?? ''));
		return $entity;
	}//end entity()

	/**
	 * Build a container resolving the OR ObjectService.
	 *
	 * @param ObjectServiceInterface $objectService The OR ObjectService.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(ObjectServiceInterface $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}
				throw new \RuntimeException('not bound: ' . $id);
			}
		);
		return $container;
	}//end container()

	/**
	 * Build a SettingsService mock resolving the beoordeeling register/schema.
	 *
	 * @return SettingsService The mock.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterIdForObjectType')->willReturn(1);
		$settings->method('getSchemaIdForObjectType')->willReturn(3);
		return $settings;
	}//end settings()

	/**
	 * Build a logger mock.
	 *
	 * @return LoggerInterface The mock.
	 */
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end logger()
}//end class
