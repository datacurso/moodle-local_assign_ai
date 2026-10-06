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
