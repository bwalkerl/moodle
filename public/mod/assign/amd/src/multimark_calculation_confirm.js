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

import ModalEvents from 'core/modal_events';
import {getString} from 'core/str';
import SaveCancelModal from 'core/modal_save_cancel';

/**
 * Event handler to add a confirmation when changing multi mark settings that can recalculate grades.
 *
 * @module     mod_assign/multimark_calculation_confirm
 * @copyright  2026 Catalyst IT Australia Pty Ltd
 * @author     Benjamin Walker <benjaminwalker@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const Selectors = {
    form: 'form[action*="modedit.php"]',
    fields: {
        advancedGradingMethod: '#id_advancedgradingmethod_submissions',
        markerCount: '#id_markercount',
        markingAllocation: '#id_markingallocation',
        markingWorkflow: '#id_markingworkflow',
        multiMarkMethod: '#id_multimarkmethod',
        multiMarkRounding: '#id_multimarkrounding',
    },
};

/**
 * Add a confirmation when changing multi mark settings that can recalculate grades.
 * This should only be called when grades exist.
 */
export const init = () => {

    const form = document.querySelector(Selectors.form);

    // Store the initial state to detect changes.
    const initial = {};
    Object.keys(Selectors.fields).forEach((key) => {
        initial[key] = form.querySelector(Selectors.fields[key])?.value ?? null;
    });

    let confirmed = false;

    form.addEventListener('submit', async e => {
        // Let confirmed submits through.
        if (confirmed) {
            return;
        }

        const current = {};
        Object.keys(Selectors.fields).forEach((key) => {
            current[key] = form.querySelector(Selectors.fields[key])?.value ?? null;
        });

        // Only recalculate grades when multi marking is enabled (and not when first enabled).
        const isMultiMarkingEnabled = (state) =>
            !state.advancedGradingMethod &&
            state.markingWorkflow &&
            state.markingAllocation &&
            state.markerCount > 1;

        if (!isMultiMarkingEnabled(current) || !isMultiMarkingEnabled(initial)) {
            return;
        }

        // Only need to recalculate grades when calculation method changes.
        const methodChanged = current.multiMarkMethod !== initial.multiMarkMethod;
        const roundingChanged = current.multiMarkRounding !== initial.multiMarkRounding;
        if (!methodChanged && !roundingChanged) {
            return;
        }

        // Some grades will be recalculated, so display a confirmation to warn the user.
        e.preventDefault();
        const submitter = e.submitter;
        const modal = await SaveCancelModal.create({
            title: await getString('confirm', 'moodle'),
            body: await getString('changemultimarkingmethodconfirm', 'mod_assign'),
            buttons: {
                save: await getString('continue', 'moodle'),
            },
            show: true,
            removeOnClose: true,
        });

        // Handle save event.
        modal.getRoot().on(ModalEvents.save, (e) => {
            e.preventDefault();
            confirmed = true;
            form.requestSubmit(submitter);
            confirmed = false;
        });
    });
};
