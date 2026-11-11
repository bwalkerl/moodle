<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_assign\plugininfo;

use core\plugininfo\base;

/**
 * Assignment resolution subplugin information.
 *
 * @package    mod_assign
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignresolution extends base {
    /**
     * Whether a plugin supplies a valid resolution method.
     *
     * @param string $pluginname Plugin shortname.
     * @return bool
     */
    public static function is_valid_method(string $pluginname): bool {
        $component = 'assignresolution_' . $pluginname;
        $method = $component . '\\method';
        return is_subclass_of($method, \mod_assign\resolution\method::class)
            && !(new \ReflectionClass($method))->isAbstract()
            && $method::get_component() === $component
            && self::is_valid_shortname($method::get_basename());
    }

    /**
     * Whether a shortname is syntactically valid and fits the database field.
     *
     * @param string $pluginname Plugin shortname.
     * @return bool
     */
    public static function is_valid_shortname(string $pluginname): bool {
        return preg_match('/^[a-z][a-z0-9_]*$/D', $pluginname) && strlen($pluginname) <= 10;
    }

    /**
     * Get the resolution method class supplied by this plugin.
     *
     * @return string
     */
    public function get_method_class(): string {
        return $this->component . '\\method';
    }

    /**
     * Get the class registered for a resolution shortname.
     *
     * @param string $shortname Resolution plugin shortname.
     * @return string Resolution class, falling back to Manual when not registered.
     */
    public static function get_resolution_class(string $shortname): string {
        $methods = self::get_resolution_methods();
        return $methods[$shortname] ?? \assignresolution_manual\method::class;
    }

    /**
     * Get valid resolution methods in assignment plugin order.
     *
     * @param bool $enabledonly Whether to include only enabled plugins.
     * @return string[] Method classes keyed by shortname.
     */
    public static function get_resolution_methods(bool $enabledonly = false): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/adminlib.php');

        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('assignresolution');
        $sorted = [];
        foreach ((new \assign_plugin_manager('assignresolution'))->get_sorted_plugins_list() as $name) {
            $plugin = $plugins[$name] ?? null;
            if (
                $plugin instanceof self && isset($plugin->rootdir) && self::is_valid_method($name)
                && (!$enabledonly || $plugin->is_enabled())
            ) {
                $method = $plugin->get_method_class();
                $sorted[$method::get_basename()] = $method;
            }
        }
        return $sorted;
    }

    /**
     * Get available assignment resolution methods, retaining the current method regardless of availability.
     *
     * @param \assign $assign Assignment being configured.
     * @return string[] Method classes keyed by stored identifier, with Manual used for a missing current method.
     */
    public static function get_available_resolution_methods(\assign $assign): array {
        $current = $assign->has_instance() ? ($assign->get_instance()->multimarkmethod ?? '') : '';
        $methods = [];
        foreach (self::get_resolution_methods() as $identifier => $method) {
            if ($identifier !== $current && (!$method::is_visible() || !$method::supports_assignment_selection())) {
                continue;
            }
            $methods[$identifier] = $method;
        }
        if ($current && !isset($methods[$current])) {
            $methods[$current] = self::get_resolution_class($current);
        }
        return $methods;
    }

    /**
     * Get calculation methods in assignment plugin order.
     *
     * @param bool $enabledonly Whether to include only enabled plugins.
     * @return string[] Calculation classes keyed by shortname.
     */
    public static function get_calculation_methods(bool $enabledonly = false): array {
        return array_filter(
            self::get_resolution_methods($enabledonly),
            fn($method) => is_subclass_of($method, \mod_assign\resolution\calculation_method::class),
        );
    }

    /**
     * Whether resolution providers can be disabled site-wide.
     *
     * @return bool
     */
    public static function plugintype_supports_disabling(): bool {
        return true;
    }

    /**
     * Get installed providers which are not disabled.
     *
     * @return array Enabled plugin names keyed by name.
     */
    public static function get_enabled_plugins(): array {
        $enabled = [];
        foreach (\core_plugin_manager::instance()->get_installed_plugins('assignresolution') as $name => $version) {
            if (!get_config('assignresolution_' . $name, 'disabled')) {
                $enabled[$name] = $name;
            }
        }
        return $enabled;
    }

    /**
     * Enable or disable a provider through the standard management controller.
     *
     * @param string $pluginname Plugin name.
     * @param int $enabled Whether the provider should be enabled.
     * @return bool Whether the configuration changed.
     */
    public static function enable_plugin(string $pluginname, int $enabled): bool {
        $component = 'assignresolution_' . $pluginname;
        $oldvalue = get_config($component, 'disabled');
        $disabled = !$enabled;
        if ($oldvalue !== false && (bool) $oldvalue === $disabled) {
            return false;
        }
        set_config('disabled', $disabled, $component);
        add_to_config_log('disabled', $oldvalue, $disabled, $component);
        \core_plugin_manager::reset_caches();
        return true;
    }

    /**
     * Get the resolution provider management URL.
     *
     * @return \moodle_url
     */
    public static function get_manage_url(): \moodle_url {
        return new \moodle_url('/mod/assign/adminmanageplugins.php', ['subtype' => 'assignresolution']);
    }

    /**
     * Get the settings section used by the management page.
     *
     * @return string
     */
    public function get_settings_section_name(): string {
        return $this->type . '_' . $this->name;
    }

    /**
     * Load a provider's optional settings beneath the resolution plugins category.
     *
     * @param \part_of_admin_tree $adminroot Administration tree.
     * @param string $parentnodename Parent category.
     * @param bool $hassiteconfig Whether the current user can configure the site.
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE;

        if (!$this->is_installed_and_upgraded() || !$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }
        $ADMIN = $adminroot;
        $plugininfo = $this;
        $settings = new \admin_settingpage(
            $this->get_settings_section_name(),
            $this->displayname,
            'moodle/site:config',
            $this->is_enabled() === false,
        );
        if ($adminroot->fulltree) {
            $shortsubtype = 'resolution';
            include($this->full_path('settings.php'));
        }
        $adminroot->add($parentnodename, $settings);
    }

    /**
     * Keep the standard provider installed while allowing removal of extensions.
     *
     * @return bool
     */
    public function is_uninstall_allowed(): bool {
        return !$this->is_standard();
    }
}
