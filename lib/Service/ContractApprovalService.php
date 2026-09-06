<?php

/**
 * Contract Approval Delegation Service.
 *
 * Delegates the contract approval / sign-off / renewal DECISION (the
 * `In onderhandeling -> Actief` transition, and the re-approval of an
 * expiring/`Verlopen` contract) to decidesk — the canonical fleet decision
 * authority (cross-app interface contract #1) — through the in-process
 * `IEventDispatcher` event contract (`OCA\Decidesk\Event\DecisionRequestedEvent`
 * / `DecisionConcludedEvent`). stackiq keeps the contract RECORD locally
 * and PROJECTS the decidesk outcome onto two catalog-local fields
 * (`approvalDecisionId`, `approvalState`).
 *
 * FAIL-CLOSED CONTRACT (mirrors the hydra-gate-unsafe-auth-resolver pattern):
 * a "decidesk not installed" / "listener did not handle" condition NEVER
 * results in an auto-approval. Delegation is wired entirely in-process: when
 * the decidesk app (and its `DecisionRequestedEvent` class) is absent, or its
 * synchronous listener reports `isHandled() === false` / a null decision id,
 * this service throws and the contract stays `In onderhandeling`; `status` is
 * never set to `Actief` on local authority.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises and projects contract approval decisions delegated to decidesk.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Overall complexity 56 (threshold 50). This
 * class is the fail-closed boundary to an optional cross-app dependency (decidesk): every
 * delegation path has to check that the event contract exists, that the listener actually handled
 * the request, and that a decision id came back, and refuse on each. Fail-open here would silently
 * auto-approve contracts, so that defensive branching is the point of the class and the
 * complexity is inherent rather than accidental.
 */
