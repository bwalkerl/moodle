<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_assign\resolution;

use assignresolution_average\method as average;
use assignresolution_maximum\method as maximum;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

/**
 * Tests for resolution calculation methods.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(calculation_method::class)]
final class calculation_method_test extends \advanced_testcase {
    use helper;

    /**
     * Calculation methods extend the shared assignment and grade context.
     */
    public function test_calculation_constructor(): void {
        $constructor = new \ReflectionMethod(calculation_method::class, '__construct');
        $this->assertSame(2, $constructor->getNumberOfRequiredParameters());
        $this->assertSame(['assignment', 'grade', 'arguments'], array_map(
            fn(\ReflectionParameter $parameter) => $parameter->getName(),
            $constructor->getParameters(),
        ));
        foreach (['assignment', 'grade'] as $name) {
            $property = new \ReflectionProperty(calculation_method::class, $name);
            $this->assertTrue($property->isPromoted());
            $this->assertTrue($property->isReadOnly());
            $this->assertTrue($property->isProtected());
        }
        $this->assertFalse(property_exists(calculation_method::class, 'arguments'));
    }

    /**
     * Calculations should only consider marks from allocated markers who have completed marking.
     */
    public function test_get_considered_markers(): void {
        $calculation = $this->setup_calculation_requirements(average::class, [40, 80]);

        // The calculation should consider all allocated markers when all markers have marked.
        $markerids = $this->get_marker_ids();
        $this->assertSame($markerids, $calculation->get_considered_markers());
        $this->assertSame(60.0, $calculation->calculate_grade());

        // When a mark is in progress, it should not be considered.
        $this->grade->grader = $markerids[1];
        $this->assignment->update_mark($this->grade, 80, ASSIGN_MARKING_WORKFLOW_STATE_INMARKING);
        $this->assertSame([$markerids[0]], $calculation->get_considered_markers());
        $this->assertSame(40.0, $calculation->calculate_grade());

        // Considered markers should only include allocated markers.
        $this->unallocate_markers();
        $this->assertSame([], $calculation->get_considered_markers());
        $this->assertNull($calculation->calculate_grade());
    }

    /**
     * Test the resolve grade process, including that calculations apply rounding.
     *
     * @param string $classname Calculation class.
     * @param array $values Mark values.
     * @param float|null $expectedcalculation Expected calculated grade value.
     * @param float|null $expectedgrade Expected calculated grade value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resolve_grade_provider')]
    public function test_resolve_grade(
        string $classname,
        array $values,
        ?float $expectedcalculation,
        ?float $expectedgrade,
    ): void {
        $calculation = $this->setup_calculation_requirements($classname, $values, [
            'multimarkrounding' => rounding::NATURAL->value,
        ]);
        $this->assignment->clear_agreed_grade($this->grade);
        $markerids = $this->get_marker_ids();

        // Confirm rounding is automatically applied for methods that support rounding.
        $this->assertSame($expectedcalculation, $calculation->calculate_grade());

        // The resolved grade should match the calculated grade.
        $resolved = $calculation->resolve_grade(false);
        $this->assertEquals($expectedgrade, $this->get_current_grade()->grade);

        // After a grade has been resolved, get considered markers should return the snapshot of markers.
        $this->assertSame($markerids, $calculation->get_considered_markers());
        $this->unallocate_markers();
        $this->assertSame($resolved ? $markerids : [], $calculation->get_considered_markers());
    }

    /**
     * Provider for testing resolve grade behavior.
     *
     * @return array
     */
    public static function resolve_grade_provider(): array {
        return [
            'calculation method with rounding' => [average::class, [40, 81], 61.0, 61.0],
            'calculation method without rounding' => [maximum::class, [40, 81.5], 81.5, 81.5],
            'calculation method with no marks' => [average::class, [], null, ASSIGN_GRADE_NOT_SET],
            'calculation method with one mark' => [average::class, [40], 40, ASSIGN_GRADE_NOT_SET],
        ];
    }
}
