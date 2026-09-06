<?php

/**
 * Stackiq Portal Contribution Provider
 *
 * Stackiq's contribution to the shared Portaliq external portal (hydra
 * ADR-046 + contract v2.1). Portaliq — the ONE shared portal for people WITHOUT
 * Nextcloud accounts — discovers this class by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and duck-types it via
 * method_exists(), never instanceof. This class is therefore deliberately
 * PLAIN: no portaliq imports, no `implements` clause, no info.xml dependency, no
 * constructor dependencies. Without portaliq installed it is inert and Software
 * Catalog behaves exactly as before.
 *
 * It declares — for the `vendor-org` (software supplier / organisatie.type
 * "Supplier") and `participant-org` (municipality / "Municipality",
 * "Collaboration", "Community") audiences — the OpenRegister collections a portal
 * subject may READ, each scoped by the subject's organisatie UUID (claim
 * `organisationId`). Some collections reach that organisatie UUID through a
 * single `via` one-hop join (contract → dienst/gebruik, compliancy → module)
 * because the schema carries no direct organisatie reference; see
 * openspec/changes/portal-contribution/design.md. This wave is READ-only: the
 * create-actions (dienst self-registration, moduleVersie updates) and the A6
 * accept/deny endpoint actions are deferred and documented in the design.
 *
 * @category Portal
 * @package  OCA\Stackiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Portal;

/**
 * Declares what an external portal subject may see in Stackiq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by portaliq's auth edge and MUST never be trusted from
 * the client (ADR-005). Scoping uses the subject's organisatie UUID (claim
 * `organisationId`) — never a Nextcloud user id, because portal subjects have
 * no Nextcloud account by premise (ADR-046 amendment A4).
 *
 * `scopeClaim`, `via`, `minTrust` and `fields` are contract-v2.1 fields:
 * portaliq's reader currently scopes on `scopeField` alone, so `via` collections
 * fail CLOSED (empty) until portaliq lands one-hop joins — exactly the
 * forward-contract pattern pipelinq already ships. Field whitelists drop
 * staff-only columns (`gebruik.interneAantekening`, `contract.opmerkingen`) and
 * the counterparty organisation's contactpersoon so a portal read never leaks
 * another organisation's data.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProvider {
	/**
	 * The OpenRegister register slug every collection below lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'stackiq';

	/**
	 * The claim carrying the subject's organisatie UUID used to scope reads.
	 *
	 * @var string
	 */
	private const ORG_CLAIM = 'organisationId';

	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first. Stackiq serves
	 * software suppliers (`vendor-org`, organisatie.type "Supplier") and the
	 * municipalities/collaborations that consume that software (`participant-org`,
	 * organisatie.type "Municipality" / "Collaboration" / "Community"). The two
	 * audiences exist because the same `gebruik` object is scoped by a DIFFERENT
	 * property for each side (`aanbieder` vs `afnemer`).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getAudiences(): array {
		return ['vendor-org', 'participant-org'];
	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getAudience(): string {
		return 'vendor-org';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef UUID,
	 * audience, organisation, trust level low|substantial|high). Returns null
	 * for any audience Stackiq does not serve (fail-closed; the registry
	 * already filters by audience, but a provider must not rely on that). This
	 * wave declares READ collections only — no create-actions and no endpoint
	 * actions (see design.md for the deferral rationale).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getContribution(array $subject): ?array {
		$audience = ($subject['audience'] ?? '');

		if ($audience === 'vendor-org') {
			return $this->vendorContribution();
		}

		if ($audience === 'participant-org') {
			return $this->participantContribution();
		}

		return null;
	}//end getContribution()

	/**
	 * Manifest for the `vendor-org` audience (software supplier / Leverancier).
	 *
	 * Read surfaces are organisatie-scoped by the supplier's own organisatie
	 * UUID: `dienst.aanbieder` and `gebruik.aanbieder` reference it directly;
	 * `contract` reaches it one hop via its required `dienst` (→ `dienst.aanbieder`)
	 * and `compliancy` one hop via its `module` (→ `module.aanbieder`). Field
	 * whitelists drop `gebruik.interneAantekening` (staff note), the customer's
	 * `contract.contactpersoonGebruiker`, `contract.opmerkingen`, and heavy/base64
	 * columns (`logo`, `bewijs`). `contract` is gated at eIDAS-substantial trust
	 * because it carries commercial terms (`kosten`).
	 *
	 * @return array<string, mixed> The vendor-org manifest.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	private function vendorContribution(): array {
		return [
			'label' => 'Stackiq',
			'collections' => [
				[
					'id' => 'vendorDiensten',
					'register' => self::REGISTER,
					'schema' => 'catalogService',
					'scopeField' => 'provider',
					'scopeClaim' => self::ORG_CLAIM,
					'label' => 'My services',
					'listable' => true,
					'fields' => [
						'name',
						'shortDescription',
						'longDescription',
						'website',
						'type',
						'modules',
					],
				],
				[
					'id' => 'vendorGebruik',
					'register' => self::REGISTER,
					'schema' => 'usage',
					'scopeField' => 'provider',
					'scopeClaim' => self::ORG_CLAIM,
					'label' => 'Deployments of my offerings',
					'listable' => true,
					'fields' => [
						'status',
						'startDateAcquisition',
						'startDatePlanned',
						'startDateInProduction',
						'startDateOutPhasing',
						'startDateOutPhased',
						'consumer',
						'module',
						'moduleVersion',
					],
				],
				[
					'id' => 'vendorContracts',
					'register' => self::REGISTER,
					'schema' => 'catalogContract',
					'scopeField' => 'provider',
					'via' => 'service',
					'scopeClaim' => self::ORG_CLAIM,
					'minTrust' => 'substantial',
					'label' => 'Contracts for my services',
					'listable' => true,
					'fields' => [
						'service',
						'usage',
						'startDate',
						'endDate',
						'contractNumber',
						'contractType',
						'cost',
						'costPeriod',
						'contactPersonProvider',
						'documentReference',
						'status',
					],
				],
				[
					'id' => 'vendorCompliancy',
					'register' => self::REGISTER,
					'schema' => 'compliancy',
					'scopeField' => 'provider',
					'via' => 'module',
					'scopeClaim' => self::ORG_CLAIM,
					'label' => 'Compliance of my modules',
					'listable' => true,
					'fields' => [
						'standardVersion',
						'standardGemma',
						'module',
						'url',
					],
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end vendorContribution()

	/**
	 * Manifest for the `participant-org` audience (municipality / Gemeente).
	 *
	 * A participant is the consuming side, so `gebruik` is scoped by its required
	 * `afnemer` (their own organisatie UUID) and `contract` reaches that UUID one
	 * hop via its required `gebruik` (→ `gebruik.afnemer`). Participants do not own
	 * `dienst`, `module` or `compliancy` rows, so those vendor surfaces are
	 * absent. Field whitelists drop `gebruik.interneAantekening`, the supplier's
	 * `contract.contactpersoonAanbieder` and `contract.opmerkingen`. `contract`
	 * is gated at eIDAS-substantial trust (commercial terms).
	 *
	 * @return array<string, mixed> The participant-org manifest.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	private function participantContribution(): array {
		return [
			'label' => 'Stackiq',
			'collections' => [
				[
					'id' => 'participantGebruik',
					'register' => self::REGISTER,
					'schema' => 'usage',
					'scopeField' => 'consumer',
					'scopeClaim' => self::ORG_CLAIM,
					'label' => 'Software we use',
					'listable' => true,
					'fields' => [
						'status',
						'startDateAcquisition',
						'startDatePlanned',
						'startDateInProduction',
						'startDateOutPhasing',
						'startDateOutPhased',
						'provider',
						'module',
						'moduleVersion',
						'diensten',
					],
				],
				[
					'id' => 'participantContracts',
					'register' => self::REGISTER,
					'schema' => 'catalogContract',
					'scopeField' => 'consumer',
					'via' => 'usage',
					'scopeClaim' => self::ORG_CLAIM,
					'minTrust' => 'substantial',
					'label' => 'Our contracts',
					'listable' => true,
					'fields' => [
						'service',
						'usage',
						'startDate',
						'endDate',
						'contractNumber',
						'contractType',
						'cost',
						'costPeriod',
						'contactPersonUser',
						'documentReference',
						'status',
					],
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end participantContribution()
}//end class
