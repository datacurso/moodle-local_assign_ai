/* eslint-disable */
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
 * Polls backend for progress of AI reviews and updates UI accordingly.
 *
 * @module     local_assign_ai/review_progress
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import { get_string as getString } from 'core/str';

const POLL_MS_DEFAULT = 8000;
let intervalid = 0;

// Client-side smoothing state per row. We keep randomized parameters per processing session
// so that progress does not look identical across rows or sessions.
const startTimes = new Map(); // pendingid -> timestamp ms
const expectedDurations = new Map(); // pendingid -> ms
const startOffsets = new Map(); // pendingid -> int percent (1..8)
const curveFactors = new Map(); // pendingid -> float (0.6..1.4)
const lastShown = new Map(); // pendingid -> last integer percent shown

function setLinkDisabled(link, disabled) {
    if (!link) {
        return;
    }
    if (disabled) {
        link.classList.add('disabled');
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('tabindex', '-1');
    } else {
        link.classList.remove('disabled');
        link.removeAttribute('aria-disabled');
        link.removeAttribute('tabindex');
    }
}

/**
 * Check if there are rows that should be polled.
 * @returns {boolean}
 */
function hasActiveRows() {
    return !!document.querySelector('tr[data-status="processing"], tr[data-status="queued"], tr.js-row-inprogress, tr.js-row-queued');
}

function hasPendingRows() {
    return !!document.querySelector('tr[data-status="pending"], tr.js-row-pending');
}

/**
 * Apply a grade value (if present) to the grade cell in the given row.
 *
 * @param {HTMLElement} row
 * @param {number|null|undefined} grade
 */
function applyGradeToRow(row, grade) {
    if (!row) {
        return;
    }

    const gradeCell = row.querySelector('.js-grade-cell');
    if (!gradeCell) {
        return;
    }

    if (typeof grade !== 'undefined' && grade !== null) {
        const rounded = Math.round(grade);
        gradeCell.textContent = String(rounded);
    } else {
        gradeCell.textContent = '-';
    }
}

/**
 * Disable/enable the header buttons depending on current progress state.
 */
function reflectHeaderButtonsState() {
    const anyActive = hasActiveRows();
    const anyPending = hasPendingRows();
    const btnReviewAll = document.querySelector('.js-review-all');
    const btnApproveAll = document.querySelector('.js-approve-all');

    if (btnReviewAll) {
        btnReviewAll.disabled = anyActive || btnReviewAll.disabled;
    }
    if (btnApproveAll) {
        const enableApprove = anyPending && !anyActive;
        if (enableApprove) {
            btnApproveAll.removeAttribute('disabled');
        } else {
            btnApproveAll.setAttribute('disabled', 'disabled');
        }
    }
}

/**
 * Update a single row UI using progress info.
 * @param {HTMLElement} row
 * @param {number} progress
 * @param {string} status
 */
