## [Unreleased]

**Compatibility note:** This version is compatible with **Moodle 4.5** only (`$plugin->supported = [405, 405]`); Moodle 5.0 to 5.3 are served by the `MOODLE_503_STABLE` branch.

### Fixed

- A site with the Datacurso AI provider older than 1.7.0 no longer breaks with `Class "aiprovider_datacurso\local\plugin_settings" not found` on every page. Every use of the provider's plugin state now goes through the new `\local_assign_ai\local\provider_dependency`: with an older provider the plugin pauses itself and the rest of the site keeps working. Hooks, observers, course module callbacks and navigation do nothing, the AI tasks skip their run and log `task_skipped_providerupdaterequired`, the review and history pages and the eight web services stop with `providerupdaterequired_admin` (site administrators: update the Datacurso AI provider to 1.7.0 or later, with a link to the plugins overview) or `providerupdaterequired` (anyone else), and the plugin settings page shows the administrator the same warning. With the provider 1.7.0 or later the behaviour is unchanged (`provider_dependency_test`, `provider_missing_guard_test`, `plugin_disabled_guard_test::test_pages_require_the_plugin_enabled`)

### Changed

- Declared compatibility with Moodle 4.5 only (`$plugin->supported` is now `[405, 405]`); the minimum `aiprovider_datacurso` version stays 1.7.0 (2026110400)
- `change_status` only accepts the `approve` and `rejected` actions sent by the review page; any other value fails with `invalid_parameter_exception` and leaves the record untouched (`change_status_test::test_rejects_an_unknown_action`)
- `process_submission` validates its parameters with `validate_parameters()` before using them (`process_submission_test::test_rejects_invalid_parameters`)

### Removed

- The plugin `.github` folder (issue templates and GitHub Actions workflows); CI and releases run on Jenkins

### Security