class ContractApprovalService {
	/**
	 * The fully-qualified request-event class spellings, NEWEST FIRST — the
	 * in-process existence guard (the decision app installed + autoloaded) for
	 * delegation.
	 *
	 * TWO SPELLINGS because a cross-app event class name is a RUNTIME lookup
	 * this app can only follow, never move. The decision app renamed from
	 * OCA\Decidesk to OCA\Decidiq with no compatibility alias, and this constant
	 * named only the old one — so `isDelegationConfigured()` returned false on an
	 * instance where the app was installed, and contract approvals silently
	 * stopped delegating. Measured: OCA\Decidiq\Event\DecisionRequestedEvent
	 * EXISTS, OCA\Decidesk\Event\DecisionRequestedEvent MISSING.
	 *
	 * @var array<int, string>
	 */
	public const DECISION_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionRequestedEvent',
		'\\OCA\\Decidesk\\Event\\DecisionRequestedEvent',
	];

	/**
	 * This consumer app id, stamped on the request event as `sourceApp` and
	 * used by the conclusion listener to filter inbound events.
	 *
	 * FROZEN at `stackiq` through the app-id rename. This value is not
	 * a name we own at read time — it is PERSISTED on decidesk's Decision rows
	 * when the approval is raised, and `DecisionConcludedListener` matches
	 * inbound conclusions against it. Every decision already open was stamped
	 * `stackiq`; moving the constant makes the filter miss them, so
	 * their outcomes are dropped on the floor and the contract never leaves
	 * `In onderhandeling`. Nothing errors — a filtered-out event looks exactly
	 * like an event that was never sent.
	 *
	 * It can only move in a coordinated change that also rewrites the stored
	 * `sourceApp` on decidesk's existing rows, or that accepts both spellings
	 * on the read side first.
	 */
	public const SOURCE_APP = 'stackiq';

	/**
	 * The decisionType raised for a first activation of an `In onderhandeling`
	 * contract (matches the decidesk Decision `decisionType` enum value).
	 */
	public const DECISION_TYPE_APPROVAL = 'contract';

	/**
	 * The decisionType raised for re-approval of an expiring/`Verlopen`
	 * contract (additive decidesk enum value, decidesk-contract-decision-hub).
	 */
	public const DECISION_TYPE_RENEWAL = 'contract-renewal';

	/**
	 * The catalog register slug that owns the contract record.
	 */
	public const SUBJECT_REGISTER = 'stackiq';

	/**
	 * The contract schema slug.
	 */
	public const SUBJECT_SCHEMA = 'catalogContract';

	/**
	 * Catalog lifecycle status: in negotiation (the pre-approval state).
	 */
	public const STATUS_NEGOTIATION = 'In negotiation';

	/**
	 * Catalog lifecycle status: active (only ever reached via an `approved`
	 * decidesk outcome — never set on local authority).
	 */
	public const STATUS_ACTIVE = 'Active';

	/**
	 * Projection state: no decision raised.
	 */
	public const APPROVAL_NONE = 'none';

	/**
	 * Projection state: a decidesk Decision is open.
	 */
	public const APPROVAL_PENDING = 'pending';

	/**
	 * Projection state: decidesk reported an adopting outcome.
	 */
	public const APPROVAL_APPROVED = 'approved';

	/**
	 * Projection state: decidesk rejected or withdrew the decision.
	 */
	public const APPROVAL_REJECTED = 'rejected';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param SettingsService $settingsService Resolves register/schema ids.
	 * @param IEventDispatcher $eventDispatcher Dispatches the decidesk request event in-process.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the decidesk event contract is available on this instance.
	 *
	 * Drives the ContractDetail Approval panel: when false, the panel hides its
	 * submit action ("approval delegation not configured") so no fail-open path
	 * exists. The check is purely in-process — the decidesk app's
	 * `DecisionRequestedEvent` class must be installed and autoloadable.
	 *
	 * @return bool True when decidesk's event contract is present.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function isDelegationConfigured(): bool {
		return ($this->resolveRequestEventClass() !== null);
	}//end isDelegationConfigured()

	/**
	 * The first request-event class that actually exists, or null.
	 *
	 * Resolving rather than assuming: this app can only follow the decision
	 * app's namespace, and a hard-coded spelling turns a rename over there into
	 * a silently disabled feature over here.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveRequestEventClass(): ?string {
		foreach (self::DECISION_REQUESTED_EVENTS as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end resolveRequestEventClass()

	/**
	 * Raise a decidesk Decision for a contract approval or renewal.
	 *
	 * FAIL-CLOSED: when decidesk's event class is absent, or its synchronous
	 * listener does not handle the dispatched request (`isHandled() === false`
	 * or a null decision id), this throws a RuntimeException and the caller
	 * leaves the contract `In onderhandeling` with `approvalState` unchanged. On
	 * success it persists the returned decision id to `approvalDecisionId`, sets
	 * `approvalState = pending`, and returns the decision id. `status` is NEVER
	 * set to `Actief` here.
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 * @param bool $isRenewal When true, raise decisionType=contract-renewal.
	 *
	 * @return string The decidesk decision id.
	 *
	 * @throws RuntimeException When delegation is not available or the listener did not handle it.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$isRenewal` selects the `decisionType`
	 * (`contract-approval` vs `contract-renewal`) carried on the raised decidesk event; the
	 * fail-closed logic, persistence and return value are byte-for-byte identical on both paths.
	 * It is a payload discriminator, not a second responsibility.
	 */
	public function submitForApproval(string $contractUuid, bool $isRenewal = false): string {
		// Resolve ONCE and narrow here, rather than calling
		// isDelegationConfigured() and resolving again below. Two lookups of the
		// same thing can disagree — and the second one has no guard, so a null
		// would reach `new $eventClass(...)` as a call on null. Psalm caught
		// exactly that shape.
		$eventClass = $this->resolveRequestEventClass();
		if ($eventClass === null) {
			// Fail closed — never auto-approve when the decision app is absent.
			throw new RuntimeException(
				'Contract approval delegation is not available (decision event contract not installed).'
			);
		}

		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			throw new RuntimeException('Contract not found: ' . $contractUuid);
		}

		$data = $contract->getObject();

		$decisionType = self::DECISION_TYPE_APPROVAL;
		if ($isRenewal === true) {
			$decisionType = self::DECISION_TYPE_RENEWAL;
		}

		$event = new $eventClass(
			self::SOURCE_APP,
			self::SUBJECT_REGISTER,
			self::SUBJECT_SCHEMA,
			$contractUuid,
			$this->buildSubjectLabel(data: $data),
			$decisionType,
			'',
			['title' => $this->buildSubjectLabel(data: $data)],
			(string)($data['contractNumber'] ?? ''),
			$contractUuid
		);

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			// Fail closed — a listener error never advances the contract.
			$this->logger->error(
				'ContractApprovalService: dispatching decidesk decision request failed — contract left in negotiation',
				['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to raise the approval decision in decidesk.', 0, $e);
		}//end try

		if ($event->isHandled() === false) {
			throw new RuntimeException('decidesk did not handle the approval request; contract left in negotiation.');
		}

		$decisionId = (string)($event->getDecisionId() ?? '');
		if ($decisionId === '') {
			throw new RuntimeException('decidesk did not return a decision id; contract left in negotiation.');
		}

		$data['approvalDecisionId'] = $decisionId;
		$data['approvalState'] = self::APPROVAL_PENDING;
		// NOTE: status is intentionally NOT touched here — it stays In onderhandeling.
		$this->persistContract(contract: $contract, data: $data);

		$this->logger->info(
			'ContractApprovalService: contract submitted for approval',
			['contractUuid' => $contractUuid, 'decisionId' => $decisionId, 'decisionType' => $decisionType]
		);

		return $decisionId;
	}//end submitForApproval()

	/**
	 * Per-object ownership guard (IDOR guard) for the submit / submitRenewal seam.
	 *
	 * Mirrors `PublicationController::authorizeEntry()`: an admin may always
	 * submit; a non-admin MUST be an `aanbod-beheerder` whose active organisation
	 * matches the contract's owning organisation (the OR-stamped `_organisation`
	 * multitenancy field, falling back to a schema-declared `aanbieder` field if
	 * ever present). Returns false (fail-closed) when the contract cannot be
	 * loaded, when the owning organisation cannot be resolved, or on any mismatch.
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 * @param array $groupNames The caller's NC group ids.
	 * @param string $activeOrgUuid The caller's active organisation uuid (may be empty).
	 *
	 * @return bool True when the caller may submit/submitRenewal this contract.
	 *
	 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
	 */
	public function authorizeSubmit(string $contractUuid, array $groupNames, string $activeOrgUuid): bool {
		if (in_array('admin', $groupNames, true) === true) {
			return true;
		}

		if (in_array('aanbod-beheerder', $groupNames, true) === false) {
			return false;
		}

		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			return false;
		}

		$data = $contract->getObject();
		$ownerOrg = (string)($data['_organisation'] ?? $data['provider'] ?? '');

		if ($activeOrgUuid === '' || $ownerOrg === '' || $activeOrgUuid !== $ownerOrg) {
			return false;
		}

		return true;
	}//end authorizeSubmit()

	/**
	 * IDOR guard: whether the given decision id is the one this contract carries.
	 *
	 * Used when projecting an outcome so a caller cannot project an arbitrary
	 * outcome onto an arbitrary contract. A blank stored or supplied id never
	 * matches (fail-closed).
	 *
	 * @param string $contractUuid The contract uuid.
	 * @param string $decisionId The decision id supplied by the caller.
	 *
	 * @return bool True when the decision id matches the contract's stored id.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function isDecisionForContract(string $contractUuid, string $decisionId): bool {
		if ($decisionId === '') {
			return false;
		}

		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			return false;
		}

		$stored = (string)($contract->getObject()['approvalDecisionId'] ?? '');
		return $stored !== '' && hash_equals($stored, $decisionId);
	}//end isDecisionForContract()

	/**
	 * Project a decidesk outcome status onto a contract, idempotently.
	 *
	 * `approved` -> approvalState=approved AND status=Actief (the ONLY path
	 * that activates a contract). `rejected`/`withdrawn` ->
	 * approvalState=rejected, status stays In onderhandeling. Any other
	 * (`pending`) status is a no-op. Re-applying the same terminal outcome is
	 * a no-op (idempotent).
	 *
	 * @param string $contractUuid The contract uuid.
	 * @param string $outcomeStatus The decidesk-derived status (approved|rejected|withdrawn|pending).
	 *
	 * @return bool True when the contract was changed.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function projectOutcome(string $contractUuid, string $outcomeStatus): bool {
		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			$this->logger->warning(
				'ContractApprovalService: outcome for unknown contract ignored',
				['contractUuid' => $contractUuid]
			);
			return false;
		}

		$data = $contract->getObject();
		$changed = false;

		switch ($outcomeStatus) {
			case 'approved':
				if (($data['approvalState'] ?? self::APPROVAL_NONE) !== self::APPROVAL_APPROVED) {
					$data['approvalState'] = self::APPROVAL_APPROVED;
					$changed = true;
				}

				// The In onderhandeling -> Actief transition happens ONLY here,
				// as a projection of an approved decidesk outcome.
				if (($data['status'] ?? '') !== self::STATUS_ACTIVE) {
					$data['status'] = self::STATUS_ACTIVE;
					$changed = true;
				}
				break;

			case 'rejected':
			case 'withdrawn':
				if (($data['approvalState'] ?? self::APPROVAL_NONE) !== self::APPROVAL_REJECTED) {
					$data['approvalState'] = self::APPROVAL_REJECTED;
					$changed = true;
				}

				// Status stays In onderhandeling — never forced here.
				break;

			default:
				// Pending / unknown — no projection.
				return false;
		}//end switch

		if ($changed === false) {
			// Idempotent re-receipt — nothing to write.
			return false;
		}

		$this->persistContract(contract: $contract, data: $data);
		$this->logger->info(
			'ContractApprovalService: outcome projected onto contract',
			['contractUuid' => $contractUuid, 'outcomeStatus' => $outcomeStatus]
		);
		return true;
	}//end projectOutcome()

	/**
	 * Resolve the catalog contract that a concluded decidesk outcome addresses.
	 *
	 * Prefers the carried `subjectId` (the OR object uuid stamped on the request
	 * event), falling back to `externalReference` (the `contractNummer`). The
	 * resolved uuid is only returned when the carried `decisionId` matches the
	 * contract's stored `approvalDecisionId` (IDOR guard), so a spoofed event
	 * cannot project an outcome onto an unrelated contract.
	 *
	 * @param string $subjectId The carried OR object uuid (may be blank).
	 * @param string $externalReference The carried contractNummer (fallback, may be blank).
	 * @param string $decisionId The concluded decision id (IDOR guard).
	 *
	 * @return string|null The matched contract uuid, or null when none matches.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function resolveContractForOutcome(string $subjectId, string $externalReference, string $decisionId): ?string {
		if ($subjectId !== '' && $this->isDecisionForContract(contractUuid: $subjectId, decisionId: $decisionId) === true) {
			return $subjectId;
		}

		$match = $this->findContractByExternalReference(externalReference: $externalReference, decisionId: $decisionId);
		if ($match !== null) {
			return $match;
		}

		$this->logger->warning(
			'ContractApprovalService: concluded outcome did not match any contract (decision/contract mismatch)',
			['subjectId' => $subjectId, 'externalReference' => $externalReference, 'decisionId' => $decisionId]
		);
		return null;
	}//end resolveContractForOutcome()

	/**
	 * Find a pending contract whose contractNummer + decisionId match.
	 *
	 * @param string $externalReference The carried contractNummer.
	 * @param string $decisionId The concluded decision id (IDOR guard).
	 *
	 * @return string|null The matched contract uuid, or null.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function findContractByExternalReference(string $externalReference, string $decisionId): ?string {
		if ($externalReference === '' || $decisionId === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'catalogContract');
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		$query = [
			'register' => $registerId,
			'schema' => $schemaId,
			'approvalDecisionId' => $decisionId,
			'_limit' => 50,
		];

		try {
			$contracts = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractApprovalService: failed to query contract by decision id',
				['error' => $e->getMessage()]
			);
			return null;
		}//end try

		foreach ($contracts as $contract) {
			$data = $contract->getObject();
			if ((string)($data['contractNumber'] ?? '') === $externalReference) {
				return $contract->getUuid();
			}
		}

		return null;
	}//end findContractByExternalReference()

	/**
	 * Build the human-readable subject label for the decision provenance.
	 *
	 * @param array $data The contract object data.
	 *
	 * @return string The subject label.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function buildSubjectLabel(array $data): string {
		$number = (string)($data['contractNumber'] ?? '');
		$type = (string)($data['contractType'] ?? '');
		if ($number !== '' && $type !== '') {
			return $number . ' — ' . $type;
		}

		if ($number !== '') {
			return $number;
		}

		return 'Contract';
	}//end buildSubjectLabel()

	/**
	 * Load a contract OR object by uuid.
	 *
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface|null The object, or null.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function loadContract(string $contractUuid) {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'catalogContract');
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		try {
			return $objectService->find(
				id: $contractUuid,
				_extend: [],
				files: false,
				register: $registerId,
				schema: $schemaId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ContractApprovalService: contract lookup failed',
				['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end loadContract()

	/**
	 * Persist a mutated contract object back to the OR store.
	 *
	 * @param \OCA\OpenRegister\Contract\ObjectEntityInterface $contract The contract entity.
	 * @param array $data The mutated object data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function persistContract($contract, array $data): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		$objectService->saveObject(
			object: $data,
			extend: [],
			register: $contract->getRegister(),
			schema: $contract->getSchema(),
			uuid: $contract->getUuid()
		);

	}//end persistContract()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			// Ask for the CONTRACT and narrow on the CONTRACT (ADR-084). Asking
			// for the concrete class and gating on `instanceof ObjectService`
			// made this method return null for anything that satisfies the
			// published interface without being that exact class — which is
			// every double a leaf app can build, so the ownership check silently
			// fell through to its fail-closed arm and refused an owner.
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('ContractApprovalService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}//end try

		return null;
	}//end getObjectService()
}//end class
