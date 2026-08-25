-- Case-level summary values materialized out of the raw infosoud payload.
--
-- Until now the case detail header and the Panel dashboard read them straight
-- from case_file.infosoud_json at render time (subject additionally from the
-- first event's detail_json). The raw JSON columns are meant to be verbatim
-- snapshots read only when writing, checking or analysing the record - never
-- during ordinary page rendering. These columns are the projection of that
-- data, written at the same choke points as the event/relation projections.
--
-- NULL means "not stated by the source" - infosoud renders a blank or "-"
-- there, and the extraction normalizes both to NULL.
--
-- Lengths are sized off the current data (dev: subject 157, status 142,
-- intake_kind 53 characters) with room to spare; the source imposes no limit.
-- Charset/collation are inherited from the table (utf8mb4_unicode_520_ci).

ALTER TABLE `case_file`
    ADD `subject` varchar(500) DEFAULT NULL COMMENT 'PREDM_RIZ of the first own event' AFTER `year`,
    ADD `status` varchar(255) DEFAULT NULL COMMENT 'infosoud "stav"' AFTER `subject`,
    ADD `status_date` date DEFAULT NULL COMMENT 'infosoud "stavDatum"' AFTER `status`,
    ADD `intake_kind` varchar(100) DEFAULT NULL COMMENT 'infosoud "napad"' AFTER `status_date`;
