## [1.1.7] - 2026-10-01

**Compatibility note:** This version is compatible from **Moodle 4.5** to **Moodle 5.0**.

### Changed

- Moodle 5.0 support: `$plugin->supported` is now `[405, 500]` and `$plugin->requires` stays on the Moodle 4.5 release, so the same release installs on either branch
- Continuous integration runs the plugin against Moodle 4.5 (MariaDB and PostgreSQL) and Moodle 5.0 (MariaDB) in Jenkins and in the manual GitHub workflow, installing the matching `aiprovider_datacurso` branch for each Moodle version