function updateRow(row, progress, status, grade) {
    const badge = row.querySelector('.js-state-badge');
    const hint = row.querySelector('.js-state-hint');
    let indicator = row.querySelector('.js-progress-indicator');
    const btnReview = row.querySelector('.js-btn-review');
    const btnDetails = row.querySelector('.js-btn-details');
    const btnGrade = row.querySelector('.js-btn-grade');

    if (status === 'processing' && progress > 0 && progress < 100) {
        row.classList.add('js-row-inprogress');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'small text-warning js-progress-indicator';
            indicator.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';
            row.querySelector('td:nth-child(7)')?.appendChild(indicator);
        }
        getString('processing', 'local_assign_ai').then(txt => {
            indicator.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + txt + ' (' + progress + '%)';
        }).catch(() => { });

        // Disable row action buttons while in progress.
        row.querySelectorAll('button').forEach(b => b.setAttribute('disabled', 'disabled'));
        setLinkDisabled(btnGrade, true);
    } else {
        row.classList.remove('js-row-inprogress');
        if (indicator) {
            indicator.remove();
        }
        // Re-enable row buttons depending on status.
        if (status === 'initial') {
            row.querySelectorAll('.js-review-ai').forEach(b => b.removeAttribute('disabled'));
            setLinkDisabled(btnGrade, false);
        } else if (status === 'pending') {
            row.querySelectorAll('.view-details').forEach(b => b.removeAttribute('disabled'));
            setLinkDisabled(btnGrade, false);
        } else if (status === 'queued') {
            // Keep disabled while waiting for processing to start.
            row.querySelectorAll('button').forEach(b => b.setAttribute('disabled', 'disabled'));
            setLinkDisabled(btnGrade, true);
        } else if (status === 'rejected') {
            row.querySelectorAll('button').forEach(b => b.removeAttribute('disabled'));
            setLinkDisabled(btnGrade, false);
        } else {
            row.querySelectorAll('button').forEach(b => b.removeAttribute('disabled'));
            setLinkDisabled(btnGrade, false);
        }
    }

    if (badge && hint) {
        // Update badge class and short text per status, and the longer hint below.
        if (status === 'initial') {
            badge.className = 'badge bg-secondary js-state-badge';
            getString('aistatus_initial_short', 'local_assign_ai').then(t => { badge.textContent = t; }).catch(() => { });
            getString('aistatus_initial_help', 'local_assign_ai').then(t => { hint.textContent = t; }).catch(() => { });
        } else if (status === 'queued') {
            badge.className = 'badge bg-warning js-state-badge';
            getString('aistatus_queued_short', 'local_assign_ai').then(t => { badge.textContent = t; }).catch(() => { });
            getString('aistatus_queued_help', 'local_assign_ai').then(t => { hint.textContent = t; }).catch(() => { });
        } else if (status === 'processing') {
            badge.className = 'badge bg-warning js-state-badge';
            getString('processing', 'local_assign_ai').then(t => { badge.textContent = t; }).catch(() => { });
            getString('aistatus_processing_help', 'local_assign_ai').then(t => { hint.textContent = t; }).catch(() => { });
        } else if (status === 'pending') {
            badge.className = 'badge bg-info js-state-badge';
            getString('aistatus_pending_short', 'local_assign_ai').then(t => { badge.textContent = t; }).catch(() => { });
            getString('aistatus_pending_help', 'local_assign_ai').then(t => { hint.textContent = t; }).catch(() => { });
        } else if (status === 'rejected') {
            badge.className = 'badge bg-danger js-state-badge';
            getString('statusrejected', 'local_assign_ai').then(t => { badge.textContent = t; }).catch(() => { });
            getString('statusrejected', 'local_assign_ai').then(t => { hint.textContent = t; }).catch(() => { });
        }
    }

    // Toggle visibility of action buttons based on status.
    if (btnReview && btnDetails) {
        if (status === 'pending' || progress >= 100) {
            btnReview.classList.add('d-none');
            btnDetails.classList.remove('d-none');
        } else if (status === 'initial' || status === 'queued' || status === 'processing') {
            btnDetails.classList.add('d-none');
            btnReview.classList.remove('d-none');
        }
    }

    // When a row finishes processing and becomes pending, set its grade (if provided) without reloading the page.
    if (status === 'pending') {
        applyGradeToRow(row, grade);
    }
}

/**
 * Find pending ids in the table that need updates.
 * @returns {number[]}
 */
function collectPendingIds() {
    const markers = document.querySelectorAll('.js-pending-marker[data-pendingid]');
    const ids = [];
    markers.forEach(marker => {
        const pid = parseInt(marker.getAttribute('data-pendingid'), 10);
        if (!pid) {
            return;
        }
        const row = marker.closest('tr');
        if (!row) {
            return;
        }
        const status = (row.getAttribute('data-status') || '').toLowerCase();
        const isProcessing = status === 'processing';
        const isQueued = status === 'queued';
        const fallbackProcessing = !status && row.classList.contains('js-row-inprogress');
        const fallbackQueued = !status && row.classList.contains('js-row-queued');
        if (isProcessing || isQueued || fallbackProcessing || fallbackQueued) {
            ids.push(pid);
        }
    });
    return ids;
}

/**
 * Apply returned progress values to DOM.
 * @param {Array} entries
 */
