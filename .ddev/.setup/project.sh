# .ddev/.setup/project.sh — repo-owned customizations for `ddev install`.
#
# This file is sourced by utils.sh on every install. It is not managed by the
# add-on, so it survives `ddev add-on get` upgrades. Delete anything you don't
# need.
#
# $VERSION expands at use time, not when this file is sourced — write it
# single-quoted (or with \$VERSION inside a double-quoted string) so it
# reaches the composer command literally and gets substituted per version.

# Extra composer packages installed alongside the base install.
# routing-benchmark and routing-test are local fixtures (see FIXTURE_EXTENSION_DIRS
# below); typo3-request-profiler is external. Together they drive `ddev benchmark`.
ADDITIONAL_PACKAGES=(
    'konradmichalik/routing-benchmark:*@dev'
    'konradmichalik/typo3-request-profiler:dev-main'
)

# Replaces the default test/sitepackage with the project's real fixture extension.
SITEPACKAGE_PACKAGES=(
    'konradmichalik/routing-test:*@dev'
)

# Symlink the fixture extensions in Tests/Functional/Fixtures/Extensions into packages/.
FIXTURE_EXTENSION_DIRS=(
    'Tests/Functional/Fixtures/Extensions'
)
