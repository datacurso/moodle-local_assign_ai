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