function applyProgress(entries) {
    const now = Date.now();
    entries.forEach(entry => {
        const marker = document.querySelector('.js-pending-marker[data-pendingid="' + entry.id + '"]');
        const row = marker ? marker.closest('tr') : null;
        if (!row) {
            return;
        }
        // Initialize smoothing when processing begins and it's not tracked.
        if (entry.status === 'processing') {
            if (!startTimes.has(entry.id)) {
                startTimes.set(entry.id, now);
                // Randomize expected duration 20-55s.
                const expected = 20000 + Math.floor(Math.random() * 35001);
                expectedDurations.set(entry.id, expected);
                // Randomize starting offset 1-8% to avoid uniform starts.
                startOffsets.set(entry.id, 1 + Math.floor(Math.random() * 8));
                // Randomize easing curve factor for diversity (ease-out exponent).
                curveFactors.set(entry.id, 0.6 + Math.random() * 0.8); // 0.6..1.4
                // Initialize last shown.
                lastShown.set(entry.id, 0);
            }
        }

        // Compute adjusted client-side progress.
        let adjusted = 0;
        if (entry.status === 'processing' && startTimes.has(entry.id)) {
            const startedAt = startTimes.get(entry.id);
            const expected = expectedDurations.get(entry.id) || 35000;
            const elapsed = Math.max(0, now - startedAt);
            const t = Math.min(0.999, Math.max(0, elapsed / expected));
            const start = startOffsets.get(entry.id) || 1;
            const curve = curveFactors.get(entry.id) || 1.0;
            // Ease-out with variable exponent to diversify curves.
            const eased = 1 - Math.pow(1 - t, curve);
            let estimated = start + Math.floor(eased * (99 - start));
            // Add small, bounded per-tick noise to avoid perfectly smooth growth.
            const noise = Math.floor((Math.random() * 5) - 2); // -2..+2
            estimated = Math.max(start, Math.min(99, estimated + noise));
            // Ensure monotonic increase.
            const last = lastShown.get(entry.id) || 0;
            adjusted = Math.max(last, estimated);
            lastShown.set(entry.id, adjusted);
        }

        // If status is no longer processing, clear smoothing state.
        if (entry.status !== 'processing') {
            startTimes.delete(entry.id);
            expectedDurations.delete(entry.id);
            startOffsets.delete(entry.id);
            curveFactors.delete(entry.id);
            lastShown.delete(entry.id);
        }

        row.setAttribute('data-status', entry.status);
        if (entry.status === 'queued') {
            row.classList.add('js-row-queued');
        } else {
            row.classList.remove('js-row-queued');
        }
        row.setAttribute('data-progress', String(adjusted));
        updateRow(row, adjusted, entry.status, entry.grade);
    });

    reflectHeaderButtonsState();
}

function stopPolling() {
    if (intervalid) {
        clearInterval(intervalid);
        intervalid = 0;
    }
}

/**
 * Poll backend for progress.
 * @returns {boolean}
 */
function poll() {
    const ids = collectPendingIds();
    if (ids.length === 0) {
        stopPolling();
        reflectHeaderButtonsState();
        return false;
    }

    Ajax.call([{
        methodname: 'local_assign_ai_get_progress',
        args: { pendingids: ids },
    }])[0].done(result => {
        applyProgress(result);
    }).fail(err => {
        Notification.exception(err);
    });

    return true;
}

function startPolling() {
    if (intervalid) {
        return;
    }
    if (!poll()) {
        return;
    }
    intervalid = setInterval(poll, POLL_MS_DEFAULT);
}

/**
 * Init module.
 * @param {number} cmid
 */
export function init(cmid) {
    // Initial reflect and start polling.
    reflectHeaderButtonsState();
    const initialDelay = document.body.classList.contains('assign-ai-progress-running') ? 0 : POLL_MS_DEFAULT;

    document.addEventListener('click', (e) => {
        const target = e.target.closest('a.js-btn-grade');
        if (target && (target.classList.contains('disabled') || target.getAttribute('aria-disabled') === 'true')) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    if (hasActiveRows()) {
        setTimeout(() => {
            startPolling();
        }, initialDelay);
    }
}

/**
 * Public helper so other modules (e.g. review_with_ai) can force the poller to start.
 */
export function ensurePolling() {
    startPolling();
}
