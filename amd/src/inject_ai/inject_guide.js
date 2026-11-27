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

/**
 * Injects assessment guide grades and comments.
 *
 * @module      local_assign_ai/inject_ai/inject_guide
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { normalizeString } from './normalize_string';

/**
 * Selects the guide rows an AI response key refers to.
 *
 * The AI service keys each criterion by its Moodle criterion id, which the guide form carries in its
 * field names ("...[criteria][12][score]"). Responses stored before that keyed it by shortname: those
 * match the criterion shortname exactly, and only as a last resort any row whose text contains it.
 *
 * @param {HTMLElement[]} rows Candidate table rows.
 * @param {string} key Criterion key in the AI response.
 * @returns {HTMLElement[]} Matching rows.
 */
const findCriterionRows = (rows, key) => {
    const trimmed = String(key).trim();
    const isid = /^\d+$/.test(trimmed);
    if (isid) {
        const byid = rows.filter(row => row.querySelector(
            `[name$="[criteria][${trimmed}][score]"], [name$="[criteria][${trimmed}][remark]"]`
        ));
        if (byid.length) {
            return byid;
        }
    }

    const wanted = normalizeString(trimmed);
    const byshortname = rows.filter(row => {
        const shortname = row.querySelector('.criterionshortname');
        return shortname && normalizeString(shortname.textContent) === wanted;
    });
    if (byshortname.length || isid) {
        // A bare number is contained in almost any row text, so it never falls back to a text search.
        return byshortname;
    }

    return rows.filter(row => normalizeString(row.textContent).includes(wanted));
};

/**
 * Injects assessment guide grades and comments.
 *
 * @param {Object} guideData The guide data object.
 * @param {{root?: Element|Document}} options Injection options.
 * @returns {boolean} True if guide was injected successfully.
 */
export const injectGuide = (guideData, options = {}) => {
    const root = options.root || document;
    const targetUserid = options.targetUserid ? parseInt(options.targetUserid, 10) : 0;
    let injected = false;

    Object.keys(guideData).forEach(criterionName => {
        const data = guideData[criterionName];
        if (!data) {
            return;
        }

        const grade = data.grade;
        let comments = data.reply;
        if (Array.isArray(comments)) {
            comments = comments.join(', ');
        }

        const allrows = Array.from(root.querySelectorAll('tr')).filter((row) => {
            if (!targetUserid) {
                return true;
            }
            const form = row.closest('form');
            if (!form) {
                return true;
            }
            const userinput = form.querySelector('input[name="userid"]');
            if (!userinput) {
                return true;
            }
            return parseInt(userinput.value, 10) === targetUserid;
        }); // Broad search for criteria rows
        const visiblerows = allrows.filter(row => row.offsetParent !== null || row.getClientRects().length > 0);
        const rows = findCriterionRows(visiblerows.length ? visiblerows : allrows, criterionName);
        rows.forEach(row => {

            const scoreInput = row.querySelector('.score input[type="text"]');
            const remarkTextarea = row.querySelector('.remark textarea');
            let rowUpdated = false;

            if (scoreInput && grade !== undefined && grade !== null) {
                if (scoreInput.value != grade) {
                    scoreInput.value = grade;
                    scoreInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                rowUpdated = true;
            }

            if (remarkTextarea && comments) {
                if (remarkTextarea.value !== comments) {
                    remarkTextarea.value = comments;
                    remarkTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                    remarkTextarea.dispatchEvent(new Event('change', { bubbles: true }));
                }
                rowUpdated = true;
            }

            if (rowUpdated) {
                injected = true;
            }
        });
    });

    return injected;
};
