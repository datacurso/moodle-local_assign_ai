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
 * Fetches AI data and injects it into grading forms.
 *
 * @module      local_assign_ai/inject_ai/init
 * @copyright   2025 Datacurso
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import { get_string as getString } from 'core/str';
import { injectMessage } from './inject_message';
import { injectRubric } from './inject_rubric';
import { injectGuide } from './inject_guide';
import { injectSimpleGrade } from './inject_simple_grade';
import { normalizeString } from './normalize_string';

let activerunid = 0;
let activecleanup = null;

const parseInjectionData = (data) => {
    const message = data.message ?? data.reply ?? '';
    const rubricResponse = data.rubric_response ?? data.rubric ?? null;
    const guideResponse = data.assessment_guide_response ?? null;
    const grade = data.grade ?? null;
    const status = data.status ?? 'none';

    if (guideResponse && guideResponse !== 'null' && guideResponse !== '') {
        return {
            status,
            message,
            mode: 'guide',
            payload: typeof guideResponse === 'string' ? JSON.parse(guideResponse) : guideResponse,
            grade,
        };
    }

    if (rubricResponse && rubricResponse !== 'null' && rubricResponse !== '') {
        const parsed = typeof rubricResponse === 'string' ? JSON.parse(rubricResponse) : rubricResponse;
        const isGuideLike = !Array.isArray(parsed) && typeof parsed === 'object';
        return {
            status,
            message,
            mode: isGuideLike ? 'guide' : 'rubric',
            payload: parsed,
            grade,
        };
    }

    if (grade !== null && grade !== undefined) {
        return {
            status,
            message,
            mode: 'simple',
            payload: null,
            grade,
        };
    }

    return {
        status,
        message,
        mode: 'none',
        payload: null,
        grade,
    };
};

/**
 * Verifies that rubric levels selected in DOM match AI expected points.
 *
 * @param {Array} rubricData AI rubric payload.
 * @param {Element|Document} root Grading root element.
 * @returns {{ok: boolean, mismatches: Array}}
 */
const verifyRubricApplied = (rubricData, root) => {
    if (!Array.isArray(rubricData)) {
        return { ok: false, mismatches: [{ reason: 'rubric_data_not_array' }] };
    }

    const rows = Array.from(root.querySelectorAll('tr.criterion'));
    const mismatches = [];

    // Per-record mode detection, mirroring inject_rubric: any criterion with a Moodle
    // id makes the whole record 'strict' (exact radio verification only, no fallback).
    const strictMode = rubricData.some((criterionData) =>
        criterionData && criterionData.id !== null && criterionData.id !== undefined && criterionData.id !== '');

    rubricData.forEach((criterionData) => {
        const name = criterionData?.criterion || '';
        const expected = parseFloat(criterionData?.levels?.[0]?.points ?? '');
        const criterionId = criterionData?.id ?? null;
        const levelId = criterionData?.levels?.[0]?.id ?? null;

        if (strictMode) {
            // Strict: only the exact expected radio being checked counts as applied.
            if (criterionId === null || criterionId === '' || levelId === null || levelId === '') {
                mismatches.push({ criterion: name, reason: 'missing_ids', expected });
                return;
            }
            const nameSelector = 'input[name$="[criteria][' + criterionId + '][levelid]"]';
            const radio = root.querySelector(nameSelector + '[value="' + levelId + '"]');
            if (!radio) {
                mismatches.push({ criterion: name, reason: 'radio_not_found', expected });
                return;
            }
            const cell = radio.closest('td.level');
            const isSelected = !!radio.checked || (cell && (cell.classList.contains('checked') ||
                cell.getAttribute('aria-checked') === 'true'));
            if (!isSelected) {
                mismatches.push({ criterion: name, reason: 'no_level_selected', expected });
            }
            return;
        }

        // Legacy record (no ids anywhere): locate the row by its normalized
        // description text and compare the selected points below.
        const row = rows.find((rowItem) => {
            const cell = rowItem.querySelector('td.description');
            if (!cell) {
                return false;
            }
            return normalizeString(cell.textContent.trim()) === normalizeString(name.trim());
        });

        if (!row) {
            mismatches.push({ criterion: name, reason: 'row_not_found', expected });
            return;
        }

        let selected = null;
        const levels = Array.from(row.querySelectorAll('td.level'));
        levels.forEach((levelCell) => {
            const radio = levelCell.querySelector('input[type="radio"]');
            const isSelected = !!radio?.checked || levelCell.classList.contains('checked') ||
                levelCell.getAttribute('aria-checked') === 'true';
            if (!isSelected) {
                return;
            }
            const score = levelCell.querySelector('.scorevalue');
            if (!score) {
                return;
            }
            selected = parseFloat(score.textContent.trim());
        });

        if (selected === null) {
            mismatches.push({ criterion: name, reason: 'no_level_selected', expected });
            return;
        }

        if (Math.abs(selected - expected) >= 0.1) {
            mismatches.push({ criterion: name, reason: 'points_mismatch', expected, selected });
        }
    });

    return { ok: mismatches.length === 0, mismatches };
};

