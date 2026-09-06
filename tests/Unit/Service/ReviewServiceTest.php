<?php

/**
 * Unit tests for ReviewService (catalog-ratings, stackiq#375).
 *
 * Covers the mandated security scenarios: an unauthenticated submission is
 * refused before any object is created; a client-supplied `auteur` is
 * discarded and replaced with the authenticated session's display name;
 * every submission lands `status = pending` regardless of client input; and
 * the subject binding is server-derived, never client-supplied.
 *
 * See ReviewAggregateServiceTest for the approved-only aggregate/read tests
 * (split into its own service to keep each class under the
 * ExcessiveClassComplexity budget).
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\Entity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for the authenticated review submission.
 */
class ReviewServiceTest extends TestCase {
	/**
	 * Captured object data handed to saveObject() across the test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up the saved-capture between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
	}//end setUp()

	/**
	 * An unauthenticated submission is refused and nothing is persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-creating-updating-or-deleting-a-review-must-be-governed-by-explicit-authorization-rules
	 */
	public function testUnauthenticatedSubmissionRefused(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession(null),
			$this->logger()
		);

		$result = $service->submit(['name' => 'Great tool', 'rating' => 8], 'module', 'module-uuid-1');

		$this->assertFalse($result['ok']);
		$this->assertSame('not authenticated', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testUnauthenticatedSubmissionRefused()

	/**
	 * A client-supplied `auteur` is ignored — the persisted author is always
	 * the authenticated session's display name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
	 */
	public function testClientSuppliedAuthorIsIgnored(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$result = $service->submit(
			['name' => 'Great tool', 'rating' => 9, 'auteur' => 'Someone Else', 'status' => 'approved', '_owner' => 'forged'],
			'module',
			'module-uuid-1'
		);

		$this->assertTrue($result['ok']);
		$stored = $this->saved[0];
		$this->assertSame('Jan Jansen', $stored['auteur']);
		$this->assertArrayNotHasKey('_owner', $stored);
	}//end testClientSuppliedAuthorIsIgnored()

	/**
	 * Every submission lands `status = pending`, even when the client tries
	 * to set a different status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function testSubmissionLandsPending(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$result = $service->submit(
			['name' => 'Great tool', 'rating' => 9, 'status' => 'approved'],
			'module',
			'module-uuid-1'
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('pending', $result['status']);
		$this->assertSame('pending', $this->saved[0]['status']);
	}//end testSubmissionLandsPending()

	/**
	 * The subject binding (modules/diensten) is derived from the explicit
	 * subjectType/subjectId parameters, not from any client-supplied
	 * relation array.
	 *
	 * @return void
	 */
	public function testSubjectBindingIsServerControlled(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$service->submit(
			['name' => 'Great tool', 'rating' => 7, 'modules' => ['forged-module-id']],
			'catalogService',
			'dienst-uuid-1'
		);

		$stored = $this->saved[0];
		$this->assertSame(['dienst-uuid-1'], $stored['diensten']);
		$this->assertArrayNotHasKey('modules', $stored);
	}//end testSubjectBindingIsServerControlled()

	/**
	 * Missing required field is rejected.
	 *
	 * @return void
	 */
	public function testMissingRequiredFieldRejected(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$result = $service->submit(['rating' => 8], 'module', 'module-uuid-1');

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('name', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testMissingRequiredFieldRejected()

	/**
	 * A rating outside 1-10 is rejected.
	 *
	 * @return void
	 */
	public function testOutOfRangeRatingRejected(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$result = $service->submit(['name' => 'Great tool', 'rating' => 11], 'module', 'module-uuid-1');

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('rating', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testOutOfRangeRatingRejected()

	/**
	 * An invalid subject type is rejected before any query/save.
	 *
	 * @return void
	 */
	public function testInvalidSubjectTypeRejected(): void {
		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$result = $service->submit(['name' => 'Great tool', 'rating' => 8], 'organization', 'org-uuid-1');

		$this->assertFalse($result['ok']);
		$this->assertSame('invalid subject type', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testInvalidSubjectTypeRejected()

	/**
	 * `entityUuid()` must read the uuid off a saved entity whose `getUuid()`
	 * is reached through `Entity::__call()` — which is what every real
	 * OpenRegister `ObjectEntity` returned by `saveObject()` does.
	 *
	 * With the old `method_exists()` probe this returned `null` for EVERY real
	 * save (the `is_array()` arm cannot rescue an object), so `submit()`
	 * answered `uuid: null` to the client and wrote `['uuid' => null]` to the
	 * audit log — stackiq#490.
	 *
	 * The private method is exercised directly because the shared
	 * `tests/Stubs/Db/ObjectEntity` still declares `getUuid()` concretely (8
	 * other test files configure it on a mock), so a double routed through
	 * `saveObject()`'s `: ObjectEntity` return type cannot express the magic
	 * shape. Recorded as remaining debt rather than hidden.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	public function testEntityUuidReadsAMagicAccessorUuid(): void {
		$entity = new class extends Entity {

			/**
			 * The uuid — a property reached via __call, as on ObjectEntity.
			 *
			 * @var string|null
			 */
			protected ?string $uuid = null;
		};
		$entity->setUuid('review-uuid-1');

		$this->assertFalse(
			method_exists($entity, 'getUuid'),
			'the double must reach getUuid() through __call, like the real ObjectEntity'
		);

		$service = new ReviewService(
			$this->container($this->objectService([])),
			$this->settings(),
			$this->userSession($this->user('jan.jansen', 'Jan Jansen')),
			$this->logger()
		);

		$method = new \ReflectionMethod($service, 'entityUuid');
		$method->setAccessible(true);

		$this->assertSame('review-uuid-1', $method->invoke($service, $entity));
	}//end testEntityUuidReadsAMagicAccessorUuid()

	/**
	 * Build an ObjectService mock whose searchObjects returns $found and whose
	 * saveObject captures the data bag.
	 *
	 * @param array<int,mixed> $found The search result.
	 *
	 * @return ObjectServiceInterface The mock.
	 */
	private function objectService(array $found): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn($found);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return $this->createStub(ObjectEntity::class);
			}
		);
		return $objectService;
	}//end objectService()

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
	 * Build an IUser mock.
	 *
	 * @param string $uid The user id.
	 * @param string $displayName The display name.
	 *
	 * @return IUser The mock.
	 */
	private function user(string $uid, string $displayName): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}//end user()

	/**
	 * Build an IUserSession mock returning the given user (or null).
	 *
	 * @param IUser|null $user The authenticated user, or null.
	 *
	 * @return IUserSession The mock.
	 */
	private function userSession(?IUser $user): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		return $userSession;
	}//end userSession()

	/**
	 * Build a logger mock.
	 *
	 * @return LoggerInterface The mock.
	 */
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end logger()
}//end class
