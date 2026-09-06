<?php

/**
 * The review-moderation overlay lands on the schema the reviews are in.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-public-read-access-to-a-review-must-be-restricted-to-approved-reviews
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the REAL fragment onto the REAL register, which is the only thing that
 * can tell "the merge works" from "the merge reaches the right schema".
 *
 * `DeepMergeAuthorizationTest` proves `deepMergeConfig()` replaces an
 * `authorization` list rather than concatenating it, and it proved that
 * throughout the period this was broken. It builds its own base keyed to match
 * the fragment, so the one thing it could never notice is the fragment naming a
 * schema the base does not have.
 *
 * That is what happened. `beoordeeling` was TITLED "Assessment" and its slug
 * became `software-review` (`RenameDutchSchemaSlugs`), and the fragment was
 * keyed on the title. It overlaid a schema nothing declares, so the real
 * `software-review` kept its base `read: ["public"]`: every pending and
 * rejected review was publicly readable, which is the exact hole the fragment
 * was written to close. Nothing failed, because the fragment merge unions by
 * key and a key nobody else uses simply creates a schema nobody reads.
 */
class ReviewModerationOverlayReachesTheSchemaTest extends TestCase {

	/**
	 * The register JSON with every `register.d` fragment merged over it.
	 *
	 * @return array<mixed> The merged register.
	 */
	private function mergedRegister(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);
		$this->assertIsArray($base, 'the register JSON must parse');

		$method = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$method->setAccessible(true);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		$this->assertNotEmpty($fragments, 'there must be fragments to merge');

		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray($overlay, basename($fragment).' must parse');
			$base = $method->invoke(null, $base, $overlay);
		}

		return $base;

	}//end mergedRegister()

	/**
	 * Every schema a `register.d` fragment names must already exist in the
	 * register it overlays.
	 *
	 * A fragment is an OVERLAY, and the merge unions by key, so a fragment
	 * naming a schema the base does not have does not fail: it quietly creates
	 * one. The overlay then applies perfectly, to nothing.
	 *
	 * @return void
	 */
	public function testEveryFragmentOverlaysASchemaThatExists(): void {
		$root = dirname(__DIR__, 3);

		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);
		$declared = array_keys($base['components']['schemas']);

		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		$this->assertNotEmpty($fragments);

		$orphans = [];
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			foreach (array_keys(($overlay['components']['schemas'] ?? [])) as $slug) {
				if (in_array($slug, $declared, true) === false) {
					$orphans[] = basename($fragment).' -> '.$slug;
				}
			}
		}

		$this->assertSame([], $orphans, 'a fragment overlays a schema the register does not declare');

	}//end testEveryFragmentOverlaysASchemaThatExists()

	/**
	 * The schema the review services actually write to carries the gated public
	 * read, and no bare `"public"` survives the merge.
	 *
	 * Keyed off the services' own constants rather than a literal, so a later
	 * slug move breaks this test instead of quietly un-gating the schema again.
	 *
	 * @return void
	 */
	public function testTheReviewSchemaPublicReadIsGatedOnApproved(): void {
		$slug = ReviewService::REVIEW_TYPE;
		$this->assertSame(
			$slug,
			ModerationService::MODERATED_TYPE_REVIEW,
			'the write path and the moderation queue must name the same schema'
		);

		$schemas = $this->mergedRegister()['components']['schemas'];
		$this->assertArrayHasKey($slug, $schemas);

		$read = $schemas[$slug]['authorization']['read'];

		$this->assertNotContains(
			'public',
			$read,
			'a bare public read makes every pending and rejected review world-readable'
		);
		$this->assertContains(
			['group' => 'public', 'match' => ['status' => 'approved']],
			$read,
			'anonymous readers must see approved reviews and only those'
		);

	}//end testTheReviewSchemaPublicReadIsGatedOnApproved()

	/**
	 * The base register on its own is NOT gated. Without this the test above
	 * cannot distinguish "the overlay applied" from "the base was already fine",
	 * and it would keep passing if the fragment stopped being merged at all.
	 *
	 * @return void
	 */
	public function testTheBaseRegisterIsUngatedWithoutTheFragment(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode(
			(string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'),
			true
		);

		$read = $base['components']['schemas'][ReviewService::REVIEW_TYPE]['authorization']['read'];

		$this->assertContains(
			'public',
			$read,
			'the base is expected to carry the bare public read that the fragment removes; '
			.'if the base has been gated directly, this test and the fragment are now redundant'
		);

	}//end testTheBaseRegisterIsUngatedWithoutTheFragment()

}//end class
