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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

/**
 * Tests for the shared resolution method behavior.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(method::class)]
final class method_test extends \advanced_testcase {
    use helper;

    /**
     * Resolution methods use explicit assignment and grade context.
     */
    public function test_method_constructor(): void {
        $constructor = new \ReflectionMethod(method::class, '__construct');
        $this->assertSame(2, $constructor->getNumberOfRequiredParameters());
        $this->assertSame(['assignment', 'grade'], array_map(
            fn(\ReflectionParameter $parameter) => $parameter->getName(),
            $constructor->getParameters(),
        ));
        foreach (['assignment', 'grade'] as $name) {
            $property = new \ReflectionProperty(method::class, $name);
            $this->assertTrue($property->isPromoted());
            $this->assertTrue($property->isReadOnly());
            $this->assertTrue($property->isProtected());
        }
    }

    /**
     * Grade resolution is controlled by the shared final implementation.
     */
    public function test_resolve_grade_is_final(): void {
        $this->assertTrue((new \ReflectionMethod(method::class, 'resolve_grade'))->isFinal());
    }

    /**
     * Tests stale grade clearing and preservation conditions.
     */
    public function test_resolve_grade_clears_stale_grades(): void {
        // Create a resolution that doesn't meet resolution requirements.
        $method = $this->setup_resolution_requirements();

        // Clear an existing positive stale grade.
        $this->set_manual_grade(75);
        $this->assertTrue($method->resolve_grade(true));
        $this->assertEquals(ASSIGN_GRADE_NOT_SET, $this->get_current_grade()->grade);

        // Clear a stale grade of zero.
        $this->set_manual_grade(0);
        $this->assertTrue($method->resolve_grade(true));
        $this->assertEquals(ASSIGN_GRADE_NOT_SET, $this->get_current_grade()->grade);

        // Preserve an existing grade when flag is false.
        $this->set_manual_grade(75);
        $this->assertFalse($method->resolve_grade(false));
        $this->assertEquals(75, $this->get_current_grade()->grade);

        // Do not clear a grade that is already unset.
        $this->set_manual_grade(ASSIGN_GRADE_NOT_SET);
        $this->assertFalse($method->resolve_grade(true));
        $this->assertEquals(ASSIGN_GRADE_NOT_SET, $this->get_current_grade()->grade);

        // Do not clear when no grade record value exists.
        $this->set_manual_grade(null);
        $this->assertFalse($method->resolve_grade(true));
        $this->assertNull($this->get_current_grade()->grade);
    }
}
