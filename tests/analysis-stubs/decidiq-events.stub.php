<?php

/**
 * Static-analysis stubs for the decidiq decision-event contract.
 *
 * ANALYSIS-ONLY — referenced from phpstan.neon `scanFiles` and psalm.xml
 * `<stubs>`, and NEVER loaded at runtime or during PHPUnit.
 *
 * The sibling of decidesk-events.stub.php, and the reason there are two.
 * The decision app renamed its PSR-4 root from `OCA\Decidesk` to `OCA\Decidiq`
 * with no compatibility alias, so the class stackiq listens for has two
 * spellings in the field at once and this app can only follow, never move it.
 * Stackiq's outbound half already carries both
 * ({@see \OCA\Stackiq\Service\ContractApprovalService::DECISION_REQUESTED_EVENTS});
 * this stub is what lets the inbound half do the same without the analyser
 * proving the newer spelling dead.
 *
 * Signatures mirror the REAL class at decidiq/lib/Event/DecisionConcludedEvent.php,
 * read on 2026-09-09 at development d72839c, not the call site's assumption
 * about it. A stub written from the consumer agrees with the consumer by
 * construction and therefore cannot fail.
 *
 * Why this lives in `tests/analysis-stubs/` and NOT in `tests/Stubs/`:
 * `tests/bootstrap.php` `require_once`s every file matching
 * `tests/Stubs/{,**\/}*.php` BEFORE Nextcloud's app bootstrap, deliberately
 * letting those stubs win over the real classes for mock generation. Doing
 * that to the decision-app event classes would shadow the REAL events
 * dispatched through IEventDispatcher on any instance where that app is
 * installed. These declarations must therefore stay out of that glob.
 *
 * @category Test
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Analysis-only mirror of decidiq's DecisionConcludedEvent.
 *
 * Dispatched by decidiq when a Decision reaches a terminal outcome; consumed
 * by Stackiq's DecisionConcludedListener.
 */
class DecisionConcludedEvent extends Event {

	/**
	 * Construct the conclusion event.
	 *
	 * @param string $decisionId The concluded Decision id.
	 * @param string $decisionType The Decision type.
	 * @param string $status Derived outcome status.
	 * @param string $outcome Raw decision outcome.
	 * @param bool $signed Whether a signature stage resolved.
	 * @param string|null $signingReference Signing reference, when signed.
	 * @param array<int, mixed> $signers Resolved signers list.
	 * @param string|null $decidedAt When the decision concluded.
	 * @param string $sourceApp Consumer app that raised the decision.
	 * @param string|null $subjectRegister OpenRegister register of the origin object.
	 * @param string|null $subjectSchema OpenRegister schema of the origin object.
	 * @param string|null $subjectId OpenRegister id of the origin object.
	 * @param string $externalReference Consumer's own reference.
	 * @param string $correlationId Correlation id from the request event.
	 */
	public function __construct(
		private readonly string $decisionId,
		private readonly string $decisionType,
		private readonly string $status,
		private readonly string $outcome,
		private readonly bool $signed,
		private readonly ?string $signingReference,
		private readonly array $signers,
		private readonly ?string $decidedAt,
		private readonly string $sourceApp,
		private readonly ?string $subjectRegister,
		private readonly ?string $subjectSchema,
		private readonly ?string $subjectId,
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the concluded Decision id.
	 *
	 * @return string
	 */
	public function getDecisionId(): string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * Get the Decision type.
	 *
	 * @return string
	 */
	public function getDecisionType(): string {
		return $this->decisionType;
	}//end getDecisionType()

	/**
	 * Get the derived outcome status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Get the raw decision outcome.
	 *
	 * @return string
	 */
	public function getOutcome(): string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * Whether a signature stage resolved.
	 *
	 * @return bool
	 */
	public function isSigned(): bool {
		return $this->signed;
	}//end isSigned()

	/**
	 * Get the signing reference, when signed.
	 *
	 * @return string|null
	 */
	public function getSigningReference(): ?string {
		return $this->signingReference;
	}//end getSigningReference()

	/**
	 * Get the resolved signers list.
	 *
	 * @return array<int, mixed>
	 */
	public function getSigners(): array {
		return $this->signers;
	}//end getSigners()

	/**
	 * Get when the decision concluded.
	 *
	 * @return string|null
	 */
	public function getDecidedAt(): ?string {
		return $this->decidedAt;
	}//end getDecidedAt()

	/**
	 * Get the consumer app that raised the decision.
	 *
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the OpenRegister register of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectRegister(): ?string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * Get the OpenRegister schema of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectSchema(): ?string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * Get the OpenRegister id of the originating object.
	 *
	 * @return string|null
	 */
	public function getSubjectId(): ?string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Get the consumer's own reference.
	 *
	 * @return string
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id from the request event.
	 *
	 * @return string
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

}//end class
