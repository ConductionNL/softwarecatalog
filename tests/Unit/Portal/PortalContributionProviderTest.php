<?php

/**
 * Unit tests for the Stackiq Portal Contribution Provider.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Portal;

use OCA\Stackiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the plain, dependency-free ADR-046 portal contribution provider.
 *
 * The provider is constructed directly (`new`, no container, no mocks): it has
 * no constructor dependencies by contract. A register-drift pin loads the real
 * register JSON and asserts every declared scopeField and projected field
 * actually exists on its schema, so a schema rename can never silently break the
 * portal scoping.
 */
class PortalContributionProviderTest extends TestCase {
	/**
	 * System under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * The parsed register JSON schemas (keyed by slug), lazily loaded.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas = [];

	/**
	 * Set up the provider and load the register schemas for the drift pin.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

		$registerPath = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$this->assertFileExists($registerPath, 'register JSON must exist for the drift pin');
		$decoded = json_decode((string)file_get_contents($registerPath), true);
		$this->assertIsArray($decoded, 'register JSON must parse');

		$rawSchemas = ($decoded['components']['schemas'] ?? []);
		foreach ($rawSchemas as $key => $schema) {
			$slug = (string)($schema['slug'] ?? $key);
			$this->schemas[$slug] = $schema;
		}
	}

	/**
	 * The class instantiates with no dependencies (portaliq absent).
	 *
	 * @return void
	 */
	public function testConstructsStandalone(): void {
		$this->assertInstanceOf(PortalContributionProvider::class, new PortalContributionProvider());
	}

