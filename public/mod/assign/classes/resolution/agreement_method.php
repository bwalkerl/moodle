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
 * Coordinates grade agreement by applying rules or delegating to a calculation.
 *
 * Agreement methods cannot be used for calculation overrides for individual grades.
 *
 * @package    mod_assign
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class agreement_method extends method {
    /** @var calculation_method|null Calculation selected by this agreement method. */
    protected ?calculation_method $calculationmethod = null;

    /** @var string|null Rule selected by this agreement method */
    protected ?string $rule = null;

    /**
     * {@inheritdoc}
     */
    public function get_calculation_method(): ?calculation_method {
        if (isset($this->calculationmethod)) {
            return $this->calculationmethod;
        }

        $rule = $this->get_selected_rule();
        if (is_subclass_of($rule, calculation_method::class)) {
            $this->calculationmethod = new $rule($this->assignment, $this->grade);
            return $this->calculationmethod;
        }

        return null;
    }

    /**
     * Returns the selected rule.
     *
     * @return string
     */
    public function get_selected_rule(): string {
        if (!isset($this->rule)) {
            $this->rule = $this->select_agreement_rule();
        }
        return $this->rule;
    }

    /**
     * Applies a set to rules to determine how a grade should be calculated.
     *
     * @return string The selected rule. This can be a calculation method or custom name.
     */
    protected function select_agreement_rule(): string {
        return '';
    }

    /**
     * Applies custom acttions for the selected rule, such as enabling optional markers.
     *
     * @return bool Whether resolution requirements have changed.
     */
    protected function apply_actions(): bool {
        return false;
    }

    /**
     * Called before a resolved grade is saved.
     *
     * @param float|null $value Resolved grade.
     */
    protected function before_resolution_saved(?float $value): void {
    }

    /**
     * Called after a resolved grade is saved.
     *
     * @param float|null $value Resolved grade.
     */
    protected function after_resolution_saved(?float $value): void {
    }
}
