## [1.1.10-wp] - 2026-11-09

**Compatibility note:** Moodle Workplace 4.5 branch (`-wp` release). Requires `aiprovider_datacurso` 2026110901 or later, the first release that keeps the plugin switches per tenant. The plugin still loads on a plain Moodle LMS.

### Changed

- The "Enable Assign AI" switch now lives only in the Datacurso AI provider, per tenant: it is set in the provider plugins list (Site administration > AI > Datacurso plugins list > Settings of Assign AI) for the active tenant, and the plugin reads it through `\aiprovider_datacurso\local\plugin_settings::is_enabled('local_assign_ai', $tenantid)`. Disabling it for a tenant hides the assignment settings section and pauses automatic processing only for that tenant; the other tenants keep working (`provider_plugin_state_test::test_feature_follows_the_provider_state`, `provider_plugin_state_test::test_tenant_a_disabled_while_tenant_b_enabled`)
- The plugin's own switch (`enableassignai`) is removed at site and tenant level: the settings page checkbox, the tenant value, its strings and `assignment_config::is_feature_enabled()` are gone (`provider_plugin_state_test::test_former_setting_is_ignored`, `provider_plugin_state_test::test_settings_page_no_longer_defines_the_setting`)
- Upgrade: the former site value and the value of every Workplace tenant are copied into the provider, then deleted. A value the provider already holds for a tenant is never overwritten (`provider_plugin_state_test::test_upgrade_copies_the_tenant_switches_to_the_provider`, `provider_plugin_state_test::test_upgrade_does_not_overwrite_the_provider`, `provider_plugin_state_test::test_upgrade_removes_the_former_values`)
- Moodle Workplace: the provider switch always follows the tenant of the user, never the tenant that owns the course. A tenant that disables Assign AI disables it for its users in every course, and nothing is processed for them: the pages, `lib.php` callbacks and web services check the tenant of the current user; the tasks `process_ai_queue`, `process_submission_ai`, `process_review_submission`, `process_all_submissions` and `retry_failed_submissions` and the submission observers check the tenant of the student the submission belongs to, never the course tenant nor the cron user. `assignment_config::is_ai_available()`, `is_ai_enabled_for_assignment()` and `is_autograde_enabled()` take the user id, and the new `is_provider_enabled_for_user()` applies the rule. The plugin's own per-tenant settings (AI defaults, credits, API tenant) keep resolving from the course tenant (`provider_plugin_state_test::test_tenant_a_disabled_while_tenant_b_enabled`, `plugin_disabled_guard_test::test_user_of_disabled_tenant_is_blocked_in_course_of_enabled_tenant`)
- `process_ai_queue` no longer stops for every tenant when one tenant is disabled: the queued row of a student whose tenant disabled Assign AI is deleted without being sent to the AI and does not count towards the batch of 20. Due rows are read in bounded pages of 20, walking forwards, so every row is read at most once per run and deleted rows cannot starve the other tenants (`process_ai_queue_task_test::test_rows_of_a_disabled_tenant_are_deleted_and_others_processed`)
- Show the Datacurso AI provider message when the plugin is disabled and stop its work: the review and history pages and the eight web services stop with the provider's `plugindisabled` error (with a link to the provider plugins list for site administrators), the AI tasks skip disabled tenants and log a translatable message (`task_skipped_plugindisabled`), the grader page scripts are not injected and manual grading no longer syncs approved AI records; `reap_stuck_submissions` and the cleanup observers keep running (`plugin_disabled_guard_test`)
- The minimum required version of `aiprovider_datacurso` is raised to 2026110901
- Tests skip when `aiprovider_datacurso` is not installed, because the CI pipeline does not install declared dependencies and the assignment creation callbacks, observers and AI entry points need it

## [1.1.9-wp] - 2026-10-13

**Compatibility note:** Moodle Workplace 4.5 branch (`-wp` release). The plugin still loads on a plain Moodle LMS, where the site settings page and site values keep working as before.