const resolveGradingRoot = () => {
    const selectors = [
        '#fitem_id_advancedgrading .gradingform_rubric',
        '#fitem_id_advancedgrading .gradingform_guide',
        '.gradingform_rubric.evaluate.editable',
        '.gradingform_guide.evaluate.editable',
        '.gradingform_rubric',
        '.gradingform_guide',
    ];

    for (const selector of selectors) {
        const nodes = Array.from(document.querySelectorAll(selector));
        const visible = nodes.find((node) => node.offsetParent !== null || node.getClientRects().length > 0);
        if (visible) {
            return { root: visible, selector, visible: true, candidates: nodes.length };
        }
        if (nodes.length) {
            return { root: nodes[0], selector, visible: false, candidates: nodes.length };
        }
    }

    return { root: document, selector: 'document', visible: false, candidates: 0 };
};

/**
 * Injects AI-generated feedback, rubric selections and/or grade.
 *
 * @param {Object} params Required parameters.
 * @param {number} params.userid Current graded user id.
 * @param {number} params.assignmentid Assignment cmid.
 * @param {number} params.courseid Course id.
 * @returns {Promise<void>}
 */
export const init = async ({ userid, assignmentid, courseid }) => {
    if (!userid || !assignmentid || !courseid) {
        return;
    }

    if (activecleanup) {
        activecleanup();
    }

    const runid = ++activerunid;
    let intervalid = 0;
    let observer = null;
    let finished = false;
    let successNotified = false;
    let stableSuccessCount = 0;
    let attempts = 0;

    const isCurrentRun = () => runid === activerunid;
    const cleanup = () => {
        if (finished) {
            return;
        }
        finished = true;
        if (intervalid) {
            clearInterval(intervalid);
            intervalid = 0;
        }
        if (observer) {
            observer.disconnect();
            observer = null;
        }
    };

    activecleanup = cleanup;

    const [strRubricArray, strRubricSuccess] = await Promise.all([
        getString('rubricmustarray', 'local_assign_ai'),
        getString('rubricsuccess', 'local_assign_ai'),
    ]);

    if (!isCurrentRun()) {
        return;
    }

    const fetchDetails = async () => Ajax.call([{
        methodname: 'local_assign_ai_get_details',
        args: { courseid, cmid: assignmentid, userid }
    }])[0];

    let cachedData = null;
    let fetchcounter = 0;

    const tryInjection = () => {
        if (!isCurrentRun() || finished || !cachedData) {
            return;
        }

        let parsed;
        try {
            parsed = parseInjectionData(cachedData);
        } catch (e) {
            cleanup();
            return;
        }

        if (parsed.status === 'approve') {
            cleanup();
            return;
        }

        const rootInfo = resolveGradingRoot();
        const gradingRoot = rootInfo.root;
        const hasRubricRows = gradingRoot.querySelectorAll('tr.criterion').length > 0;

        if (parsed.mode === 'rubric' && !rootInfo.visible) {
            return;
        }

        if (parsed.mode === 'rubric' && !hasRubricRows) {
            return;
        }

        injectMessage(parsed.message);

        let applied = false;
        if (parsed.mode === 'guide') {
            applied = injectGuide(parsed.payload, { root: gradingRoot });
        } else if (parsed.mode === 'rubric') {
            const result = injectRubric(parsed.payload, strRubricArray, {
                detailed: true,
                root: gradingRoot,
            });

            applied = !!result.injected;

            const verification = verifyRubricApplied(parsed.payload, gradingRoot);
            if (!verification.ok) {
                applied = false;
            }
        } else if (parsed.mode === 'simple') {
            applied = injectSimpleGrade(parsed.grade);
        }

        if (!applied) {
            stableSuccessCount = 0;
            return;
        }

        stableSuccessCount++;

        if (!successNotified && stableSuccessCount >= 2) {
            Notification.addNotification({
                message: strRubricSuccess,
                type: 'success'
            });
            successNotified = true;
            setTimeout(() => {
                if (isCurrentRun()) {
                    cleanup();
                }
            }, 500);
        }
    };

    const refreshAndInject = async () => {
        if (!isCurrentRun() || finished) {
            return;
        }

        fetchcounter++;
        if (!cachedData || fetchcounter % 4 === 0) {
            try {
                cachedData = await fetchDetails();
                if (!isCurrentRun() || finished) {
                    return;
                }
            } catch (e) {
                Notification.exception(e);
                cleanup();
                return;
            }
        }

        tryInjection();
    };

    await refreshAndInject();
    if (!isCurrentRun() || finished) {
        return;
    }

    const maxAttempts = 320;
    intervalid = setInterval(() => {
        attempts++;
        if (attempts > maxAttempts) {
            cleanup();
            return;
        }

        void refreshAndInject();
    }, 250);

    const container = document.querySelector('#fitem_id_advancedgrading') ||
        document.querySelector('[data-region="grading-actions-form"]') ||
        document.body;

    observer = new MutationObserver(() => {
        if (!isCurrentRun() || finished) {
            return;
        }
        tryInjection();
    });

    observer.observe(container, { childList: true, subtree: true });

    setTimeout(() => {
        if (isCurrentRun()) {
            cleanup();
        }
    }, 90000);
};
