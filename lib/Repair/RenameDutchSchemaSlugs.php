<?php

/**
 * Renames this app's Dutch SCHEMA SLUGS in place, before the register import.
 *
 * OpenRegister's ImportHandler matches an incoming schema to an existing one by
 * SLUG (`SchemaMapper::findBySlugInIds()`). So changing a slug in the register
 * JSON does not rename anything: the import finds no match, CREATES a second
 * schema, and every object already stored keeps pointing at the old one. The
 * data is not lost — it is stranded behind a schema nothing reads any more,
 * and the app looks like it simply has no records.
 *
 * This step closes that gap by renaming the slug on the existing row first, so
 * the import that follows recognises the schema and updates it instead of
 * forking it. The shard table is named for the register and schema IDs, which
 * this step never touches, so the rows move with the schema untouched.
 *
 * ORDERING: this MUST run before `InitializeSettings`, which is what triggers
 * the import via `SettingsService::initialize()`. Registered first in
 * info.xml's post-migration block for that reason.
 *
 * The app-config keys derived from those slugs (`dienst_schema`) are NOT
 * migrated here. That is a separate change: the key family spans roughly forty
 * sites in SettingsService alone, including differently-prefixed
 * (`voorzieningen_contactpersoon_schema`) and compound (`koppeling_gebruik_schema`)
 * forms, and migrating a subset would resolve some object types while leaving
 * others silently "not configured". The objectType->key map keeps its existing
 * Dutch keys, so resolution is unaffected by this step.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames Dutch schema slugs on the rows the import will match against.
 */
class RenameDutchSchemaSlugs implements IRepairStep {

	/**
	 * Old slug => new slug, for schemas this app owns.
	 *
	 * Targets are read off each schema's own English `title`, not invented:
	 * `beoordeeling` was already titled "Assessment".
	 *
	 * `organisatie` => `organization` is here too, but the name is occupied: the
	 * app declared a SECOND organisation schema, the ArchiMate one, carrying the
	 * identity and statutory identifiers (name, oin, rsin, pki, tooi) and the
	 * round-trip `xml`. The two shared not one property. They are now merged into
	 * a single `organization` in the register JSON, and `retireArchimateOrganization()`
	 * frees the name on the existing rows before the rename runs.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'beoordeeling' => 'software-review',
		'bioMaatregel' => 'bioMeasure',
		'contactpersoon' => 'contactPerson',
		'dienst' => 'service',
		'gebruik' => 'usage',
		'koppeling' => 'connection',
		'kwetsbaarheid' => 'vulnerability',
		'moduleVersie' => 'moduleVersion',
		'organisatie' => 'organization',
	];

	/**
	 * Slug the absorbed ArchiMate organisation schema is parked under.
	 *
	 * Renamed rather than DELETED. The merge is only performed when that schema
	 * holds no rows, so nothing is lost either way — but a retired row costs
	 * nothing and keeps the decision reversible, where a DROP does not.
	 *
	 * @var string
	 */
	private const RETIRED_ARCHIMATE_SLUG = 'archimateOrganizationLegacy';

