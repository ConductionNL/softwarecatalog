<?php

/**
 * Tests for the pure decisions behind the schema-slug migration.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameDutchSchemaSlugDecisions;
use OCA\Stackiq\Repair\RenameDutchSchemaSlugs;
use PHPUnit\Framework\TestCase;

/**
 * The slug migration's decisions, exercised without a database.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Repair\RenameDutchSchemaSlugDecisions
 */
final class RenameDutchSchemaSlugDecisionsTest extends TestCase {

	/**
	 * The decisions under test.
	 *
	 * @var RenameDutchSchemaSlugDecisions
	 */
	private RenameDutchSchemaSlugDecisions $decisions;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->decisions = new RenameDutchSchemaSlugDecisions();

	}//end setUp()

	/**
	 * A slug present on the install is renamed; one that is absent is skipped.
	 *
	 * @return void
	 */
	public function testPlanRenamesOnlyWhatIsPresent(): void {
		$plan = $this->decisions->plan(
			['dienst' => 'catalogService', 'gebruik' => 'usage'],
			['dienst', 'module']
		);

		self::assertSame(['dienst' => 'catalogService'], $plan['renames']);
		self::assertSame([], $plan['refused'], 'an absent slug is nothing to do, not a refusal');

	}//end testPlanRenamesOnlyWhatIsPresent()

	/**
	 * A target that already exists refuses the rename rather than merging.
	 *
	 * Two schemas cannot share a slug, and combining them is a decision about
	 * data — never something a repair step should do on its own.
	 *
	 * @return void
	 */
	public function testPlanRefusesWhenTheTargetSlugExists(): void {
		$plan = $this->decisions->plan(
			['organisatie' => 'organization'],
			['organisatie', 'organization']
		);

		self::assertSame([], $plan['renames']);
		self::assertArrayHasKey('organisatie', $plan['refused']);
		self::assertStringContainsString('organization', $plan['refused']['organisatie']);

	}//end testPlanRefusesWhenTheTargetSlugExists()

	/**
	 * A rename earlier in the map is visible to a later collision check.
	 *
	 * Without carrying the freshly taken name forward, two entries aiming at the
	 * same target would both read as safe and the second would collide at the
	 * database rather than here.
	 *
	 * @return void
	 */
	public function testPlanSeesItsOwnEarlierRenames(): void {
		$plan = $this->decisions->plan(
			['dienst' => 'catalogService', 'service2' => 'catalogService'],
			['dienst', 'service2']
		);

		self::assertSame(['dienst' => 'catalogService'], $plan['renames']);
		self::assertArrayHasKey('service2', $plan['refused']);

	}//end testPlanSeesItsOwnEarlierRenames()

	/**
	 * Retiring the absorbed schema is allowed only when it holds no rows.
	 *
	 * A NEGATIVE count means the count could not be taken, and an unreadable
	 * table must never be mistaken for an empty one.
	 *
	 * @return void
	 */
	public function testMayRetireOnlyOnAProvenEmptySchema(): void {
		self::assertTrue($this->decisions->mayRetire(0));
		self::assertFalse($this->decisions->mayRetire(1));
		self::assertFalse($this->decisions->mayRetire(-1), 'a failed count is not an empty schema');

	}//end testMayRetireOnlyOnAProvenEmptySchema()

	/**
	 * Schema ids are read out of the registers' JSON column defensively.
	 *
	 * A null, a malformed value or a non-numeric entry must yield no ids rather
	 * than a fatal: this runs inside a repair step, where an exception aborts
	 * the upgrade.
	 *
	 * @return void
	 */
	public function testSchemaIdsFromToleratesMalformedRows(): void {
		$ids = $this->decisions->schemaIdsFrom([
			['schemas' => '[34,35,35]'],
			['schemas' => '[40,"not-a-number",null]'],
			['schemas' => 'not json at all'],
			['schemas' => null],
			[],
		]);

		self::assertSame([34, 35, 40], $ids, 'deduplicated, numeric only, no fatal');

	}//end testSchemaIdsFromToleratesMalformedRows()

	/**
	 * The shard-table suffix is matched exactly, not by substring.
	 *
	 * `LIKE %_table_%_3` also matches `..._table_3_13`, and counting another
	 * schema's rows would make an empty schema look occupied and refuse a merge
	 * that was safe.
	 *
	 * @return void
	 */
	public function testIsShardTableForMatchesTheSuffixExactly(): void {
		self::assertTrue($this->decisions->isShardTableFor('oc_openregister_table_12_3', 3));
		self::assertFalse($this->decisions->isShardTableFor('oc_openregister_table_3_13', 3));
		self::assertFalse($this->decisions->isShardTableFor('oc_openregister_table_12_31', 3));

	}//end testIsShardTableForMatchesTheSuffixExactly()

	/**
	 * Both organisation schemas are found, and a missing one is not an error.
	 *
	 * With only one present the merge either already ran or this install never
	 * had the second schema; in both cases there is no name to free.
	 *
	 * @return void
	 */
	public function testOrganisationPairFindsBothOrReportsAbsence(): void {
		$rows = [
			['id' => 39, 'slug' => 'organisatie'],
			['id' => 5057, 'slug' => 'organization'],
			['id' => 40, 'slug' => 'module'],
		];

		$pair = $this->decisions->organisationPair($rows);
		self::assertSame(39, $pair['catalogue']['id']);
		self::assertSame(5057, $pair['archimate']['id']);

		$after = $this->decisions->organisationPair([['id' => 39, 'slug' => 'organization']]);
		self::assertNull($after['catalogue'], 'the merge having already run is an ordinary outcome');
		self::assertSame(39, $after['archimate']['id']);

		$empty = $this->decisions->organisationPair([]);
		self::assertNull($empty['archimate']);
		self::assertNull($empty['catalogue']);

	}//end testOrganisationPairFindsBothOrReportsAbsence()

	/**
	 * Every target in the shipped map is English and distinct.
	 *
	 * A duplicate target would mean two schemas aimed at one name; a target that
	 * is also somebody else's OLD name would mean the order of the map decides
	 * the outcome.
	 *
	 * @return void
	 */
	public function testShippedMapTargetsAreDistinctAndDoNotCollideWithSources(): void {
		$map = RenameDutchSchemaSlugs::SLUG_MAP;

		self::assertSame(
			count($map),
			count(array_unique(array_values($map))),
			'two slugs must not target one name'
		);

		$sources = array_keys($map);
		foreach ($map as $old => $new) {
			self::assertNotContains(
				$new,
				$sources,
				sprintf("target '%s' is also a source slug, so the map order would decide the result", $new)
			);
		}

	}//end testShippedMapTargetsAreDistinctAndDoNotCollideWithSources()
}//end class
