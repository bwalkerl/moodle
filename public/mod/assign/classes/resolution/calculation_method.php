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
 * A deterministic calculation of an agreed grade from the allocated marks.
 *
 * Calculations must not change allocations, workflow state, or stored grades.
 * calculate_grade() is side-effect-free. The final resolve_grade() workflow
 * handles persistence, metadata and stale-grade clearing.
 *
 * @package    mod_assign
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class calculation_method extends method {
    /**
     * Initialise a calculation with its context.
     * Subclasses supporting multiple instances should validate arguments individually.
     *
     * @param \assign $assignment Assignment being resolved.
     * @param \stdClass $grade Grade record for this attempt.
     * @param array $arguments Arguments for calculation methods that support multiple instances.
     */
    public function __construct(\assign $assignment, \stdClass $grade, array $arguments = []) {
        parent::__construct($assignment, $grade);
    }

    /**
     * {@inheritdoc}
     */
    final public function get_calculation_method(): calculation_method {
        return $this;
    }

    /**
     * Calculates an agreed grade value and applies rounding.
     *
     * @return float|null Calculated value.
     */
    final public function calculate_grade(): ?float {
        $value = $this->calculate();
        if (!isset($value) || !$this->supports_rounding()) {
            return $value;
        }

        // Apply rounding.
        $roundingmethod = (int) ($this->assignment->get_instance()->multimarkrounding ?? '');
        return rounding::tryFrom($roundingmethod)?->apply($value) ?? $value;
    }

    /**
     * Calculates an agreed grade using marks.
     *
     * @return float|null Unrounded grade, or null when unavailable.
     */
    abstract protected function calculate(): ?float;

    /**
     * Whether assignment rounding is supported by this calculation.
     *
     * @return bool
     */
    public static function supports_rounding(): bool {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    final public static function display_rounding_options(): bool {
        return static::supports_rounding() || parent::display_rounding_options();
    }

    /**
     * Get marker IDs considered during this calculation.
     *
     * @return int[]
     */
    public function get_considered_markers(): array {
        return array_keys($this->get_marks());
    }

    /**
     * Get the marker IDs of marks that were used in the final calculation.
     * When a calculation method selects a single mark, only that marker is returned.
     *
     * @return int[]
     */
    public function get_contributing_markers(): array {
        return $this->get_considered_markers();
    }
}