	/**
	 * getAudiences() (v2) and getAudience() (v1) agree on the primary audience.
	 *
	 * @return void
	 */
	public function testAudienceContract(): void {
		$this->assertSame(['vendor-org', 'participant-org'], $this->provider->getAudiences());
		$this->assertSame('vendor-org', $this->provider->getAudience());
		$this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());
	}

	/**
	 * Any audience Stackiq does not serve yields null (fail-closed).
	 *
	 * @return void
	 */
	public function testUnknownAudienceReturnsNull(): void {
		$this->assertNull($this->provider->getContribution(['audience' => 'client']));
		$this->assertNull($this->provider->getContribution(['audience' => '']));
		$this->assertNull($this->provider->getContribution([]));
	}

	/**
	 * The vendor-org manifest declares the four expected read collections.
	 *
	 * @return void
	 */
	public function testVendorManifestShape(): void {
		$manifest = $this->provider->getContribution(['audience' => 'vendor-org']);
		$this->assertIsArray($manifest);
		$this->assertSame('Stackiq', $manifest['label']);

		$byId = $this->collectionsById($manifest);
		$this->assertSame(
			['vendorDiensten', 'vendorGebruik', 'vendorContracts', 'vendorCompliancy'],
			array_keys($byId)
		);

		$this->assertSame('catalogService', $byId['vendorDiensten']['schema']);
		$this->assertSame('provider', $byId['vendorDiensten']['scopeField']);
		$this->assertSame('organisationId', $byId['vendorDiensten']['scopeClaim']);
		$this->assertArrayNotHasKey('via', $byId['vendorDiensten']);

		$this->assertSame('provider', $byId['vendorGebruik']['scopeField']);

		// contract is reached one hop via dienst and gated at substantial trust.
		$this->assertSame('service', $byId['vendorContracts']['via']);
		$this->assertSame('provider', $byId['vendorContracts']['scopeField']);
		$this->assertSame('substantial', $byId['vendorContracts']['minTrust']);

		// compliancy is reached one hop via module.
		$this->assertSame('module', $byId['vendorCompliancy']['via']);
		$this->assertSame('provider', $byId['vendorCompliancy']['scopeField']);

		$this->assertSame([], $manifest['actions'], 'read-only wave: no create actions');
		$this->assertSame([], $manifest['notifications']);
	}

	/**
	 * The participant-org manifest declares the two expected read collections.
	 *
	 * @return void
	 */
	public function testParticipantManifestShape(): void {
		$manifest = $this->provider->getContribution(['audience' => 'participant-org']);
		$this->assertIsArray($manifest);
		$this->assertSame('Stackiq', $manifest['label']);

		$byId = $this->collectionsById($manifest);
		$this->assertSame(['participantGebruik', 'participantContracts'], array_keys($byId));

		$this->assertSame('usage', $byId['participantGebruik']['schema']);
		$this->assertSame('consumer', $byId['participantGebruik']['scopeField']);

		$this->assertSame('usage', $byId['participantContracts']['via']);
		$this->assertSame('consumer', $byId['participantContracts']['scopeField']);
		$this->assertSame('substantial', $byId['participantContracts']['minTrust']);

		$this->assertSame([], $manifest['actions']);
		$this->assertSame([], $manifest['notifications']);
	}

	/**
	 * Staff-only and counterparty columns are never projected (no data leak).
	 *
	 * @return void
	 */
	public function testExcludedFieldsAreNotProjected(): void {
		$vendor = $this->collectionsById($this->provider->getContribution(['audience' => 'vendor-org']));
		$participant = $this->collectionsById($this->provider->getContribution(['audience' => 'participant-org']));

		// Staff-only internal note never leaves either gebruik projection.
		$this->assertNotContains('interneAnnotation', $vendor['vendorGebruik']['fields']);
		$this->assertNotContains('interneAnnotation', $participant['participantGebruik']['fields']);

		// Internal contract remarks are always dropped.
		$this->assertNotContains('opmerkingen', $vendor['vendorContracts']['fields']);
		$this->assertNotContains('opmerkingen', $participant['participantContracts']['fields']);

		// Each side never sees the OTHER organisation's contactpersoon.
		$this->assertNotContains('contactPersonUser', $vendor['vendorContracts']['fields']);
		$this->assertNotContains('contactPersonProvider', $participant['participantContracts']['fields']);

		// kwetsbaarheid is excluded entirely (documented >1-hop / array exclusion).
		$allSchemas = array_merge(
			array_column($vendor, 'schema'),
			array_column($participant, 'schema')
		);
		$this->assertNotContains('vulnerability', $allSchemas);
	}

	/**
	 * Register-drift pin: every declared scope/projected field exists on the
	 * schema it is declared against, across BOTH audiences.
	 *
	 * - direct collections: scopeField MUST be a property of the collection schema;
	 * - via collections: the via property MUST be a property of the collection
	 *   schema, and scopeField MUST be a property of the schema the via property
	 *   references;
	 * - every projected field MUST be a property of the collection schema.
	 *
	 * @return void
	 */
	public function testScopeAndProjectedFieldsExistOnSchema(): void {
		$vendor = $this->provider->getContribution(['audience' => 'vendor-org']);
		$participant = $this->provider->getContribution(['audience' => 'participant-org']);

		$collections = array_merge($vendor['collections'], $participant['collections']);
		$this->assertNotEmpty($collections);

		foreach ($collections as $collection) {
			$schemaSlug = (string)$collection['schema'];
			$props = $this->propertiesOf($schemaSlug);
			$this->assertNotEmpty($props, "schema '$schemaSlug' must exist in the register");

			if (isset($collection['via']) === true) {
				$via = (string)$collection['via'];
				$this->assertContains(
					$via,
					$props,
					"via property '$via' must exist on schema '$schemaSlug'"
				);

				$targetSlug = $this->refTarget($schemaSlug, $via);
				$targetProps = $this->propertiesOf($targetSlug);
				$this->assertContains(
					(string)$collection['scopeField'],
					$targetProps,
					"scopeField '{$collection['scopeField']}' must exist on via-target schema '$targetSlug'"
				);
			} else {
				$this->assertContains(
					(string)$collection['scopeField'],
					$props,
					"scopeField '{$collection['scopeField']}' must exist on schema '$schemaSlug'"
				);
			}

			foreach (($collection['fields'] ?? []) as $field) {
				$this->assertContains(
					(string)$field,
					$props,
					"projected field '$field' must exist on schema '$schemaSlug'"
				);
			}
		}
	}

	/**
	 * Index a manifest's collections by their id, preserving declared order.
	 *
	 * @param array<string, mixed> $manifest The provider manifest.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collectionsById(array $manifest): array {
		$byId = [];
		foreach ($manifest['collections'] as $collection) {
			$byId[(string)$collection['id']] = $collection;
		}

		return $byId;
	}

	/**
	 * Property names of a schema (by slug) in the register JSON.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, string>
	 */
	private function propertiesOf(string $slug): array {
		return array_keys(($this->schemas[$slug]['properties'] ?? []));
	}

	/**
	 * Resolve the schema slug that a relation property on $schemaSlug references.
	 *
	 * @param string $schemaSlug The schema owning the property.
	 * @param string $property The relation property to resolve.
	 *
	 * @return string The referenced schema slug (last path segment of its $ref).
	 */
	private function refTarget(string $schemaSlug, string $property): string {
		$ref = (string)($this->schemas[$schemaSlug]['properties'][$property]['$ref'] ?? '');
		$this->assertNotSame('', $ref, "via property '$property' on '$schemaSlug' must carry a \$ref");

		$segments = explode('/', $ref);
		return (string)end($segments);
	}
}
