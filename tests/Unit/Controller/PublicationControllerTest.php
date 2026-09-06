<?php

/**
 * Unit tests for PublicationController — the app's ONE live publish seam.
 *
 * `PUT /api/publication/{objectType}/{uuid}/publish` (route `publication#publish`)
 * is how a catalog entry's OpenRegister `publicatiedatum` is set, which is what
 * the `{group:public, match:{publicatiedatum:{$lte:$now}}}` RBAC read predicate
 * gates anonymous and federated reads on. Until now nothing asserted that the
 * controller reaches `PublicationService::publish()` at all, nor that its
 * per-object ownership guard refuses a non-owner — the only tests naming the
 * publish leg went through `FederationService::publishEntryForFederation()`, a
 * wrapper with zero production callers (removed; gate 57).
 *
 * Covers:
 *   - an admin publishes and the optional ISO-8601 `$when` is FORWARDED (the
 *     removed wrapper silently dropped it);
 *   - an aanbod-beheerder whose organisation does not own the entry gets 403
 *     and `PublicationService::publish()` is never reached (IDOR, ADR-005);
 *   - a peer-sourced (federated mirror) entry is never publishable locally.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\PublicationController;
use OCA\Stackiq\Service\PublicationService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for PublicationController.
 */
class PublicationControllerTest extends TestCase {
	/**
	 * The publication service double.
	 *
	 * @var PublicationService|MockObject
	 */
	private PublicationService|MockObject $publicationService;

	/**
	 * The user session double.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * The config double (per-user organisation lookup).
	 *
	 * @var IConfig|MockObject
	 */
	private IConfig|MockObject $config;

	/**
	 * Build the collaborator doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->publicationService = $this->createMock(PublicationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return PublicationController The controller.
	 */
	private function controller(): PublicationController {
		return new PublicationController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->groupManager,
			$this->config,
			$this->publicationService,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * Sign a user in and put them in the given groups.
	 *
	 * @param string $uid The uid.
	 * @param array<int,string> $groups The group ids.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$groupMocks = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groupMocks[] = $group;
		}

		$this->groupManager->method('getUserGroups')->willReturn($groupMocks);

	}//end signIn()

	/**
	 * An admin publishes, and the optional ISO-8601 moment reaches the service.
	 *
	 * The wrapper this test replaces had the signature
	 * `publishEntryForFederation(string, string)` and could not express `$when`
	 * at all, so a scheduled publication was unreachable through it.
	 *
	 * @return void
	 */
	public function testAdminPublishForwardsTheOptionalWhenMoment(): void {
		$this->signIn(uid: 'alice', groups: ['admin']);
		$this->publicationService->method('isPublishableType')->willReturn(true);
		$this->publicationService->method('resolveEntry')->willReturn(
			['data' => ['_organisation' => 'org-1']]
		);

		$this->publicationService->expects($this->once())
			->method('publish')
			->with('catalogService', 'uuid-9', '2026-09-01T00:00:00+00:00')
			->willReturn(
				[
					'ok' => true,
					'reason' => 'scheduled',
					'publicationDate' => '2026-09-01T00:00:00+00:00',
				]
			);

		$response = $this->controller()->publish(
			objectType: 'catalogService',
			uuid: 'uuid-9',
			when: '2026-09-01T00:00:00+00:00'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['ok']);

	}//end testAdminPublishForwardsTheOptionalWhenMoment()

	/**
	 * A beheerder from another organisation is refused, and the service is
	 * never reached — the refusal is server-side, before any write.
	 *
	 * @return void
	 */
	public function testNonOwnerBeheerderIsRefusedAndNeverReachesTheService(): void {
		$this->signIn(uid: 'bob', groups: ['aanbod-beheerder']);
		$this->publicationService->method('isPublishableType')->willReturn(true);
		$this->publicationService->method('resolveEntry')->willReturn(
			['data' => ['_organisation' => 'org-1']]
		);
		$this->config->method('getUserValue')->willReturn('org-2');

		$this->publicationService->expects($this->never())->method('publish');

		$response = $this->controller()->publish(objectType: 'catalogService', uuid: 'uuid-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testNonOwnerBeheerderIsRefusedAndNeverReachesTheService()

	/**
	 * A peer-sourced (federated mirror) entry is read-only locally, even for an
	 * admin — publishing it would republish another instance's record as ours.
	 *
	 * @return void
	 */
	public function testPeerSourcedEntryCannotBePublishedLocally(): void {
		$this->signIn(uid: 'alice', groups: ['admin']);
		$this->publicationService->method('isPublishableType')->willReturn(true);
		$this->publicationService->method('resolveEntry')->willReturn(
			['data' => ['_source' => ['instance' => 'https://peer.example.org']]]
		);

		$this->publicationService->expects($this->never())->method('publish');

		$response = $this->controller()->publish(objectType: 'catalogService', uuid: 'uuid-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testPeerSourcedEntryCannotBePublishedLocally()
}//end class
