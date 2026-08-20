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


-- Unifies the naming of secondary indexes: every plain index is `idx_`,
-- unique keys stay `uq_`, foreign key constraints stay `fk_`. The prefix had
-- drifted between `ix_` and `idx_` over the earlier migrations, and the
-- indexes that back a foreign key carried the constraint's own `fk_` name -
-- MariaDB names them after the constraint when none is given.
--
-- Renaming such an index does not touch the constraint: the FK keeps its
-- `fk_` name and keeps using the index under its new one (verified on 10.5).
-- Note that a future DROP + re-ADD of one of these FKs without an explicit
-- index would reintroduce an `fk_`-named index.
--
-- Metadata-only, no table rebuild, no data touched. Index names appear
-- nowhere in the application - Nette Database never references them - so this
-- is independent of any deploy.
--
-- Verify after (expected: no rows):
--   SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
--   WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME <> 'PRIMARY'
--     AND INDEX_NAME NOT LIKE 'idx\_%' AND INDEX_NAME NOT LIKE 'uq\_%';

ALTER TABLE `case_file_relation`
    RENAME KEY `fk_relation_type` TO `idx_case_file_relation_type`;

ALTER TABLE `court`
    RENAME KEY `fk_court_parent` TO `idx_court_parent`,
    RENAME KEY `ix_court_level`  TO `idx_court_level`;

ALTER TABLE `court_prefix`
    RENAME KEY `fk_court_prefix_court` TO `idx_court_prefix_court`;

ALTER TABLE `favorite`
    RENAME KEY `fk_favorite_group` TO `idx_favorite_group`,
    RENAME KEY `ix_favorite_order` TO `idx_favorite_order`;

ALTER TABLE `favorite_group`
    RENAME KEY `ix_favorite_group_order` TO `idx_favorite_group_order`;

ALTER TABLE `hearing`
    RENAME KEY `ix_hearing_date`     TO `idx_hearing_date`,
    RENAME KEY `ix_hearing_room_id`  TO `idx_hearing_room_id`,
    RENAME KEY `ix_hearing_spisovka` TO `idx_hearing_spisovka`;

ALTER TABLE `hearing_observation`
    RENAME KEY `ix_hearing_observation_hearing` TO `idx_hearing_observation_hearing`;

ALTER TABLE `hearing_room`
    RENAME KEY `ix_hearing_room_kind`     TO `idx_hearing_room_kind`,
    RENAME KEY `ix_hearing_room_off_site` TO `idx_hearing_room_off_site`;

ALTER TABLE `registry`
    RENAME KEY `ix_registry_norm` TO `idx_registry_norm`,
    RENAME KEY `ix_registry_slug` TO `idx_registry_slug`;

ALTER TABLE `senate_rule`
    RENAME KEY `fk_senate_rule_court` TO `idx_senate_rule_court`;
