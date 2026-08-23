-- Materializes hearing records nested in the infosoud timeline. A hearing
-- scheduled for several terms is a single NAR_JED/ZRUS_JED timeline event
-- whose other records exist only in its nested jednani[] array (own poradi,
-- no row of their own; see docs/infosoud-api.md). CaseFileProjectionService
-- now projects them as ordinary case_file_event rows; parent_event_order
-- carries the poradi of the aggregating record such a row was found under,
-- NULL for a regular top-level event.
--
-- The link is a natural key on purpose (no surrogate FK): a nested record
-- inherits the parent's event_code, so the parent row is identified by
-- (case_file_id, source, event_code, parent_event_order) - which the unique
-- key uq_case_file_event_own already spans - and the value survives the
-- environment sync, which never transfers surrogate ids.
--
-- No data conversion happens here: existing nested records materialize on
-- the next projection of each case (any refresh or sync import).
--
-- ORDER OF DEPLOYMENT: hard cutover with the matching code - the entity
-- hydrates the column on every read. Run immediately before or after
-- uploading it. The non-terminal position means a table rebuild (seconds).
--
-- Verify after (expected: one row, IS_NULLABLE = YES):
--   SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_file_event'
--     AND COLUMN_NAME = 'parent_event_order';

ALTER TABLE `case_file_event`
    ADD COLUMN `parent_event_order` INT UNSIGNED NULL DEFAULT NULL AFTER `cancelled`;
