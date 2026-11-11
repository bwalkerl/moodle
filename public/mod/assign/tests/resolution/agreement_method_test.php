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

use assign;
use assignresolution_average\method as average;
use assignresolution_maximum\method as maximum;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

/**
 * Tests for resolution agreement methods and behavior.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(agreement_method::class)]
final class agreement_method_test extends \advanced_testcase {
    use helper;

    /**
     * Agreement methods use the shared assignment and grade context.
     */
    public function test_agreement_constructor(): void {
        $classname = agreement_method::class;
        $constructor = new \ReflectionMethod($classname, '__construct');
        $this->assertSame(2, $constructor->getNumberOfRequiredParameters());
        $this->assertSame(['assignment', 'grade'], array_map(
            fn(\ReflectionParameter $parameter) => $parameter->getName(),
            $constructor->getParameters(),
        ));
        foreach (['assignment', 'grade'] as $name) {
            $property = new \ReflectionProperty($classname, $name);
            $this->assertTrue($property->isPromoted());
            $this->assertTrue($property->isReadOnly());
            $this->assertTrue($property->isProtected());
        }
    }

    /**
     * Agreement rules can be used to select calculation methods that resolve grades.
     *
     * @param array $markvalues Mark values.
     * @param string $expectedmethod Expected calculation class.
     * @param float $expectedgrade Expected resolved grade.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('select_agreement_rule')]
    public function test_select_agreement_rule(
        array $markvalues,
        string $expectedmethod,
        float $expectedgrade,
    ): void {
        $this->setup_resolution_requirements($markvalues);
        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$this->assignment, $this->grade])
            ->onlyMethods(['select_agreement_rule'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(
            max($markvalues) - min($markvalues) <= 10 ? maximum::class : average::class,
        );

        // Select the calculation and resolve using this case's stable mark spread.
        $this->assertInstanceOf($expectedmethod, $method->get_calculation_method());
        $this->assertTrue($method->resolve_grade(false));
        $this->assertEquals($expectedgrade, $this->get_current_grade()->grade);
    }

    /**
     * Agreement calculation choices for different mark spreads.
     *
     * @return array
     */
    public static function select_agreement_rule(): array {
        return [
            'select maximum' => [[75, 80], maximum::class, 80.0],
            'select average' => [[20, 80], average::class, 50.0],
        ];
    }

    /**
     * Agreement methods stop resolution when they have no calculation method.
     */
    public function test_no_calculation_method(): void {
        $method = $this->setup_resolution_requirements([40, 80]);
        $this->assertNull($method->get_calculation_method());
        $this->set_manual_grade(75);
        $this->assertFalse($method->resolve_grade(true));
        $this->assertEquals(75, $this->get_current_grade()->grade);
    }

    /**
     * Calculation lookup does not apply actions, but grade resolution does.
     */
    public function test_apply_actions(): void {
        $this->setup_resolution_requirements([40, 80]);
        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$this->assignment, $this->grade])
            ->onlyMethods(['select_agreement_rule', 'apply_actions'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(average::class);

        // Confirm calls to apply actions.
        $actionsapplied = false;
        $method->expects($this->once())->method('apply_actions')->willReturnCallback(
            function () use (&$actionsapplied): bool {
                $actionsapplied = true;
                return false;
            },
        );

        $this->assertInstanceOf(average::class, $method->get_calculation_method());
        $this->assertFalse($actionsapplied);

        $this->assertTrue($method->resolve_grade(false));
        $this->assertTrue($actionsapplied);
    }

    /**
     * Resolution can update an optional marker without recalculating recursively.
     */
    public function test_marker_allocation_does_not_recalculate(): void {
        $this->setup_resolution_requirements([80], [
            'markercount' => 1,
            'optionalmarkercount' => 1,
            'multimarkmethod' => average::get_basename(),
        ]);
        $grade = $this->get_current_grade();
        $this->assertEquals(80, $grade->grade);
        $assignment = $this->getMockBuilder(assign::class)
            ->setConstructorArgs([
                $this->assignment->get_context(),
                $this->assignment->get_course_module(),
                $this->assignment->get_course(),
            ])
            ->onlyMethods(['get_resolution_method'])
            ->getMock();

        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$assignment, $grade])
            ->onlyMethods(['select_agreement_rule', 'apply_actions'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(average::class);

        // Calculate and save agreed grades should be called twice, but resolve grade only once.
        // Use apply actions as a proxy for resolve grade calls.
        $method->expects($this->once())->method('apply_actions')->willReturnCallback(
            function () use ($assignment, $grade): bool {
                $assignment->update_marker_allocations($grade->userid, [2 => [0, 1]]);
                $this->assertEquals(1, $assignment->get_marker_allocations($grade->userid)[2]->enabled);
                return true;
            },
        );
        $assignment->expects($this->exactly(2))
            ->method('get_resolution_method')
            ->willReturn($method);
        $this->assertTrue($assignment->calculate_and_save_agreed_grade($grade, true));

        // Grade should be reset because an optional marker was enabled.
        $this->assertEquals(ASSIGN_GRADE_NOT_SET, $assignment->get_user_grade($grade->userid, false)->grade);
        $this->assertEquals(1, $assignment->get_marker_allocations($grade->userid)[2]->enabled);
    }

    /**
     * Save hooks run inside the transaction on either side of the grade update.
     */
    public function test_resolve_grade_hooks(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->setup_resolution_requirements([40, 80]);
        $this->set_manual_grade(60);
        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$this->assignment, $this->grade])
            ->onlyMethods(['select_agreement_rule', 'before_resolution_saved', 'after_resolution_saved'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(maximum::class);

        $method->expects($this->once())->method('before_resolution_saved')
            ->willReturnCallback(function (float $value): void {
                global $DB;
                $this->assertTrue($DB->is_transaction_started());
                $this->assertSame(80.0, $value);
                $this->assertEquals(60, $this->get_current_grade()->grade);
            });

        $method->expects($this->once())->method('after_resolution_saved')
            ->willReturnCallback(function (float $value): void {
                global $DB;
                $this->assertTrue($DB->is_transaction_started());
                $this->assertSame(80.0, $value);
                $this->assertEquals(80, $this->get_current_grade()->grade);
            });

        $this->assertTrue($method->resolve_grade(false));
        $this->assertFalse($DB->is_transaction_started());
    }

    /**
     * A failed grade update does not invoke the code that requires a grade update.
     */
    public function test_resolve_grade_update(): void {
        $this->setup_resolution_requirements([40, 80]);
        $assignment = $this->getMockBuilder(assign::class)
            ->setConstructorArgs([
                $this->assignment->get_context(),
                $this->assignment->get_course_module(),
                $this->assignment->get_course(),
            ])
            ->onlyMethods(['update_grade'])
            ->getMock();
        $assignment->expects($this->once())->method('update_grade')->willReturn(false);

        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$assignment, $this->grade])
            ->onlyMethods(['select_agreement_rule', 'before_resolution_saved', 'after_resolution_saved'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(maximum::class);
        $method->expects($this->once())->method('before_resolution_saved');
        $method->expects($this->never())->method('after_resolution_saved');

        $this->assertFalse($method->resolve_grade(false));
        $this->assertEquals(ASSIGN_GRADE_NOT_SET, $this->get_current_grade()->grade);
    }

    /**
     * A failing hook rolls back the grade update.
     */
    public function test_resolve_grade_rollback(): void {
        $this->preventResetByRollback();
        $this->setup_resolution_requirements([40, 80]);
        $this->set_manual_grade(60);
        $method = $this->getMockBuilder(agreement_method::class)
            ->setConstructorArgs([$this->assignment, $this->grade])
            ->onlyMethods(['select_agreement_rule', 'after_resolution_saved'])
            ->getMock();
        $method->method('select_agreement_rule')->willReturn(maximum::class);
        $method->expects($this->once())->method('after_resolution_saved')
            ->willThrowException(new \RuntimeException('Post save failure'));

        try {
            $method->resolve_grade(false);
            $this->fail('The post-save exception should be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Post save failure', $exception->getMessage());
        }
        $this->assertEquals(60, $this->get_current_grade()->grade);
    }
}
