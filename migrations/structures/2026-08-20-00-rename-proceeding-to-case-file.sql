-- Renames the `proceeding` family to `case_file`, closing the naming decision
-- recorded in CLAUDE.md (*Terminologie a pojmenovani*): the container is named
-- after its content, and the content is a court case file. The wave was held
-- back until the typed-entity refactoring was finished, so entities already
-- read CaseFile / CaseFileEvent / CaseFileRelation - only the storage lags.
--
-- Nothing here converts data. RENAME TABLE and RENAME COLUMN/KEY are metadata
-- operations; the FKs are dropped and re-added only to carry the new names
-- (MariaDB cannot rename a constraint, and it refuses to rename a column while
-- an FK still points at it). Re-adding the FK on `hearing` revalidates ~36k
-- rows, which is the only real work in this file.
--
-- Secondary index names are unified on the `idx_` prefix at the same time;
-- unique keys keep `uq_`, FK constraints keep `fk_`. The remaining `ix_`
-- indexes on untouched tables follow in 2026-08-20-01.
--
-- ORDER OF DEPLOYMENT: this is a hard cutover - the old code cannot read the
-- new schema and vice versa. Run it immediately before or after uploading the
-- matching code, and take a database backup first.
--
-- Verify after (expected: no rows):
--   SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'proceeding_id';

RENAME TABLE
    `proceeding`          TO `case_file`,
    `proceeding_event`    TO `case_file_event`,
    `proceeding_relation` TO `case_file_relation`;

ALTER TABLE `case_file`
    RENAME KEY `uq_proceeding_case`          TO `uq_case_file_case`,
    RENAME KEY `ix_proceeding_registry_year` TO `idx_case_file_registry_year`,
    RENAME KEY `idx_proceeding_spisovka`     TO `idx_case_file_spisovka`,
    DROP FOREIGN KEY `fk_proceeding_court`,
    ADD CONSTRAINT `fk_case_file_court` FOREIGN KEY (`court_kod`) REFERENCES `court` (`kod`);

ALTER TABLE `case_file_event`
    DROP FOREIGN KEY `fk_event_proceeding`,
    RENAME COLUMN `proceeding_id` TO `case_file_id`,
    RENAME KEY `uq_event_own`      TO `uq_case_file_event_own`,
    RENAME KEY `ix_event_timeline` TO `idx_case_file_event_timeline`,
    ADD CONSTRAINT `fk_case_file_event_case_file`
        FOREIGN KEY (`case_file_id`) REFERENCES `case_file` (`id`) ON DELETE CASCADE;

ALTER TABLE `case_file_relation`
    RENAME KEY `ix_relation_dst` TO `idx_case_file_relation_dst`;

ALTER TABLE `favorite`
    DROP FOREIGN KEY `fk_favorite_proceeding`,
    RENAME COLUMN `proceeding_id` TO `case_file_id`,
    RENAME KEY `fk_favorite_proceeding` TO `idx_favorite_case_file`,
    ADD CONSTRAINT `fk_favorite_case_file`
        FOREIGN KEY (`case_file_id`) REFERENCES `case_file` (`id`);

ALTER TABLE `hearing`
    DROP FOREIGN KEY `fk_hearing_proceeding`,
    RENAME COLUMN `proceeding_id` TO `case_file_id`,
    RENAME KEY `ix_hearing_proceeding` TO `idx_hearing_case_file`,
    ADD CONSTRAINT `fk_hearing_case_file`
        FOREIGN KEY (`case_file_id`) REFERENCES `case_file` (`id`) ON DELETE SET NULL;
