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

import * as formSubmit from 'core_form/submit';
import ModalEvents from 'core/modal_events';
import {getString} from 'core/str';
import SaveCancelModal from 'core/modal_save_cancel';

/**
 * Module for the quick grading functionality on the submissions page.
 *
 * @module     mod_assign/quick_grading
 * @copyright  2024 Mihail Geshoski <mihail@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @constant {Object} The object containing the relevant selectors. */
const Selectors = {
    quickGradingSaveRegion: '[data-region="quick-grading-save"]',
    quickGradingTable: 'table#submissions',
    markerEnabledCheckbox: 'input[type="checkbox"][name*="allocatedmarkerenabled"]',
    notifyStudentsCheckbox: 'input[type="checkbox"][name="sendstudentnotifications"]',
    notifyStudentsHidden: 'input[type="hidden"][name="sendstudentnotifications"]',
    saveButton: 'button[type="submit"]',
};

/**
 * Initialize module.
 */
export const init = () => {
    const quickGradingSaveRegion = document.querySelector(Selectors.quickGradingSaveRegion);
    if (quickGradingSaveRegion) {
        const quickGradingSaveButton = quickGradingSaveRegion.querySelector(Selectors.saveButton);
        // Initialize the submit button.
        formSubmit.init(quickGradingSaveButton);
        // Add 'change' event listener to the quick grading save region.
        quickGradingSaveRegion.addEventListener('change', e => {
            const notifyStudentsCheckbox = e.target.closest(Selectors.notifyStudentsCheckbox);
            // The target is the 'Notify student' checkbox.
            if (notifyStudentsCheckbox) {
                // The 'Notify student' option uses a hidden input and a checkbox. The hidden input is used to submit '0'
                // as a workaround when the checkbox is unchecked since unchecked checkboxes are not submitted with the
                // form. Therefore, we need to enable or disable the hidden input based on the checkbox state.
                const notifyStudentsHidden = notifyStudentsCheckbox.parentNode.querySelector(Selectors.notifyStudentsHidden);
                notifyStudentsHidden.disabled = notifyStudentsCheckbox.checked;
            }
        });
    }

    const quickGradingTable = document.querySelector(Selectors.quickGradingTable);
    if (quickGradingTable) {
        quickGradingTable.addEventListener('change', async e => {
            const markerCheckbox = e.target;
            if (!markerCheckbox.matches(Selectors.markerEnabledCheckbox)) {
                return;
            }

            const select = markerCheckbox.closest('td').querySelector('select');
            if (select) {
                if (!markerCheckbox.checked) {
                    select.disabled = true;
                    return;
                }

                const row = select.closest('tr');
                const gradeInput = row.querySelector('input.quickgrade[id^="quickgrade_"]');
                const hasGrade = gradeInput && gradeInput.value.trim() !== '';
                if (!hasGrade) {
                    select.disabled = false;
                    return;
                }

                // Revert the checkbox change until it is confirmed.
                markerCheckbox.checked = false;

                // Add a notification that enabling the marker will clear the current grade.
                const modal = await SaveCancelModal.create({
                    title: await getString('confirm', 'moodle'),
                    body: await getString('enableoptionalmarkeraftergraded', 'mod_assign'),
                    buttons: {
                        save: await getString('enable', 'moodle'),
                    },
                    show: true,
                    removeOnClose: true,
                });

                // Handle save event.
                modal.getRoot().on(ModalEvents.save, () => {
                    markerCheckbox.checked = true;
                    select.disabled = false;
                });
            }
        });
    }
};