- The Datacurso AI provider now logs each request to the AI service (`/assign/answer`) with its `external_request_completed` event in the assignment module context, with the student as related user, instead of the system context: `client::send_to_ai()` takes the context and the student and passes them to the provider client's `set_audit_context()` when the installed provider has it (`client_test`, `submission_sent_to_ai_test::test_provider_request_is_audited_in_the_assignment_context`)
- LAA-SEC-006: The eight web services authorize at one common point, the new `\local_assign_ai\local\activity_access`, before any query that depends on the requested user or record. `require_access()` resolves the assign activity from the course module, validates its context, requires the capability and rejects a course id that does not own the activity (`invalidcourseid`); `require_pending_record()` loads pending records scoped inside the query to the authorized activity and course and fails with the same `invalidpendingrecord` error whether the record is absent or belongs elsewhere (`activity_access_test::test_require_access_returns_the_activity_for_an_authorised_user`, `activity_access_test::test_require_access_rejects_a_course_that_does_not_own_the_activity`, `activity_access_test::test_require_access_rejects_an_unenrolled_user`, `activity_access_test::test_require_pending_record_fails_the_same_way_for_absent_and_foreign_records`, `activity_access_test::test_require_pending_record_returns_the_record_of_the_activity`)
- LAA-SEC-006: `get_details` no longer looks up the review before authorizing, so an unenrolled user or a student gets the same error whether or not a classmate has an AI review; an authorized teacher still gets the status `none` when there is no review, and a course id that does not own the activity is rejected (`get_details_test::test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists`, `get_details_test::test_student_gets_the_same_error_whether_or_not_a_review_exists`, `get_details_test::test_teacher_gets_the_review_details`, `get_details_test::test_teacher_gets_status_none_when_there_is_no_review`, `get_details_test::test_rejects_a_course_that_does_not_own_the_activity`)
- LAA-SEC-006: `update_response` and `change_status` authorize before looking up the pending review, so a missing record no longer answers with a different error than a missing capability; an authorized teacher without a pending review gets `invalidpendingrecord` (`update_response_test::test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists`, `update_response_test::test_rejects_a_course_that_does_not_own_the_activity`, `update_response_test::test_teacher_gets_the_uniform_error_when_there_is_no_pending_review`, `change_status_test::test_unenrolled_user_gets_the_same_error_whether_or_not_a_review_exists`, `change_status_test::test_rejects_a_course_that_does_not_own_the_activity`, `change_status_test::test_teacher_gets_the_uniform_error_when_there_is_no_pending_review`, `change_status_test::test_teacher_rejects_a_pending_review`)
- LAA-SEC-006: `cancel_review` and `process_submission` no longer reveal pending record ids of other activities: a record of another activity, course or user fails exactly like an id that does not exist (`cancel_review_test::test_unenrolled_user_gets_the_same_error_whether_or_not_the_record_exists`, `cancel_review_test::test_foreign_and_absent_records_fail_the_same_way`, `cancel_review_test::test_teacher_cancels_a_queued_review`, `process_submission_test::test_unenrolled_user_gets_the_same_error_whether_or_not_the_record_exists`, `process_submission_test::test_foreign_and_absent_records_fail_the_same_way`)
- LAA-SEC-006: `approve_all_pending`, `get_token` and `get_progress` already authorized first and now use the common helper without behaviour change; `get_progress` still drops the records the caller cannot review (`approve_all_pending_test::test_unenrolled_user_gets_the_same_error_whether_or_not_reviews_exist`, `get_token_test::test_unenrolled_user_gets_the_same_error_whether_or_not_a_token_exists`, `get_progress_test::test_unenrolled_user_gets_the_same_answer_whether_or_not_the_record_exists`)
- LAA-PRIV-004: Every transfer of a submission to the AI service (`/assign/answer`), from autograde and from the teacher review, is logged with the new `\local_assign_ai\event\submission_sent_to_ai` event, whether the service answers or fails. It records the actor (the teacher who requested the review, else the configured grader, else the system), the student, the activity and submission, the endpoint, the result and, on failure, only the error code; the prompt, submission text, files, student name, AI reply and grade are never logged (`submission_sent_to_ai_test::test_successful_review_logs_transfer_without_content`, `submission_sent_to_ai_test::test_failed_review_logs_transfer_with_errorcode`, `submission_sent_to_ai_test::test_failed_transfer_with_generic_error_logs_class_name`, `submission_sent_to_ai_test::test_needs_review_answer_is_logged_as_completed_transfer`, `submission_sent_to_ai_test::test_autograde_logs_transfer_with_grader_as_actor`, `submission_sent_to_ai_test::test_autograde_without_grader_logs_system_actor`, `submission_sent_to_ai_test::test_submission_not_sent_logs_nothing`, `submission_sent_to_ai_test::test_event_requires_relateduser_and_result`)
- LAA-SEC-006: Every web service in `db/services.php` declares the capability its `execute()` requires; `get_details` (`local/assign_ai:viewdetails`), `update_response` and `change_status` (`local/assign_ai:changestatus`) declared none (`services_declaration_test::test_every_function_is_covered`, `services_declaration_test::test_every_function_declares_its_required_capability`, `services_declaration_test::test_declared_capability_matches_the_one_checked_by_execute`)
- LAA-PRIV-004: The course module id (`cmi_id`) is no longer sent to the AI service and is removed from the outbound allowlist; the activity is identified by `assignment_id` and the course by `course_id` (`payload_anonymizer_test::test_course_module_id_is_not_sent`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`)
- The retry and log actions of the history page authorize through `activity_access` and load the record scoped to the activity, so an id that does not exist and an id of another activity fail the same way (`invalidpendingrecord`); a manual retry is now attributed to the teacher who requested it (`history_service_test::test_get_log_record_returns_a_record_of_the_activity`, `history_service_test::test_get_log_record_fails_the_same_for_absent_and_foreign_ids`, `history_service_test::test_retry_review_fails_the_same_for_absent_and_foreign_ids`, `history_service_test::test_retry_review_requeues_a_failed_record`, `history_service_test::test_retry_review_of_an_approved_record_creates_a_new_attempt`, `history_service_test::test_get_log_record_requires_the_review_capability`)
- LAA-AUD-011: "Retry all failed" on the history page now queues its task with the teacher who clicked it as reviewer, so each `submission_sent_to_ai` event and the AI consumption name that teacher instead of the configured grader or the system. The action moved to `history_service::retry_all_failed()`, which authorizes on the activity and re-queues only that activity's failed reviews (`history_service_test::test_retry_all_failed_queues_the_task_with_the_teacher_as_reviewer`, `history_service_test::test_retry_all_failed_without_failed_records_queues_nothing`, `history_service_test::test_retry_all_failed_requires_the_review_capability`, `submission_sent_to_ai_test::test_retry_all_failed_logs_transfer_with_the_teacher_as_actor`)
- The review table no longer always shows the student email: the identity columns follow the site setting `showuseridentity` and the capability `moodle/site:viewuseridentity`, and their values are escaped (`review_table_test::test_no_email_column_when_identity_fields_are_empty`, `review_table_test::test_email_column_shown_to_teacher_with_identity_capability`, `review_table_test::test_email_column_hidden_without_identity_capability`, `review_table_test::test_identity_value_is_escaped`, `review_table_test::test_query_selects_identity_fields`)
- The AI status badge and hint of the review table are escaped, so an error message with markup is shown as text (`review_table_test::test_failed_hint_with_markup_is_escaped`)

## [1.2.2] - 2026-11-06

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.3**.

### Changed

- The "Enable Assign AI" switch moved to the Datacurso AI provider: it is now the Enabled switch of Assign AI in Site administration > AI > Datacurso plugins list, and the plugin reads it through `\aiprovider_datacurso\local\plugin_settings::is_enabled('local_assign_ai')`. Disabling it still hides the assignment settings section and pauses automatic processing, and now also stops the plugin's pages, web services and tasks (below) (`provider_plugin_state_test::test_feature_follows_the_provider_state`, `provider_plugin_state_test::test_former_setting_is_ignored`)
- The former `local_assign_ai/enableassignai` setting is removed from the plugin settings page and deleted on upgrade (2026110600), with its strings `enableassignai` and `enableassignai_desc`; its value is preserved, because the provider copies it into its own setting before the plugin upgrades (`provider_plugin_state_test::test_settings_page_no_longer_defines_the_setting`, `provider_plugin_state_test::test_upgrade_removes_the_former_setting`)
- The per-assignment default "Enable AI" (`defaultenableai`) is unchanged and stays in the plugin settings
- Show the Datacurso AI provider message when the plugin is disabled and stop its work: the review and history pages and the eight web services stop with the provider's `plugindisabled` error (`plugindisabled_admin`, with a link to the provider plugins list for site administrators), the AI tasks `process_ai_queue`, `retry_failed_submissions`, `send_error_digest`, `process_submission_ai`, `process_review_submission` and `process_all_submissions` skip their run and log a translatable message (`task_skipped_plugindisabled`), the grader page scripts are not injected and manual grading no longer syncs approved AI records; `reap_stuck_submissions` and the cleanup observers keep running (`plugin_disabled_guard_test`)
- The minimum required version of `aiprovider_datacurso` is raised to 1.7.0 (2026110400), the first release that has `plugin_settings::require_enabled()`
- `\local_assign_ai\config\assignment_config::is_feature_enabled()` is removed; code that called it uses `plugin_settings::is_enabled('local_assign_ai')`

## [1.2.1] - 2026-11-05

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.3**.

### Changed

- Declared compatibility with Moodle 5.3 (`$plugin->supported` is now `[405, 503]`). Manual and automated checks on Moodle 5.3 found no regressions caused by the new core version.

## [1.2.0] - 2026-10-22

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Added

- **Submission size limits applied before anything is sent.** `assign_submission::MAX_FILE_MB` (45 MB per file) and `MAX_TOTAL_MB` (30 MB in total) are fixed in code, mirror the defaults of the AI service and are checked from the stored file sizes before any content is read. An oversized submission fails at once as a terminal failure: no AI call, no automatic retry, a message that names the limit and a single notice to the grader (`error_file_too_large`, `error_payload_too_large`).
- **Terminal failures for AI answers that carry no grade.** When the service reports an unreadable file or inconsistent evaluations, the submission is stored as `failed` with no grade and no rubric, retries set to the maximum and a message asking to grade it manually (`error_unreadable_file`, `error_inconsistent_evaluation`, `error_needsreview_generic`). The message is shown as the red "Error" badge of the review table and in the history report.
- **Error summary by email for administrators.** New scheduled task `send_error_digest` (every 10 minutes) and two site settings: `errornotifyenabled` (on by default) and `errornotifyemails` (up to 10 addresses, validated, lowercased and de-duplicated by the new `setting_emaillist`). It collects definitive failures of the last 7 days, groups them by course, activity and error kind, and sends one email per address (plain text and HTML) with counts and a link to the history page. Student names, file names and error details are never included. New `errornotifiedtime` column on `local_assign_ai_pending`, declared in the Privacy API, so each failure is reported once unless it changes again.
- **Tests.** `payload_limit_and_review_failures_test.php` (limits, terminal failures, notices, history and review table), `send_error_digest_test.php`, `setting_emaillist_test.php` and `upgrade_test.php`, plus 25 new strings in all 9 language packs.

### Changed

- **An HTTP 413 from the AI service is a terminal failure.** It is never retried and shows its own message (`error_service_payload_rejected`); HTTP 502, 503 and 504 keep the automatic retries.
- **Manual reviews that fail definitively now notify the grader too,** like the other terminal failures.
- **`client::send_to_ai` also returns `needs_review` and `review_reason`** when the service answers without a grade.

### Fixed

- **A service answer without a grade raised an undefined index.** `client::send_to_ai` now tolerates a missing `grade`, so unreadable or inconsistent evaluations end as a clear failure instead of an error or a misleading low grade.

## [1.1.9] - 2026-10-08

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Security

- LAA-SEC-005: Removing or resubmitting a submission now clears only the queue rows of that exact user in that exact activity. The queue payload is decoded and compared exactly instead of matched with `LIKE '%"userid":12%'` patterns, which also matched user `123` or course module `50` and could delete other students' or other activities' delayed reviews (`submission_observer_test::test_removing_a_submission_only_clears_the_queue_rows_of_that_user_and_activity`, `submission_observer_test::test_delayed_submission_replaces_only_the_queue_rows_of_that_user_and_activity`)
- The queue cleanup run on submission created/removed events only scans unprocessed queue rows (`processed = 0`, id and payload columns), since processed rows are kept as history and can no longer be cancelled; the scan no longer grows with the processed history (`submission_observer_test::test_removing_a_submission_keeps_already_processed_queue_rows`)

### Privacy

- LAA-PRIV-001: The payload sent to the Datacurso AI service is restricted to an explicit, plugin-owned allowlist (`payload_anonymizer::ALLOWED_FIELDS`); any other field is dropped before sending, and the student name travels as the plugin-owned `[STUDENT_NAME]` placeholder (`payload_anonymizer_test::test_fields_outside_the_allowlist_are_dropped`, `payload_anonymizer_test::test_deanonymize_text_leaves_empty_text_and_empty_replacements_untouched`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`)
- LAA-PRIV-001: The outbound `userid` is now a stable, non-reversible, site-scoped pseudonym instead of the raw Moodle user id. The pseudonymisation is delegated to the provider's shared helper `\aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid_value()` (HMAC-SHA256 of the reviewer id keyed with a per-site secret owned by aiprovider_datacurso and never sent to the services, truncated to 32 hex characters, produced by `outbound_privacy::pseudonymise_userid()`), so the whole Datacurso suite uses one formula and Assign AI owns no HMAC code (`payload_anonymizer_test::test_userid_is_replaced_by_a_deterministic_site_scoped_pseudonym`, `provider_pseudonymiser_test::test_production_adapter_delegates_to_the_provider`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`)
- LAA-PRIV-001: The minimum required version of `aiprovider_datacurso` is raised to 1.6.0 (2026100900), the first release shipping the shared `outbound_privacy` userid pseudonymisation helper
- LAA-PRIV-001: The userid pseudonymiser is injectable: `payload_anonymizer` resolves the `userid_pseudonymiser` port through the DI container, bound to the `provider_pseudonymiser` adapter in `db/hooks.php`, so the anonymiser unit tests bind a fake and do not depend on the installed provider version while one contract test checks the adapter against the provider (`payload_anonymizer_test::test_userid_is_the_token_returned_by_the_bound_pseudonymiser`, `payload_anonymizer_test::test_userid_is_replaced_by_a_deterministic_site_scoped_pseudonym`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`, `provider_pseudonymiser_test::test_container_resolves_the_provider_adapter_by_default`, `provider_pseudonymiser_test::test_production_adapter_delegates_to_the_provider`)
- LAA-PRIV-001: New `_docs/privacy.md` documents the purpose of the transfer, the allowlist with a justification per field, the pseudonymisation and the provider retention/location section; `_docs/ai_payloads.md` examples now show the pseudonymous token and the Privacy API external location describes `userid` as a pseudonymous token
- LAA-PRIV-003: The Privacy API metadata for `local_assign_ai_config` now declares every stored field (`enableai`, `autograde`, `usedelay`, `delayminutes`, `prompt`, `lang`, `timecreated`, `timemodified` in addition to `assignmentid`, `graderid`, `usermodified`) in all language packs, and the grader's export is verified to include the prompt, language and flags (`privacy\provider_test::test_get_metadata_declares_every_config_field`, `privacy\provider_test::test_export_includes_grader_configuration_values`)

## [1.1.8] - 2026-10-01

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Changed

- Moodle 5.0 to 5.2 support: `$plugin->supported` is now `[405, 502]` and `$plugin->requires` stays on the Moodle 4.5 release, so the same release installs on Moodle 4.5, 5.0, 5.1 and 5.2
- Continuous integration runs the plugin against Moodle 4.5 (MariaDB and PostgreSQL), Moodle 5.0 (MariaDB) and Moodle 5.2 (MariaDB) in Jenkins and in the manual GitHub workflow; the GitHub workflow installs the `aiprovider_datacurso` `MOODLE_500_STABLE` branch on Moodle 5.2, as that branch supports Moodle 5.0 to 5.2

### Fixed

- The AI review details window opens again on Moodle 5.2: it is now created with `core/modal` instead of the removed `core/modal_factory` (MDL-79182)
- The AI feedback editor in the review details window is editable again on Moodle 5.2: TinyMCE 8 opened it read-only because the plugin initialised it without the `gpl` license key
- PHPUnit tests no longer fail with deprecation debugging on Moodle 5.2: modules are duplicated and deleted through `core_courseformat` `cmactions` when available (MDL-86858, MDL-86856)
- PHPUnit AI pipeline tests create an enabled Datacurso provider instance, which Moodle 5.0 and later read the license key from
