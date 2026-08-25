-- Hearing values materialized out of the event detail payload.
--
-- The case timeline shows the time, room and kind of every scheduled hearing;
-- it used to decode case_file_event.detail_json for each NAR_JED row while
-- rendering. These columns hold the same three values, parsed once at write
-- time from the JED_* attributes (JED_D_ZAC, JED_SIN, JED_DRUH) exactly as
-- InfosoudHearing does. The event page keeps reading detail_json - the full
-- attribute list is too variable to model in columns.
--
-- NULL means the detail is not fetched yet, carries no JED_* attributes, or
-- states the value as blank / "-". Whether the hearing is cancelled is NOT
-- duplicated here: the event row already has `cancelled`.
--
-- JED_SIN is not always a room label: courts also write a whole sentence
-- there ("umístěné v Psychiatrické nemocnici Bohnice (Ústavní 91, Praha 8),
-- oddělení 36, jednací místnost č. 2.25/ I. patro" - 113 characters in the
-- current data), hence 255 rather than a room-sized column.

ALTER TABLE `case_file_event`
    ADD `hearing_at` datetime DEFAULT NULL COMMENT 'JED_D_ZAC - hearing start' AFTER `event_date`,
    ADD `hearing_room` varchar(255) DEFAULT NULL COMMENT 'JED_SIN - court room or free-text venue' AFTER `hearing_at`,
    ADD `hearing_type` varchar(100) DEFAULT NULL COMMENT 'JED_DRUH - kind of hearing' AFTER `hearing_room`;
