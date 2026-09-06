<?php

/**
 * Wire-contract tests for ReviewController.
 *
 * `GET /api/reviews/aggregate` (`review#aggregate`) is a `#[PublicPage]`
 * endpoint: an anonymous visitor on a module or dienst detail page reads it.
 * That makes its response shape and its status codes part of the app's public
 * surface, so they are pinned here rather than left to be discovered by a
 * consumer.
 *
 * These assert the CONTRACT — the exact keys on the wire and the status code —
 * not merely that a JSONResponse came back.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ReviewController;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the public review aggregate endpoint.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 */
class ReviewControllerContractTest extends TestCase {

	/** @var ReviewAggregateService|MockObject */
	private ReviewAggregateService|MockObject $aggregateService;

	private ReviewController $controller;

	/**
	 * Set up the controller with mocked services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->aggregateService = $this->createMock(ReviewAggregateService::class);

		$this->controller = new ReviewController(
			$this->createMock(IRequest::class),
			$this->createMock(ReviewService::class),
			$this->aggregateService
		);

	}//end setUp()

	/**
	 * A successful aggregate returns exactly average/count/items with HTTP 200.
	 *
	 * @return void
	 */
	public function testAggregateSuccessBodyCarriesExactlyTheContractKeys(): void {
		$items = [
			['uuid' => 'r-1', 'rating' => 4],
			['uuid' => 'r-2', 'rating' => 5],
		];

		$this->aggregateService
			->method('getAggregate')
			->willReturn(
				[
					'ok' => true,
					'reason' => 'ok',
					'average' => 4.5,
					'count' => 2,
					'items' => $items,
				]
			);

		$response = $this->controller->aggregate('module', 'module-uuid');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['average', 'count', 'items'], array_keys($body));
		$this->assertSame(4.5, $body['average']);
		$this->assertSame(2, $body['count']);
		$this->assertSame($items, $body['items']);

	}//end testAggregateSuccessBodyCarriesExactlyTheContractKeys()

	/**
	 * The service's internal `ok`/`reason` bookkeeping must never reach the wire.
	 *
	 * @return void
	 */
	public function testAggregateDoesNotLeakInternalBookkeepingKeys(): void {
		$this->aggregateService
			->method('getAggregate')
			->willReturn(
				[
					'ok' => true,
					'reason' => 'ok',
					'average' => null,
					'count' => 0,
					'items' => [],
				]
			);

		$body = $this->controller->aggregate('catalogService', 'dienst-uuid')->getData();

		$this->assertArrayNotHasKey('ok', $body);
		$this->assertArrayNotHasKey('reason', $body);

	}//end testAggregateDoesNotLeakInternalBookkeepingKeys()

	/**
	 * A subject with no approved reviews is a 200 with a null average, not a 404.
	 *
	 * A consumer rendering a star widget has to distinguish "nothing approved
	 * yet" from "bad request"; that difference is the contract.
	 *
	 * @return void
	 */
	public function testAggregateWithNoApprovedReviewsIsAnEmptyTwoHundred(): void {
		$this->aggregateService
			->method('getAggregate')
			->willReturn(
				[
					'ok' => true,
					'reason' => 'ok',
					'average' => null,
					'count' => 0,
					'items' => [],
				]
			);

		$response = $this->controller->aggregate('module', 'module-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()['average']);
		$this->assertSame(0, $response->getData()['count']);
		$this->assertSame([], $response->getData()['items']);

	}//end testAggregateWithNoApprovedReviewsIsAnEmptyTwoHundred()

	/**
	 * A rejected request is a 400 carrying `message`, and no aggregate keys.
	 *
	 * @return void
	 */
	public function testAggregateFailureIsFourHundredWithAMessage(): void {
		$this->aggregateService
			->method('getAggregate')
			->willReturn(
				[
					'ok' => false,
					'reason' => 'invalid subject type',
					'average' => null,
					'count' => 0,
					'items' => [],
				]
			);

		$response = $this->controller->aggregate('bogus', 'some-uuid');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message'], array_keys($body));
		$this->assertSame('invalid subject type', $body['message']);

	}//end testAggregateFailureIsFourHundredWithAMessage()

	/**
	 * The caller's subjectType/subjectId reach the service unaltered.
	 *
	 * @return void
	 */
	public function testAggregatePassesTheSubjectThroughToTheService(): void {
		$this->aggregateService
			->expects($this->once())
			->method('getAggregate')
			->with('catalogService', 'the-subject-uuid')
			->willReturn(
				[
					'ok' => true,
					'reason' => 'ok',
					'average' => 3.0,
					'count' => 1,
					'items' => [],
				]
			);

		$this->controller->aggregate('catalogService', 'the-subject-uuid');

	}//end testAggregatePassesTheSubjectThroughToTheService()

}//end class
