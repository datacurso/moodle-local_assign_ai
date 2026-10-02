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
