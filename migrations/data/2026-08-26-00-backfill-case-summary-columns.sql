-- Backfill of the case-level summary columns added by
-- structures/2026-08-26-00 (subject, status, status_date, intake_kind).
--
-- Recomputes exactly what the write path will store from now on:
--   * status / status_date / intake_kind come from the case overview
--     (case_file.infosoud_json), blank and "-" normalized to NULL - the same
--     rule InfosoudEventAttribute::cleanValue applies;
--   * subject is the PREDM_RIZ attribute of the case's FIRST OWN event, read
--     from case_file_event.detail_json. That table is the authoritative home
--     of event details, so this does not depend on the firstEventDetail key
--     that data/2026-08-26-02 strips out of the raw payload afterwards.
--
-- The first own event is picked exactly as the application does: the opening
-- record (ZAHAJ_RIZ) if there is one, otherwise the earliest own record that
-- has a detail; foreign records (ref_registry_norm IS NOT NULL) never count.
--
-- JSON_VALUE, not JSON_UNQUOTE(JSON_EXTRACT(...)): the latter renders a JSON
-- null as the literal string 'null' ("napad" is null for most cases).
--
-- Idempotent - a second run recomputes the same values. Safe to run before or
-- after deploying the code; run it again after the deploy if a sync happened
-- in the window (a synced case would already carry its own values).
--
-- Verification (expected: 0 rows, i.e. no case disagrees with its payload):
--   SELECT COUNT(*) FROM case_file
--   WHERE infosoud_json IS NOT NULL
--     AND NOT (status <=> NULLIF(NULLIF(TRIM(JSON_VALUE(infosoud_json,'$.stav')),''),'-')
--          AND status_date <=> STR_TO_DATE(JSON_VALUE(infosoud_json,'$.stavDatum'),'%d.%m.%Y')
--          AND intake_kind <=> NULLIF(NULLIF(TRIM(JSON_VALUE(infosoud_json,'$.napad')),''),'-'));

UPDATE `case_file`
SET `status` = NULLIF(NULLIF(TRIM(JSON_VALUE(`infosoud_json`, '$.stav')), ''), '-'),
    `status_date` = STR_TO_DATE(JSON_VALUE(`infosoud_json`, '$.stavDatum'), '%d.%m.%Y'),
    `intake_kind` = NULLIF(NULLIF(TRIM(JSON_VALUE(`infosoud_json`, '$.napad')), ''), '-')
WHERE `infosoud_json` IS NOT NULL;

UPDATE `case_file` `c`
SET `c`.`subject` = (
    SELECT NULLIF(NULLIF(TRIM(JSON_VALUE(
        `e`.`detail_json`,
        REPLACE(JSON_UNQUOTE(JSON_SEARCH(`e`.`detail_json`, 'one', 'PREDM_RIZ')), '.typ', '.hodnota')
    )), ''), '-')
    FROM `case_file_event` `e`
    WHERE `e`.`case_file_id` = `c`.`id`
      AND `e`.`source` = 'infosoud'
      AND `e`.`ref_registry_norm` IS NULL
      AND `e`.`detail_json` IS NOT NULL
    ORDER BY (`e`.`event_code` = 'ZAHAJ_RIZ') DESC, `e`.`event_date`, `e`.`event_order`
    LIMIT 1
)
WHERE EXISTS (
    SELECT 1 FROM `case_file_event` `e`
    WHERE `e`.`case_file_id` = `c`.`id`
      AND `e`.`source` = 'infosoud'
      AND `e`.`ref_registry_norm` IS NULL
      AND `e`.`detail_json` IS NOT NULL
);