	/**
	 * Registers whose schemas are in scope.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_SLUGS = ['stackiq', 'vng-gemma'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 * @param RenameDutchSchemaSlugDecisions $decisions The pure predicates.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchSchemaSlugDecisions $decisions = new RenameDutchSchemaSlugDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Rename Dutch Stackiq schema slugs';
	}//end getName()

	/**
	 * Rename the slugs on this app's existing schema rows.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/english-vocabulary-migration/spec.md#requirement-renaming-a-stored-property-ships-a-data-migration
	 */
	public function run(IOutput $output): void {
		$schemaIds = $this->inScopeSchemaIds();
		if ($schemaIds === []) {
			$output->info('RenameDutchSchemaSlugs: no Stackiq registers on this install; nothing to do.');
			return;
		}

		$this->retireArchimateOrganization(schemaIds: $schemaIds, output: $output);

		$existing = $this->slugsOf(schemaIds: $schemaIds);

		$plan = $this->decisions->plan(map: self::SLUG_MAP, existing: $existing);

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: ' . $why . '; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		$refused = count($plan['refused']);
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new, schemaIds: $schemaIds) === true) {
				$renamed++;
			}
		}

		$output->info(
			sprintf(
				'RenameDutchSchemaSlugs: %d slug(s) renamed, %d refused.',
				$renamed,
				$refused
			)
		);
	}//end run()

	/**
	 * Free the name `organization` by parking the absorbed ArchiMate schema.
	 *
	 * The register JSON now declares ONE organisation schema: the catalogue's
	 * own, with the ArchiMate identity/statutory properties folded in. On an
	 * existing install two rows still carry the two old slugs, and `organisatie`
	 * cannot take a name another row holds.
	 *
	 * This only proceeds when the ArchiMate schema holds NO rows. Where it holds
	 * data, merging is a decision about that data — which row wins, how the
	 * disjoint property sets combine — and making it silently inside a repair
	 * step is precisely the class of change this programme keeps finding after
	 * the fact. So it refuses, loudly, and leaves both schemas alone.
	 *
	 * @param array<int, int> $schemaIds Schema ids in scope.
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function retireArchimateOrganization(array $schemaIds, IOutput $output): void {
		$pair = $this->decisions->organisationPair(rows: $this->schemaRows(schemaIds: $schemaIds));
		$archimate = $pair['archimate'];

		// Nothing to free: either the merge already ran, or this install never
		// had the second schema.
		if ($archimate === null || $pair['catalogue'] === null) {
			return;
		}

		$count = $this->rowCountFor(schemaId: (int)$archimate['id']);
		if ($this->decisions->mayRetire(rowCount: $count) === false) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: the ArchiMate organization schema holds rows; refusing to merge. '
				. 'Migrate them onto the catalogue organisation deliberately, then re-run repair.',
				['schemaId' => $archimate['id'], 'rows' => $count]
			);
			$output->warning(
				sprintf(
					'RenameDutchSchemaSlugs: ArchiMate organization schema holds %d row(s) — merge refused, both schemas left as they are.',
					$count
				)
			);
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[self::RETIRED_ARCHIMATE_SLUG, (int)$archimate['id']]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not retire the ArchiMate organization schema.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$output->info('RenameDutchSchemaSlugs: absorbed ArchiMate organization schema (0 rows) parked as ' . self::RETIRED_ARCHIMATE_SLUG . '.');
	}//end retireArchimateOrganization()

	/**
	 * Count the rows in a schema's shard table across this app's registers.
	 *
	 * Returns 0 when no shard table exists, which is the same answer as an empty
	 * one for the purpose of the merge guard.
	 *
	 * @param int $schemaId Schema id.
	 *
	 * @return int Row count.
	 */
	private function rowCountFor(int $schemaId): int {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%\_' . $schemaId);
			$stmt->execute();
			$tables = $stmt->fetchAll();
		} catch (\Throwable $e) {
			// Unable to look: treat as "has rows" so the merge refuses rather
			// than proceeding on an unchecked assumption.
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not list shard tables; treating the schema as non-empty.',
				['exception' => $e->getMessage()]
			);
			return -1;
		}

		$total = 0;
		foreach ($tables as $table) {
			$name = (string)($table['table_name'] ?? '');
			if ($this->decisions->isShardTableFor(tableName: $name, schemaId: $schemaId) === false) {
				continue;
			}

			try {
				$total += (int)$this->db->executeQuery('SELECT COUNT(*) FROM ' . $this->quote(identifier: $name))->fetchOne();
			} catch (Exception $e) {
				$this->logger->warning(
					'RenameDutchSchemaSlugs: could not count rows; treating the schema as non-empty.',
					['table' => $name, 'exception' => $e->getMessage()]
				);
				return -1;
			}
		}

		return $total;
	}//end rowCountFor()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()

	/**
	 * Read id and slug for the given schemas.
	 *
	 * @param array<int, int> $schemaIds Schema ids to read.
	 *
	 * @return array<int, array{id: int|string, slug: string}>
	 */
	private function schemaRows(array $schemaIds): array {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT id, slug FROM `*PREFIX*openregister_schemas` WHERE id IN (' . $placeholders . ')',
				array_map(static fn (int $id): string => (string)$id, $schemaIds)
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not read schemas; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $rows;
	}//end schemaRows()

	/**
	 * Resolve the schema ids belonging to this app's registers.
	 *
	 * @return array<int, int>
	 */
	private function inScopeSchemaIds(): array {
		$placeholders = implode(',', array_fill(0, count(self::REGISTER_SLUGS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				self::REGISTER_SLUGS
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->decisions->schemaIdsFrom(rows: $rows);
	}//end inScopeSchemaIds()

	/**
	 * Read the slugs currently held by the given schemas.
	 *
	 * @param array<int, int> $schemaIds Schema ids to read.
	 *
	 * @return array<int, string>
	 */
	private function slugsOf(array $schemaIds): array {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE id IN (' . $placeholders . ')',
				array_map(static fn (int $id): string => (string)$id, $schemaIds)
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: could not read schema slugs; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $row): string => (string)$row['slug'], $rows);
	}//end slugsOf()

	/**
	 * Rename one slug, scoped to this app's schemas.
	 *
	 * @param string $old Current slug.
	 * @param string $new Replacement slug.
	 * @param array<int, int> $schemaIds Schema ids in scope.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new, array $schemaIds): bool {
		$placeholders = implode(',', array_fill(0, count($schemaIds), '?'));

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND id IN (' . $placeholders . ')',
				array_merge([$new, $old], array_map(static fn (int $id): string => (string)$id, $schemaIds))
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchSchemaSlugs: slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()

}//end class
