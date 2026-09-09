<?php

/**
 * Tests that the contract-approval conclusion listener accepts the decision
 * app's event under BOTH namespaces it has shipped.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\DecisionConcludedListener;
use OCA\Stackiq\Service\ContractApprovalService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/decision-conclusion-event-doubles.php';

/**
 * A cross-app event class name is a runtime lookup this app can only follow.
 *
 * The decision app renamed its PSR-4 root from `OCA\Decidesk` to `OCA\Decidiq`
 * with no compatibility alias. `handle()` tested `instanceof` against the old
 * spelling only, so the real event arrived and was rejected as somebody else's:
 * the contract stayed in `In onderhandeling`, nothing threw, and nothing was
 * logged. An event with no listener looks exactly like a listener on no event.
 *
 * Both spellings are asserted, because pinning either one alone reproduces the
 * outage on the half of the fleet running the other.
 */
class DecisionConcludedListenerNamespaceTest extends TestCase {
	/**
	 * The listener projects an outcome carried by either spelling of the event.
	 *
	 * @return void
	 */
	public function testTheOutcomeIsProjectedUnderEitherFleetNamespace(): void {
		$spellings = [
			'OCA\Decidiq\Event\DecisionConcludedEvent',
			'OCA\Decidesk\Event\DecisionConcludedEvent',
		];

		foreach ($spellings as $fqcn) {
			$projected = [];

			$approvalService = $this->createMock(ContractApprovalService::class);
			$approvalService->method('resolveContractForOutcome')->willReturn('contract-1');
			$approvalService->method('projectOutcome')->willReturnCallback(
				static function (string $contractUuid, string $outcomeStatus) use (&$projected): void {
					$projected[] = [$contractUuid, $outcomeStatus];
				}
			);

			$listener = new DecisionConcludedListener($approvalService, $this->createMock(LoggerInterface::class));
			$listener->handle(new $fqcn('decision-1', 'approved', ContractApprovalService::SOURCE_APP, 'subject-1', 'ext-1'));

			$this->assertSame(
				[['contract-1', 'approved']],
				$projected,
				'the listener ignored a real conclusion event dispatched as ' . $fqcn
			);
		}
	}//end testTheOutcomeIsProjectedUnderEitherFleetNamespace()

	/**
	 * An event raised by a different consumer app is still ignored.
	 *
	 * Widening the accepted class names must not widen the sourceApp filter:
	 * that filter is what stops this app projecting another consumer's decision
	 * onto its own contracts.
	 *
	 * @return void
	 */
	public function testAnotherConsumersDecisionIsStillIgnored(): void {
		$approvalService = $this->createMock(ContractApprovalService::class);
		$approvalService->expects($this->never())->method('resolveContractForOutcome');

		$listener = new DecisionConcludedListener($approvalService, $this->createMock(LoggerInterface::class));
		$listener->handle(
			new \OCA\Decidiq\Event\DecisionConcludedEvent('decision-1', 'approved', 'dossiq', 'subject-1', 'ext-1')
		);

	}//end testAnotherConsumersDecisionIsStillIgnored()

	/**
	 * The registration list carries both spellings, newest first.
	 *
	 * Written out rather than read from the constant under test: iterating the
	 * same list the assertion checks is the shape that cannot fail.
	 *
	 * @return void
	 */
	public function testTheRegistrationListCarriesBothSpellingsNewestFirst(): void {
		$this->assertSame(
			[
				'\OCA\Decidiq\Event\DecisionConcludedEvent',
				'\OCA\Decidesk\Event\DecisionConcludedEvent',
			],
			ContractApprovalService::DECISION_CONCLUDED_EVENTS,
			'order is the contract: the current namespace first, the pre-rename one retained'
		);

	}//end testTheRegistrationListCarriesBothSpellingsNewestFirst()
}//end class
