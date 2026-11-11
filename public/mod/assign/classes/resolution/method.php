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

/**
 * Shared identity and configuration metadata for assignment resolution methods.
 *
 * This uses similar methods to assign_plugin, but has been kept separate due to different requirements.
 *
 * @package    mod_assign
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class method {
    /** @var array|null mark snapshot of marks used during grade resolution. */
    protected ?array $marks = null;

    /**
     * Initialise the resolution context.
     *
     * @param \assign $assignment Assignment being resolved.
     * @param \stdClass $grade Grade record for this attempt.
     */
    public function __construct(
        /** @var \assign Assignment using this method. */
        protected readonly \assign $assignment,
        /** @var \stdClass Grade record for this attempt. */
        protected readonly \stdClass $grade,
    ) {
    }

    /**
     * Get the stable method identifier.
     *
     * @return string
     */
    public static function get_basename(): string {
        return substr(static::get_component(), strlen('assignresolution_'));
    }

    /**
     * Get the owning plugin component.
     *
     * @return string
     */
    public static function get_component(): string {
        return \core\component::get_component_from_classname(static::class);
    }

    /**
     * Get the assignment plugin subtype.
     *
     * @return string
     */
    public static function get_subtype(): string {
        return 'assignresolution';
    }

    /**
     * Get the method display name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('pluginname', static::get_component());
    }

    /**
     * Get the description displayed in assignment settings.
     *
     * @return string
     */
    public static function get_description(): string {
        return get_string('pluginname_help', static::get_component());
    }

    /**
     * Whether the resolution plugin is visible site-wide.
     *
     * @return bool
     */
    final public static function is_visible(): bool {
        return !get_config(static::get_component(), 'disabled');
    }

    /**
     * Whether the method automatically derives a grade.
     *
     * @return bool
     */
    public function is_automatic(): bool {
        return true;
    }

    /**
     * Whether this method supports selection in assignment settings.
     *
     * @return bool
     */
    public static function supports_assignment_selection(): bool {
        return true;
    }

    /**
     * Whether to display rounding options in assignment settings.
     *
     * @return bool
     */
    public static function display_rounding_options(): bool {
        return false;
    }

    /**
     * Whether enough marks are available to resolve the grade.
     *
     * @return bool
     */
    public function can_resolve_grade(): bool {
        return count($this->get_marks()) >= $this->assignment->expected_marker_count($this->grade->userid);
    }

    /**
     * Get all completed marks from currently allocated markers for this attempt.
     *
     * @return array
     */
    protected function get_marks(): array {
        if (isset($this->marks)) {
            return $this->marks;
        }

        $marks = $this->assignment->get_mark_records($this->grade->id, $this->grade->userid);
        return array_filter($marks, fn($mark) => $this->assignment->mark_is_marked($mark));
    }

    /**
     * Get the calculation currently determined by this resolution method.
     *
     * @return calculation_method|null
     */
    abstract public function get_calculation_method(): ?calculation_method;

    /**
     * Resolves and saves the agreed grade.
     *
     * @param bool $clearstale Whether to clear an existing grade when resolution is unavailable.
     * @return bool Whether the agreed grade was updated.
     */
    final public function resolve_grade(bool $clearstale): bool {
        global $DB;

        $ready = $this->can_resolve_grade();
        if ($ready && $this instanceof agreement_method && $this->apply_actions()) {
            // The action has changed the resolution conditions, need to check resolution again.
            $ready = $this->can_resolve_grade();
        }

        if (!$ready) {
            // Clear stale agreed grade calculations. This handles clearing grades when an optional marker is enabled.
            if ($clearstale && isset($this->grade->grade) && $this->grade->grade != ASSIGN_GRADE_NOT_SET) {
                return $this->assignment->clear_agreed_grade($this->grade);
            }
            return false;
        }

        $method = $this->get_calculation_method();
        if (!isset($method) || !$method->is_automatic()) {
            return false;
        }

        $method->marks = $method->get_marks();
        $value = $method->calculate_grade();
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($this instanceof agreement_method) {
                $this->before_resolution_saved($value);
            }
            $markerids = $method->get_considered_markers();
            $updated = $this->assignment->update_agreed_grade(
                $this->grade,
                $value,
                $markerids,
                $method->get_basename(),
            );
            if ($updated && $this instanceof agreement_method) {
                $this->after_resolution_saved($value);
            }
            $transaction->allow_commit();
            return $updated;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
            return false;
        }
    }
}
