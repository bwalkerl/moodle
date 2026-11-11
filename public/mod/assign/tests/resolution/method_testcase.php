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
 * Shared tests for resolution plugins..
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class method_testcase extends \advanced_testcase {
    use helper;

    /**
     * The plugin method shortname must fit assignment and calculation records.
     */
    final public function test_resolution_method_shortname(): void {
        $component = \core\component::get_component_from_classname(static::class);
        $this->assertNotNull($component);
        $classname = $component . '\\method';
        $this->assertTrue(is_subclass_of($classname, method::class));
        $this->assertFalse((new \ReflectionClass($classname))->isAbstract());
        $this->assertSame($component, $classname::get_component());
        $this->assertTrue(\mod_assign\plugininfo\assignresolution::is_valid_shortname($classname::get_basename()));
    }
}
