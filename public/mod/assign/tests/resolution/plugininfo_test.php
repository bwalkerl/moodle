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
use assignresolution_manual\method as manual;
use assignresolution_maximum\method as maximum;
use mod_assign\plugininfo\assignresolution;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/adminlib.php');
require_once(__DIR__ . '/../generator.php');

/**
 * Tests for assignment resolution plugin discovery and management.
 *
 * @package    mod_assign
 * @category   test
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(assignresolution::class)]
final class plugininfo_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /**
     * Resolution plugins require a valid method.
     *
     * @param string $pluginname Plugin shortname.
     * @param bool $valid Whether the plugin is valid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resolution_validation_provider')]
    public function test_resolution_plugin_validation(string $pluginname, bool $valid): void {
        $this->assertSame($valid, \mod_assign\plugininfo\assignresolution::is_valid_method($pluginname));
    }

    /**
     * Resolution plugin validation cases.
     *
     * @return array
     */
    public static function resolution_validation_provider(): array {
        return [
            'valid method' => [average::get_basename(), true],
            'missing method' => ['missing', false],
        ];
    }

    /**
     * Shortname syntax and length are validated independently of class lookup.
     *
     * @param string $pluginname Plugin shortname.
     * @param bool $valid Whether the shortname is valid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resolution_shortname_provider')]
    public function test_resolution_plugin_shortname(string $pluginname, bool $valid): void {
        $this->assertSame($valid, assignresolution::is_valid_shortname($pluginname));
    }

    /**
     * Resolution plugin shortname validation cases.
     *
     * @return array
     */
    public static function resolution_shortname_provider(): array {
        return [
            'valid' => [average::get_basename(), true],
            'maximum length' => ['abcdefghij', true],
            'too long' => ['abcdefghijk', false],
            'invalid syntax' => ['bad-name', false],
        ];
    }

    /**
     * Each subplugin supplies exactly one method identified by its shortname.
     */
    public function test_resolution_plugin(): void {
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('assignresolution');
        foreach ($plugins as $name => $plugin) {
            $method = $plugin->get_method_class();
            $this->assertTrue(assignresolution::is_valid_method($method::get_basename()));
            $this->assertSame('assignresolution_' . $name, $method::get_component());
            $this->assertTrue(is_subclass_of($method, method::class));
        }
    }

    /**
     * Resolution methods return expected methods and include disabled plugins unless enabled-only is requested.
     */
    public function test_get_resolution_methods(): void {
        $this->resetAfterTest();
        $methods = assignresolution::get_resolution_methods();
        $enabledmethods = assignresolution::get_resolution_methods(true);

        // Resolution methods contain all core methods.
        $this->assertArrayHasKey(average::get_basename(), $methods);
        $this->assertArrayHasKey(maximum::get_basename(), $methods);
        $this->assertArrayHasKey(manual::get_basename(), $methods);

        // Returned methods respect enabled flag.
        $name = maximum::get_basename();
        $this->assertArrayHasKey($name, $enabledmethods);
        assignresolution::enable_plugin($name, false);
        $this->assertArrayHasKey($name, assignresolution::get_resolution_methods());
        $this->assertArrayNotHasKey($name, assignresolution::get_resolution_methods(true));
    }

    /**
     * Calculation methods exclude agreement methods and respect enabled-only filtering.
     */
    public function test_get_calculation_methods(): void {
        $this->resetAfterTest();
        $methods = assignresolution::get_calculation_methods();
        $enabledmethods = assignresolution::get_calculation_methods(true);

        // Calculation methods contain all core calculation methods and not agreement methods.
        $this->assertArrayHasKey(average::get_basename(), $methods);
        $this->assertArrayHasKey(maximum::get_basename(), $methods);
        $this->assertArrayNotHasKey(manual::get_basename(), $methods);

        // Returned methods respect enabled flag.
        $name = maximum::get_basename();
        $this->assertArrayHasKey($name, $enabledmethods);
        assignresolution::enable_plugin($name, false);
        $this->assertArrayHasKey($name, assignresolution::get_calculation_methods());
        $this->assertArrayNotHasKey($name, assignresolution::get_calculation_methods(true));
    }

    /**
     * Test plugin enabled status.
     */
    public function test_enable_plugin(): void {
        $this->resetAfterTest();
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('assignresolution');
        $name = maximum::get_basename();
        $component = maximum::get_component();

        // Plugin starts as enabled.
        $this->assertFalse(get_config($component, 'disabled'));
        $this->assertTrue(maximum::is_visible());
        $this->assertArrayHasKey($name, assignresolution::get_enabled_plugins());

        // Disable the plugin.
        $this->assertTrue(assignresolution::enable_plugin($name, false));
        $this->assertFalse(assignresolution::enable_plugin($name, false));
        $this->assertEquals(1, get_config($component, 'disabled'));
        $this->assertFalse(maximum::is_visible());
        $this->assertArrayNotHasKey($name, assignresolution::get_enabled_plugins());
        $this->assertFalse($plugins[$name]->is_enabled());

        // Re-enable the plugin.
        $this->assertTrue(assignresolution::enable_plugin($name, true));
        $this->assertEquals(0, get_config($component, 'disabled'));
        $this->assertTrue(maximum::is_visible());
        $this->assertArrayHasKey($name, assignresolution::get_enabled_plugins());
        $this->assertTrue($plugins[$name]->is_enabled());
    }

    /**
     * Method discovery uses assignment plugin sort order, including collisions.
     */
    public function test_resolution_plugin_sort_order(): void {
        $this->resetAfterTest();
        $average = average::get_basename();
        $maximum = maximum::get_basename();
        $manual = manual::get_basename();
        $otherplugins = array_values(array_diff(
            array_keys(\core_plugin_manager::instance()->get_plugins_of_type('assignresolution')),
            [$average, $manual, $maximum],
        ));
        $orderedplugins = array_merge([$average, $manual, $maximum], $otherplugins);
        foreach ($orderedplugins as $sortorder => $pluginname) {
            set_config('sortorder', $sortorder, 'assignresolution_' . $pluginname);
        }
        $pluginmanager = new \assign_plugin_manager('assignresolution');

        $assertbefore = function (array $methods, string $first, string $second): void {
            $firstposition = array_search($first, $methods, true);
            $secondposition = array_search($second, $methods, true);
            $this->assertIsInt($firstposition);
            $this->assertIsInt($secondposition);
            $this->assertLessThan($secondposition, $firstposition);
        };

        // Moving Maximum up once puts it between Average and Manual.
        $pluginmanager->move_plugin($maximum, 'up');
        $resolutionmethods = array_keys(assignresolution::get_resolution_methods());
        $assertbefore($resolutionmethods, $average, $maximum);
        $assertbefore($resolutionmethods, $maximum, $manual);
        $calculationmethods = array_keys(assignresolution::get_calculation_methods());
        $assertbefore($calculationmethods, $average, $maximum);

        // Moving maximum up again makes it the first resolution and calculation method.
        $pluginmanager->move_plugin($maximum, 'up');
        $resolutionmethods = array_keys(assignresolution::get_resolution_methods());
        $assertbefore($resolutionmethods, $maximum, $average);
        $assertbefore($resolutionmethods, $average, $manual);
        $calculationmethods = array_keys(assignresolution::get_calculation_methods());
        $assertbefore($calculationmethods, $maximum, $average);

        // When manual and maximum share sort order, plugin order breaks the tie.
        set_config('sortorder', 2, 'assignresolution_' . $average);
        set_config('sortorder', 0, 'assignresolution_' . $manual);
        set_config('sortorder', 0, 'assignresolution_' . $maximum);
        $sortorder = 3;
        foreach ($otherplugins as $pluginname) {
            set_config('sortorder', $sortorder++, 'assignresolution_' . $pluginname);
        }
        $resolutionmethods = array_keys(assignresolution::get_resolution_methods());
        $assertbefore($resolutionmethods, $manual, $maximum);
        $assertbefore($resolutionmethods, $maximum, $average);
    }

    /**
     * Resolution lookup returns registered plugins and falls back to Manual.
     */
    public function test_get_resolution_class(): void {
        $this->resetAfterTest();
        $this->assertSame(maximum::class, assignresolution::get_resolution_class(maximum::get_basename()));

        // Disabled plugins should still return the resolution class.
        assignresolution::enable_plugin(maximum::get_basename(), false);
        $this->assertSame(maximum::class, assignresolution::get_resolution_class(maximum::get_basename()));

        // Missing and invalid classes should default to manual behaviour.
        $this->assertSame(manual::class, assignresolution::get_resolution_class('absent'));
        $this->assertSame(manual::class, assignresolution::get_resolution_class('bad-name'));
    }

    /**
     * Tests the availability of disabled resolution methods on the assign mod_form.
     */
    public function test_disabled_resolution_method_form(): void {
        $this->resetAfterTest();
        assignresolution::enable_plugin(maximum::get_basename(), false);
        assignresolution::enable_plugin(average::get_basename(), false);

        // Keep the disabled method available when it is already selected.
        $choices = $this->get_resolution_method_dropdown(maximum::get_basename());
        $this->assertTrue($choices->has_value(maximum::get_basename()));

        // Hide the disabled method when another method is selected.
        $this->assertFalse($choices->has_value(average::get_basename()));
    }

    /**
     * Tests the availability of missing resolution methods on the assign mod_form.
     */
    public function test_missing_resolution_method_form(): void {
        $this->resetAfterTest();
        $method = 'missing';

        // Retain the unavailable stored method as a choice.
        $choices = $this->get_resolution_method_dropdown($method);
        $this->assertTrue($choices->has_value($method));

        // The missing method should resolve using manual.
        $this->assertSame(manual::class, assignresolution::get_resolution_class($method));
    }

    /**
     * Get assignment form method choices for a current resolution method.
     *
     * @param string $currentmethod Current assignment method.
     * @return \core\output\choicelist
     */
    private function get_resolution_method_dropdown(string $currentmethod): \core\output\choicelist {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->create_instance($course, [
            'markercount' => 2,
            'multimarkmethod' => $currentmethod,
        ]);
        $this->setAdminUser();
        $PAGE->set_course($course);
        $current = clone $assignment->get_instance();
        $current->instance = $current->id;
        $current->coursemodule = $assignment->get_course_module()->id;
        $form = new \mod_assign_mod_form($current, 1, $assignment->get_course_module(), $course);
        $form->set_data($current);
        $quickform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        $element = $quickform->getElement('multimarkmethod');
        $this->assertSame([$currentmethod], $element->getValue());
        return (new \ReflectionProperty(\MoodleQuickForm_choicedropdown::class, 'choice'))->getValue($element);
    }
}
