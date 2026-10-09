## [1.1.9] - 2026-10-08

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.2**.

### Security

- LAA-SEC-005: Removing or resubmitting a submission now clears only the queue rows of that exact user in that exact activity. The queue payload is decoded and compared exactly instead of matched with `LIKE '%"userid":12%'` patterns, which also matched user `123` or course module `50` and could delete other students' or other activities' delayed reviews (`submission_observer_test::test_removing_a_submission_only_clears_the_queue_rows_of_that_user_and_activity`, `submission_observer_test::test_delayed_submission_replaces_only_the_queue_rows_of_that_user_and_activity`)
- The queue cleanup run on submission created/removed events only scans unprocessed queue rows (`processed = 0`, id and payload columns), since processed rows are kept as history and can no longer be cancelled; the scan no longer grows with the processed history (`submission_observer_test::test_removing_a_submission_keeps_already_processed_queue_rows`)

### Privacy

- LAA-PRIV-001: The payload sent to the Datacurso AI service is restricted to an explicit allowlist (`payload_anonymizer::ALLOWED_FIELDS`); any other field is dropped before sending. The filtering is delegated to the shared helper `\aiprovider_datacurso\local\outbound_privacy` of aiprovider_datacurso 1.6.0 (`payload_anonymizer_test::test_fields_outside_the_allowlist_are_dropped`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`)
- LAA-PRIV-001: The outbound `userid` is now a stable, non-reversible, site-scoped pseudonym instead of the raw Moodle user id. The token is produced by the provider's shared helper `outbound_privacy::pseudonymise_userid()` (HMAC-SHA256 keyed with the site identifier and the provider's frozen key suffix); Assign AI no longer owns any pseudonymisation logic (`payload_anonymizer_test::test_userid_is_replaced_by_a_deterministic_site_scoped_pseudonym`, `payload_anonymizer_test::test_userid_matches_the_provider_pseudonym`, `assign_submission_test::test_outbound_payload_is_pseudonymised_and_restricted_to_the_allowlist`)
- LAA-PRIV-001: The minimum required version of `aiprovider_datacurso` is raised to 1.6.0 (2026100900), the first release shipping the shared `outbound_privacy` helper
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
