<?php

/**
 * Stackiq DecisionConcludedListener.
 *
 * In-process listener for decidesk's `DecisionConcludedEvent` — the terminal
 * outcome of a contract approval / renewal Decision that stackiq raised
 * via `DecisionRequestedEvent`. It REPLACES the former HTTP outcome-callback +
 * daily reconcile poll path: decidesk dispatches the conclusion synchronously,
 * this listener filters to `sourceApp === stackiq`, IDOR-checks the
 * carried `decisionId` against the contract's stored `approvalDecisionId`, and
 * projects the outcome onto the catalog-local `approvalState` / `status`
 * fields. The `In onderhandeling -> Actief` transition is reached ONLY here, as
 * a projection of an `approved` decidesk outcome — never on local authority.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\Decidesk\Event\DecisionConcludedEvent as DecideskDecisionConcludedEvent;
use OCA\Decidiq\Event\DecisionConcludedEvent as DecidiqDecisionConcludedEvent;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Projects a concluded decidesk Decision outcome onto a catalog contract.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class DecisionConcludedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContractApprovalService $approvalService The approval-delegation service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContractApprovalService $approvalService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a concluded decidesk Decision.
	 *
	 * Only `DecisionConcludedEvent`s whose `sourceApp` is stackiq are
	 * acted on; everything else is ignored. The carried `decisionId` is
	 * IDOR-checked against the contract's stored `approvalDecisionId` inside
	 * `resolveContractForOutcome()` before any projection is written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function handle(Event $event): void {
		// BOTH SPELLINGS. The decision app renamed its PSR-4 root from
		// OCA\Decidesk to OCA\Decidiq with no compatibility alias, so an
		// `instanceof` against one name silently rejects the other app's real
		// event and this method returns as if the event were somebody else's.
		// The two classes publish an identical getter surface (verified against
		// decidiq development d72839c), so everything below is unchanged.
		//
		// `instanceof` against a class that is not installed is simply false —
		// it neither autoloads nor errors — which is why naming both here costs
		// nothing on an instance that runs only one of them.
		if (($event instanceof DecidiqDecisionConcludedEvent) === false
			&& ($event instanceof DecideskDecisionConcludedEvent) === false
		) {
			return;
		}

		if ($event->getSourceApp() !== ContractApprovalService::SOURCE_APP) {
			return;
		}

		try {
			$contractUuid = $this->approvalService->resolveContractForOutcome(
				subjectId: (string)($event->getSubjectId() ?? ''),
				externalReference: $event->getExternalReference(),
				decisionId: $event->getDecisionId()
			);

			if ($contractUuid === null) {
				return;
			}

			$this->approvalService->projectOutcome(
				contractUuid: $contractUuid,
				outcomeStatus: $event->getStatus()
			);
		} catch (\Throwable $e) {
			// Never break the dispatch chain; a projection failure leaves the
			// contract in its prior (fail-closed) state.
			$this->logger->error(
				'DecisionConcludedListener: projecting contract outcome failed',
				[
					'decisionId' => $event->getDecisionId(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end handle()
}//end class
