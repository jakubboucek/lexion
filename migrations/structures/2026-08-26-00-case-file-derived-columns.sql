-- Derived columns of the case file records, so that rendering a page never
-- decodes a raw JSON payload (see docs/architektura.md, *Derivovaná data*).
--
-- One migration, one order - the steps below depend on each other and only
-- make sense as a whole:
--   1. summary columns of case_file,
--   2. hearing columns of case_file_event,
--   3. backfill of both out of the payloads they are derived from,
--   4. removal of the firstEventDetail key the sync used to inject into
--      case_file.infosoud_json.
--
-- RUN BEFORE DEPLOYING THE CODE: the new code selects these columns, so it
-- cannot run without them. The reverse order is harmless the other way round -
-- the old code ignores columns it does not know. Should a case be refreshed by
-- the still-old code in the window between this migration and the deploy, it
-- writes its firstEventDetail copy back; re-run step 4 afterwards if you care,
-- it is idempotent (and the stray key is inert either way).
--
-- Every step is idempotent except the two ALTERs, which fail on a second run
-- because the columns exist - that is the intended signal, not a problem.
--
-- Lengths are deliberately generous. None of these values is a short code:
-- infosoud states them as whole sentences, and we hold no complete codelist
-- to size them against - an intake kind of 124 characters ("Bylo rozhodnuto
-- o nařízení výkonu podmíněného, ...") turned up in production while the
-- widest one in the sample had 70, and JED_SIN is not always a room label
-- either (courts write out the whole venue there). The source column stays
-- the authority, so the cost of a roomy varchar is nothing next to an import
-- aborting on one long sentence. NULL everywhere means "the source does not
-- state it".
--
-- Verification (all three expected to return 0 after the run):
--   SELECT COUNT(*) FROM case_file
--   WHERE infosoud_json IS NOT NULL
--     AND NOT (status <=> NULLIF(NULLIF(TRIM(JSON_VALUE(infosoud_json,'$.stav')),''),'-')
--          AND status_date <=> STR_TO_DATE(JSON_VALUE(infosoud_json,'$.stavDatum'),'%d.%m.%Y')
--          AND intake_kind <=> NULLIF(NULLIF(TRIM(JSON_VALUE(infosoud_json,'$.napad')),''),'-'));
--   SELECT COUNT(*) FROM case_file_event
--   WHERE detail_json IS NOT NULL
--     AND NOT (hearing_room <=> NULLIF(NULLIF(TRIM(JSON_VALUE(detail_json,
--                REPLACE(JSON_UNQUOTE(JSON_SEARCH(detail_json,'one','JED_SIN')),'.typ','.hodnota'))),''),'-'));
--   SELECT COUNT(*) FROM case_file WHERE infosoud_json LIKE '%firstEventDetail%';

-- 1. Case-level summary values: the subject of the proceedings and the
-- overview scalars, all of them read on every case page and dashboard row.

ALTER TABLE `case_file`
    ADD `subject` varchar(1000) DEFAULT NULL COMMENT 'PREDM_RIZ of the first own event' AFTER `year`,
    ADD `status` varchar(500) DEFAULT NULL COMMENT 'infosoud "stav"' AFTER `subject`,
    ADD `status_date` date DEFAULT NULL COMMENT 'infosoud "stavDatum"' AFTER `status`,
    ADD `intake_kind` varchar(500) DEFAULT NULL COMMENT 'infosoud "napad"' AFTER `status_date`;

-- 2. Hearing values of an event, parsed from the JED_* attributes of its
-- detail exactly as InfosoudHearing does. Whether the hearing is cancelled is
-- NOT duplicated here - the row already has `cancelled`.

ALTER TABLE `case_file_event`
    ADD `hearing_at` datetime DEFAULT NULL COMMENT 'JED_D_ZAC - hearing start' AFTER `event_date`,
    ADD `hearing_room` varchar(500) DEFAULT NULL COMMENT 'JED_SIN - court room or free-text venue' AFTER `hearing_at`,
    ADD `hearing_type` varchar(255) DEFAULT NULL COMMENT 'JED_DRUH - kind of hearing' AFTER `hearing_room`;

-- 3a. Overview scalars out of the case payload. JSON_VALUE, not
-- JSON_UNQUOTE(JSON_EXTRACT(...)): the latter renders a JSON null as the
-- literal string 'null', and "napad" is null for most cases. Blank and "-"
-- normalize to NULL, the rule InfosoudEventAttribute::cleanValue applies.

UPDATE `case_file`
SET `status` = NULLIF(NULLIF(TRIM(JSON_VALUE(`infosoud_json`, '$.stav')), ''), '-'),
    `status_date` = STR_TO_DATE(JSON_VALUE(`infosoud_json`, '$.stavDatum'), '%d.%m.%Y'),
    `intake_kind` = NULLIF(NULLIF(TRIM(JSON_VALUE(`infosoud_json`, '$.napad')), ''), '-')
WHERE `infosoud_json` IS NOT NULL;

-- 3b. Subject out of the PREDM_RIZ attribute of the case's FIRST OWN event,
-- read from case_file_event.detail_json - the authoritative home of event
-- details, which is why this does not depend on the key step 4 removes. The
-- first own event is picked as the application picks it: the opening record
-- (ZAHAJ_RIZ) if there is one, otherwise the earliest own record carrying a
-- detail; foreign records (ref_registry_norm IS NOT NULL) never count.

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

-- 3c. Hearing values out of the event details. Rows whose detail has no JED_*
-- attributes are included on purpose: they get NULLs, which is what the write
-- path stores for them. An unparseable JED_D_ZAC yields NULL through
-- STR_TO_DATE, exactly as the PHP parser does.

UPDATE `case_file_event`
SET `hearing_at` = STR_TO_DATE(
        NULLIF(NULLIF(TRIM(JSON_VALUE(
            `detail_json`,
            REPLACE(JSON_UNQUOTE(JSON_SEARCH(`detail_json`, 'one', 'JED_D_ZAC')), '.typ', '.hodnota')
        )), ''), '-'),
        '%d.%m.%Y %H:%i'
    ),
    `hearing_room` = NULLIF(NULLIF(TRIM(JSON_VALUE(
        `detail_json`,
        REPLACE(JSON_UNQUOTE(JSON_SEARCH(`detail_json`, 'one', 'JED_SIN')), '.typ', '.hodnota')
    )), ''), '-'),
    `hearing_type` = NULLIF(NULLIF(TRIM(JSON_VALUE(
        `detail_json`,
        REPLACE(JSON_UNQUOTE(JSON_SEARCH(`detail_json`, 'one', 'JED_DRUH')), '.typ', '.hodnota')
    )), ''), '-')
WHERE `detail_json` IS NOT NULL;

-- 4. Drop the injected firstEventDetail key from the stored payloads. Until
-- now the sync merged the separately fetched detail of the first own event
-- INTO the overview payload, back when there was no case_file_event table to
-- keep it in. The detail has lived on the event row for a long time (step 3b
-- reads it from there), so the copy is redundant - and it broke the promise
-- the column keeps: to be a verbatim snapshot of the overview response.
--
-- Note that MariaDB re-renders the JSON text it rewrites (whitespace and
-- escaping may differ from what we stored); the data is identical, but a
-- byte-exact comparison against an older backup will show the difference.

UPDATE `case_file`
SET `infosoud_json` = JSON_REMOVE(`infosoud_json`, '$.firstEventDetail')
WHERE `infosoud_json` LIKE '%firstEventDetail%';
