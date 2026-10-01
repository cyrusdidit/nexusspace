SET @profile_schema_version_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profile_customizations' AND column_name = 'schema_version'),
    'SELECT 1',
    'ALTER TABLE profile_customizations ADD COLUMN schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER user_id'
);
PREPARE profile_schema_version_migration FROM @profile_schema_version_sql;
EXECUTE profile_schema_version_migration;
DEALLOCATE PREPARE profile_schema_version_migration;

SET @profile_simple_settings_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profile_customizations' AND column_name = 'simple_settings'),
    'SELECT 1',
    'ALTER TABLE profile_customizations ADD COLUMN simple_settings JSON NULL AFTER schema_version'
);
PREPARE profile_simple_settings_migration FROM @profile_simple_settings_sql;
EXECUTE profile_simple_settings_migration;
DEALLOCATE PREPARE profile_simple_settings_migration;

SET @profile_sidebar_width_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profile_customizations' AND column_name = 'sidebar_width'),
    'SELECT 1',
    'ALTER TABLE profile_customizations ADD COLUMN sidebar_width SMALLINT UNSIGNED NOT NULL DEFAULT 280 AFTER simple_settings'
);
PREPARE profile_sidebar_width_migration FROM @profile_sidebar_width_sql;
EXECUTE profile_sidebar_width_migration;
DEALLOCATE PREPARE profile_sidebar_width_migration;

SET @profile_published_revision_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'profile_customizations' AND column_name = 'published_revision'),
    'SELECT 1',
    'ALTER TABLE profile_customizations ADD COLUMN published_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER custom_css'
);
PREPARE profile_published_revision_migration FROM @profile_published_revision_sql;
EXECUTE profile_published_revision_migration;
DEALLOCATE PREPARE profile_published_revision_migration;

UPDATE profile_customizations
SET simple_settings = JSON_OBJECT()
WHERE simple_settings IS NULL OR JSON_VALID(simple_settings) = 0;

CREATE TABLE IF NOT EXISTS profile_customization_drafts (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    simple_settings JSON NOT NULL,
    sidebar_width SMALLINT UNSIGNED NOT NULL DEFAULT 280,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_customization_drafts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS profile_customization_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    simple_settings JSON NOT NULL,
    sidebar_width SMALLINT UNSIGNED NOT NULL,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY profile_customization_revision (user_id, revision_number),
    KEY profile_customization_revision_created (user_id, created_at),
    CONSTRAINT fk_profile_customization_revisions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO profile_customization_revisions (
    user_id,
    revision_number,
    schema_version,
    simple_settings,
    sidebar_width,
    template_html,
    custom_css,
    created_at
)
SELECT
    customization.user_id,
    1,
    customization.schema_version,
    customization.simple_settings,
    customization.sidebar_width,
    customization.template_html,
    customization.custom_css,
    customization.updated_at
FROM profile_customizations customization
WHERE NOT EXISTS (
    SELECT 1
    FROM profile_customization_revisions revision
    WHERE revision.user_id = customization.user_id
);

UPDATE profile_customizations customization
SET published_revision = 1
WHERE customization.published_revision = 0;
