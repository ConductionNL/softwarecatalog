<?php

/**
 * RBAC publish-gate tests for open-data-publishing.
 *
 * Proves the LIVE OpenRegister anon-publication model: every publishable schema
 * (dienst/module/koppeling/organisatie) carries a public read rule
 * `{group:public, match:{publicatiedatum:{$lte:$now}}}`, and that rule makes an
 * object anonymously (public-group) readable ONCE `publicatiedatum <= now` and
 * NOT before. The deprecated/removed `@self.published` predicate is NOT used.
 *
 * The test evaluates the rule exactly as OpenRegister's RBAC filter does (`$now`
 * resolves to the current moment; `$lte` is a `<=` comparison) against the real
 * rule loaded from `lib/Settings/softwarecatalogus_register.json`.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the publicatiedatum<=$now public read gate semantics.
 */
class PublishGateRbacTest extends TestCase {
	/**
	 * @var array<string,mixed> The decoded register configuration.
	 */
	private array $register;

	/**
	 * Load the register once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		$this->register = $decoded;
	}//end setUp()

	/**
	 * Extract the public read rule that gates on publicatiedatum from a schema.
	 *
	 * @param string $schemaName The schema key.
	 *
	 * @return array<string,mixed>|null The match map, or null when absent.
	 */
	private function publicPublishGate(string $schemaName): ?array {
		$read = $this->register['components']['schemas'][$schemaName]['authorization']['read'] ?? [];
		foreach ($read as $rule) {
			if (is_array($rule) === true
				&& ($rule['group'] ?? null) === 'public'
				&& isset($rule['match']['publicationDate']) === true
			) {
				return $rule['match'];
			}
		}
		return null;
	}//end publicPublishGate()

	/**
	 * Evaluate the OR `match` predicate against object data exactly as the OR
	 * RBAC filter does: `$now` → current moment, `$lte` → object value <= bound.
	 *
	 * @param array<string,mixed> $match The match predicate (with $lte/$now).
	 * @param array<string,mixed> $object The candidate object data.
	 *
	 * @return bool Whether the public group may read the object.
	 */
	private function publicCanRead(array $match, array $object): bool {
		foreach ($match as $field => $condition) {
			$value = $object[$field] ?? null;
			if (is_array($condition) === true && array_key_exists('$lte', $condition) === true) {
				if ($value === null || $value === '') {
					// No publication date set → never matches (never visible).
					return false;
				}
				$bound = $condition['$lte'] === '$now' ? time() : strtotime((string)$condition['$lte']);
				$objectTs = strtotime((string)$value);
				if ($objectTs === false || $objectTs > $bound) {
					return false;
				}
				continue;
			}
			if ($value !== $condition) {
				return false;
			}
		}
		return true;
	}//end publicCanRead()

	/**
	 * Every publishable schema carries the public publicatiedatum<=$now gate.
	 *
	 * @return void
	 */
	public function testEveryPublishableSchemaHasTheGate(): void {
		foreach (['catalogService', 'module', 'connection', 'organization'] as $schema) {
			$match = $this->publicPublishGate($schema);
			$this->assertNotNull($match, $schema . ' must have a public publicatiedatum gate');
			$this->assertSame('$now', $match['publicationDate']['$lte'], $schema . ' gate must be $lte $now');
		}
	}//end testEveryPublishableSchemaHasTheGate()

	/**
	 * An anonymous (public-group) read returns the object once publicatiedatum
	 * <= now, and NOT before (future date or absent).
	 *
	 * @return void
	 */
	public function testAnonReadVisibleOnlyWhenPublicatiedatumInThePast(): void {
		foreach (['catalogService', 'module', 'connection', 'organization'] as $schema) {
			$match = $this->publicPublishGate($schema);
			$this->assertNotNull($match);

			// Not published (no publicatiedatum) → invisible to public.
			$this->assertFalse(
				$this->publicCanRead($match, ['name' => 'Draft']),
				$schema . ': unpublished entry must NOT be anon-visible'
			);

			// Published in the future → still invisible until that moment.
			$future = gmdate('Y-m-d\TH:i:sP', (time() + 86400));
			$this->assertFalse(
				$this->publicCanRead($match, ['publicationDate' => $future]),
				$schema . ': future-dated entry must NOT be anon-visible yet'
			);

			// Published in the past → anon-visible.
			$past = gmdate('Y-m-d\TH:i:sP', (time() - 3600));
			$this->assertTrue(
				$this->publicCanRead($match, ['publicationDate' => $past]),
				$schema . ': past-published entry MUST be anon-visible'
			);
		}
	}//end testAnonReadVisibleOnlyWhenPublicatiedatumInThePast()

	/**
	 * Publishable schemas carry the publicatiedatum + depublicatiedatum fields.
	 *
	 * @return void
	 */
	public function testPublishGateFieldsPresent(): void {
		foreach (['catalogService', 'module', 'connection', 'organization'] as $schema) {
			$props = $this->register['components']['schemas'][$schema]['properties'] ?? [];
			$this->assertArrayHasKey('publicationDate', $props, $schema . ' needs publicatiedatum');
			$this->assertArrayHasKey('depublicationDate', $props, $schema . ' needs depublicatiedatum');
			$this->assertSame('date-time', $props['publicationDate']['format']);
		}
	}//end testPublishGateFieldsPresent()
}//end class