### Changed

- Moodle Workplace: every tenant now has a completely independent configuration. A setting resolves to the value the tenant saved, or otherwise to the plugin built-in default; it never reads the site value and never the value of another tenant
- Moodle Workplace: nothing is stored at site level any more and no site defaults are seeded, so nothing at site level can switch on, switch off or change all tenants at once. Each tenant is managed from its own configuration page
- Moodle Workplace: the Assign AI switches (`enableassignai`, `defaultenableai`) are per tenant: switching one tenant off pauses only that tenant
- Moodle Workplace: the global configuration of each tenant is the standard Assign AI settings page of Moodle LMS (`/admin/settings.php?section=local_assign_ai_settings`, same look and fields), now read and written per tenant. Site administrators find it in Site administration > Plugins > Local plugins > "Assign AI"; tenant administrators, who cannot see Local plugins, find the same page under Site administration > AI. Nobody sees two entries
- Moodle Workplace: the page edits the active Workplace tenant (the tenant selected with the Workplace tenant switcher; a tenant administrator always edits the own tenant) and a notice names it. The tenant is never taken from the request, a save is refused when no tenant is active, and a tenant starts with the plugin defaults: the page is pre-filled with the values the tenant saved, or with the plugin defaults, never with the site values
- Moodle Workplace: the custom tenant settings page (`tenant_settings.php`), its form and its tenant dropdown are removed, together with the `tenantid` URL parameter. Applying the default settings on install no longer touches any tenant
- Moodle Workplace: a course outside every tenant category, for a user without a tenant (tenant 0), resolves to the plugin built-in defaults instead of the site values. A plain Moodle LMS is unchanged
- The plugin built-in defaults are defined once in `tenant_config` and reused by the LMS settings page

## [1.1.8-wp] - 2026-10-06

**Compatibility note:** Moodle Workplace 4.5 branch (`-wp` release). The plugin still loads on a plain Moodle LMS, where tenant features are inactive.

### Added

- Moodle Workplace: the global defaults (`enableassignai`, `defaultenableai`, `defaultautograde`, `defaultusedelay`, `defaultdelayminutes`, `defaultprompt`) can now be set per tenant. Values are stored in the new `local_assign_ai_tenant_config` table and fall back to the site settings when a tenant has none
- Moodle Workplace: new tenant settings page (Site administration > AI > Tenant settings), editable by tenant administrators for their own tenant and by site administrators for any tenant
- New capability `local/assign_ai:managetenantsettings`, granted to managers and to the Workplace tenant administrator role (the upgrade step assigns it to existing tenant administrator roles)

### Fixed

- Moodle Workplace: AI requests sent from cron and ad hoc tasks now use the licence of the course tenant (or the student's tenant when the course is outside a tenant category) instead of the licence of the tenant of the administrator running the task
- Moodle Workplace: the tenant settings form can be saved again. The delay is only validated while it is visible (AI, autograde and delay switched on), so turning the AI or the delay off no longer fails on a hidden field
- Moodle Workplace: new assignments are pre-filled, and seeded when created, with the defaults of the tenant of the course instead of the site values
- Moodle Workplace: the tenant selector on the tenant settings page navigates as soon as a tenant is chosen, the page is titled "Assign AI tenant settings" and the heading is no longer duplicated
- Moodle Workplace: a tenant (or site) with the Assign AI switches off no longer starts AI processing. The review and history pages show the unavailable notice, `process_submission` is refused, the retry task skips it and delay queue rows queued before the switch went off are deleted instead of being processed

### Changed

- Assignment defaults are resolved through a single `assignment_config::get_default()` / `get_defaults()` path, replacing the duplicated block in `lib.php`
- Restoring an assignment into a course of another tenant keeps clearing the configured grader; this behaviour is now covered by a test
- Removed the `moodle-release` GitHub workflow from the Workplace branch

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
