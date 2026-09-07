<?php

/**
 * Tests for the EOL sync configuration re-pointing.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RepointEolSyncConfig;
use OCP\IAppConfig;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The re-pointing decision table.
 *
 * `repoint()` is pure, so it is exercised directly: what has to be right is
 * which stored values it rewrites and, more importantly, which it leaves alone.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Repair\RepointEolSyncConfig
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
 */
final class RepointEolSyncConfigTest extends TestCase {

	/**
	 * The step under test.
	 *
	 * @var RepointEolSyncConfig
	 */
	private RepointEolSyncConfig $step;

	/**
	 * Set up the subject with mocked collaborators the planner never touches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->step = new RepointEolSyncConfig(
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * The step is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStepWithAName(): void {
		$this->assertInstanceOf(IRepairStep::class, $this->step);
		$this->assertNotSame('', $this->step->getName());

	}//end testItIsARepairStepWithAName()

	/**
	 * A configuration an admin saved before either rename is fully re-pointed.
	 *
	 * @return void
	 */
	public function testItRepointsAllThreeStaleSlugs(): void {
		$result = $this->step->repoint([
			'enabled' => true,
			'register' => 'openconnector',
			'productSchema' => 'eolProduct',
			'cycleSchema' => 'eolCycle',
			'intervalSeconds' => 86400,
		]);

		$this->assertSame(3, $result['changed']);
		$this->assertSame('integriq', $result['config']['register']);
		$this->assertSame('eol_product', $result['config']['productSchema']);
		$this->assertSame('eol_cycle', $result['config']['cycleSchema']);

	}//end testItRepointsAllThreeStaleSlugs()

	/**
	 * Fields the map does not name are carried through untouched.
	 *
	 * @return void
	 */
	public function testItLeavesEveryOtherFieldAlone(): void {
		$result = $this->step->repoint([
			'enabled' => true,
			'register' => 'openconnector',
			'intervalSeconds' => 3600,
		]);

		$this->assertTrue($result['config']['enabled']);
		$this->assertSame(3600, $result['config']['intervalSeconds']);

	}//end testItLeavesEveryOtherFieldAlone()

	/**
	 * An admin pointing the feature at their own register is never rewritten.
	 *
	 * This is the assertion that makes the step safe to run unconditionally on
	 * every upgrade: the guard is the exact stored value, not the field name.
	 *
	 * @return void
	 */
	public function testItNeverRewritesAnAdminsOwnSlugs(): void {
		$config = [
			'enabled' => true,
			'register' => 'our-own-register',
			'productSchema' => 'products',
			'cycleSchema' => 'cycles',
		];

		$result = $this->step->repoint($config);

		$this->assertSame(0, $result['changed']);
		$this->assertSame($config, $result['config']);

	}//end testItNeverRewritesAnAdminsOwnSlugs()

	/**
	 * A second run has nothing to do.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$once = $this->step->repoint([
			'register' => 'openconnector',
			'productSchema' => 'eolProduct',
			'cycleSchema' => 'eolCycle',
		]);
		$twice = $this->step->repoint($once['config']);

		$this->assertSame(0, $twice['changed']);
		$this->assertSame($once['config'], $twice['config']);

	}//end testASecondRunChangesNothing()

	/**
	 * A non-string value is left alone rather than coerced.
	 *
	 * @return void
	 */
	public function testItIgnoresANonStringValue(): void {
		$result = $this->step->repoint(['register' => 42]);

		$this->assertSame(0, $result['changed']);
		$this->assertSame(42, $result['config']['register']);

	}//end testItIgnoresANonStringValue()

	/**
	 * An empty configuration is not invented into a full one.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigurationStaysEmpty(): void {
		$result = $this->step->repoint([]);

		$this->assertSame(0, $result['changed']);
		$this->assertSame([], $result['config']);

	}//end testAnEmptyConfigurationStaysEmpty()
}//end class
