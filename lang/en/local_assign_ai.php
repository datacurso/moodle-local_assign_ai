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

/**
 * Plugin strings are defined here.
 *
 * @package     local_assign_ai
 * @category    string
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['ai_response_language'] = 'AI response language';
$string['ai_response_language_help'] = 'Select the language in which the AI will respond when reviewing this assignment.';
$string['aiconfigheader'] = 'Datacurso Assign AI';
$string['aiprompt'] = 'Give instructions to the AI';
$string['aiprompt_help'] = 'Additional instructions sent to the AI as part of the "prompt" field.';
$string['aistatus'] = 'AI Status';
$string['aistatus_initial_help'] = 'Send the submission to AI to generate a proposal.';
$string['aistatus_initial_short'] = 'Pending AI review';
$string['aistatus_pending_help'] = 'AI proposal ready. Open the details to edit or approve it.';
$string['aistatus_pending_short'] = 'Pending approval';
$string['aistatus_processing_help'] = 'AI is currently processing this submission. This may take a while.';
$string['aistatus_queued_help'] = 'This submission has been queued and will start processing soon.';
$string['aistatus_queued_short'] = 'queued';
$string['aitaskdone'] = 'AI processing complete. Total submissions processed: {$a}';
$string['aitaskstart'] = 'Processing AI submissions for course: {$a}';
$string['aitaskuserqueued'] = 'Submission queued for user ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Datacurso logo';
$string['approveall'] = 'Approve all';
$string['assign_ai:changestatus'] = 'Change AI approval status';
$string['assign_ai:review'] = 'Review AI suggestions for assignments';
$string['assign_ai:viewdetails'] = 'View AI feedback details';
$string['attemptnumber'] = 'Attempt';
$string['autograde'] = 'Auto-approve AI feedback';
$string['autograde_help'] = 'When enabled, AI-generated grades and comments are applied automatically to student submissions without requiring manual approval.';
$string['autogradegrader'] = 'Recorded grader for auto approvals';
$string['autogradegrader_help'] = 'Select the user who will be recorded as the grader whenever AI feedback is auto approved. Only users who can grade assignments in this course are listed.';
$string['backtocourse'] = 'Back to course';
$string['backtoreview'] = 'Back to AI review';
$string['confirm_approve_all'] = 'Approve every AI proposal currently pending and apply its grades/comments to students. Do you want to continue?';
$string['confirm_cancel_review'] = 'Cancel this AI review? It will return to the pending state so you can retry it.';
$string['confirm_review_all'] = 'Send every submission marked "Pending AI review" to the AI and start processing. This may take a few minutes. Do you want to continue?';
$string['current'] = 'Current';
$string['defaultautograde'] = 'Auto-approve AI feedback by default';
$string['defaultautograde_desc'] = 'Defines the default value for new assignments.';
$string['defaultdelayminutes'] = 'Delay time by default (minutes)';
$string['defaultdelayminutes_desc'] = 'Default wait time used when delayed review is enabled.';
$string['defaultenableai'] = 'Enable AI';
$string['defaultenableai_desc'] = 'Controls global AI availability for assignments. If disabled, AI is turned off for all existing assignments and cannot be enabled per assignment until it is enabled again globally.';
$string['defaultprompt'] = 'Give instructions to the AI by default';
$string['defaultprompt_desc'] = 'This text is used as the default and sent in the "prompt" field. It can be overridden per assignment.';
$string['defaultusedelay'] = 'Use delayed review by default';
$string['defaultusedelay_desc'] = 'Defines whether delayed review is enabled by default in new assignments.';
$string['delayminutes'] = 'Delay time (minutes)';
$string['delayminutes_help'] = 'Number of minutes to wait after the student posts before executing the AI review.';
$string['downloadlog'] = 'Download log';
$string['edited'] = 'Edited';
$string['editgrade'] = 'Edit grade';
$string['email'] = 'Email';
$string['enableai'] = 'Enable AI';
$string['enableai_global_disabled_notice'] = 'AI activation for this assignment is unavailable because it has been globally disabled by an administrator.';
$string['enableai_help'] = 'If disabled, the rest of the options in this section are hidden for this assignment.';
$string['enableassignai'] = 'Enable Assign AI';
$string['enableassignai_desc'] = 'If disabled, the "Datacurso Assign AI" section is hidden from assignment activity settings and automatic processing is paused.';
$string['error_advancedresponsemissing'] = 'The assignment is graded with an advanced method ({$a}) but the AI response contains no data for it. The grade was not applied.';
$string['error_airequest'] = 'Error communicating with the AI service: {$a}';
$string['error_guidemismatch'] = 'The AI response does not match the marking guide of this assignment. The grade was not applied. Unmatched criteria: {$a}';
$string['error_processing_timeout'] = 'Processing timed out without a response; please retry.';
$string['error_rubricmismatch'] = 'The AI response does not match the rubric of this assignment. The grade was not applied. Unmatched criteria: {$a}';
$string['errorparsingguide'] = 'Error parsing assessment_guide_response: {$a}';
$string['errorparsingrubric'] = 'Error parsing rubric_response: {$a}';
$string['feedbackcomments'] = 'Comments';
$string['feedbackcommentsfull'] = 'Feedback comments';
$string['fullname'] = 'Full name';
$string['grade'] = 'Grade';
$string['gradesuccess'] = 'Grade successfully injected';
$string['gradingfailed_body'] = 'The AI grading for the assignment "{$a->assignment}" (student: {$a->student}) failed and the automatic retries are exhausted. Last error: {$a->error}';
$string['gradingfailed_subject'] = 'AI grading failed: {$a}';
$string['lastmodified'] = 'Last modified';
$string['log'] = 'Log';
$string['logdetails'] = 'AI log details';
$string['logerror'] = 'Error';
$string['logfailed'] = 'Failed';
$string['logsuccess'] = 'Success';
$string['manytasksreviewed'] = '{$a} tasks reviewed';
$string['messageprovider:gradingfailed'] = 'AI grading failure notifications';
$string['missingtaskparams'] = 'Missing task parameters. Unable to start AI batch processing.';
$string['modaltitle'] = 'AI Feedback';
$string['nopermissiontochangestatus'] = 'You do not have permission to save or approve changes to AI reviews. You can only view the details.';
$string['norecords'] = 'No records found';
$string['nostatus'] = 'No feedback';
$string['nosubmissions'] = 'No submissions found to process.';
$string['notasksfound'] = 'No tasks to review';
$string['onetaskreviewed'] = '1 task reviewed';
$string['pluginname'] = 'Assign AI';
$string['privacy:metadata:local_assign_ai_pending'] = 'Stores AI-generated feedback pending approval.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Unique token used to track approvals.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'The assignment this AI feedback belongs to.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'The submission attempt number this AI feedback relates to.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'The course associated with this feedback.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Whether this evaluation reflects a student edit of the submission.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'The error reported when AI processing failed.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'The AI-generated proposed grade.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'The feedback message generated by AI.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'The AI-generated rubric feedback.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'The approval status of the feedback.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'The submission attempt this AI feedback was generated for.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'The submission modification time captured when this AI feedback was generated.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'The title of the generated feedback.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'The user for whom the AI feedback was generated.';
$string['processed'] = '{$a} submission(s) processed successfully.';
$string['processing'] = 'Processing';
$string['processingerror'] = 'An error occurred while processing the AI review.';
$string['promptdefaulttext'] = 'Respond with an empathetic and motivating tone';
$string['qualify'] = 'Grade';
$string['queued'] = 'Queued';
$string['reloadpage'] = 'Please reload the page to see the updated results.';
$string['require_approval'] = 'Review AI Response';
$string['retry'] = 'Retry';
$string['retryallfailed'] = 'Retry failed';
$string['retryallqueued'] = '{$a} failed review(s) re-queued.';
$string['retryqueued'] = 'Review re-queued; it will be processed shortly.';
$string['review'] = 'Review';
$string['reviewaidisabled'] = 'AI review is disabled for this assignment.';
$string['reviewall'] = 'Review all';
$string['reviewcancelled'] = 'AI review cancelled.';
$string['reviewhistory'] = 'AI review history';
$string['reviewnotsubmitted'] = 'The submission attempt is not in a submitted state, so it cannot be reviewed by AI.';
$string['reviewwithai'] = 'Review with AI';
$string['rubricfailed'] = 'Failed to inject rubric after 20 attempts';
$string['rubricmustarray'] = 'rubric_response must be an array';
$string['rubricsuccess'] = 'Rubric successfully injected';
$string['save'] = 'Save';
$string['saveapprove'] = 'Save and Approve';
$string['status'] = 'Status';
$string['statusapprove'] = 'Approved';
$string['statuserror'] = 'Error';
$string['statuspending'] = 'Pending';
$string['statusrejected'] = 'Rejected';
$string['statussuperseded'] = 'Superseded (previous attempt)';
$string['submission_draft'] = 'Draft';
$string['submission_new'] = 'New';
$string['submission_none'] = 'No submission';
$string['submission_submitted'] = 'Submitted';
$string['submittedfiles'] = 'Submitted files';
$string['superseded'] = 'Superseded';
$string['task_process_ai_queue'] = 'Process delayed Assign AI queue';
$string['task_reap_stuck'] = 'Reap stuck AI submissions';
$string['task_retry_failed'] = 'Retry failed AI submissions';
$string['unexpectederror'] = 'An unexpected error occurred: {$a}';
$string['usedelay'] = 'Use delayed review';
$string['usedelay_help'] = 'If enabled, AI review will be executed after a configurable delay instead of immediately.';
$string['validity'] = 'Validity';
$string['viewaifeedback'] = 'View AI feedback';
$string['viewdetails'] = 'View details';
$string['viewlog'] = 'View log';
