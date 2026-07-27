-- uq_hearing_observation included the nullable `room` column; MariaDB treats
-- NULLs in a unique key as distinct, so observations without a room would
-- never be deduplicated and the import idempotency would silently break.
-- Today that is theoretical - the infojednani source always carries a room
-- (it is the query parameter; 0 NULL rooms in data) - but the planned
-- 'infosoud' source builds observations from NAR_JED/ZRUS_JED details where
-- JED_SIN may be missing. Same pattern as proceeding_relation.dst_court_key:
-- a generated NOT NULL variant of the column stands in inside the unique.
--
-- Verify after: SELECT COUNT(*) FROM hearing_observation WHERE room_key <> IFNULL(room, ''); -- 0
ALTER TABLE `hearing_observation`
    ADD COLUMN `room_key` VARCHAR(255) GENERATED ALWAYS AS (IFNULL(`room`, '')) STORED AFTER `room`,
    DROP INDEX `uq_hearing_observation`,
    ADD UNIQUE KEY `uq_hearing_observation` (`hearing_id`, `source`, `observed_at`, `room_key`);
