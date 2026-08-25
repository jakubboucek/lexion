-- Backfill of the hearing columns added by structures/2026-08-26-01
-- (hearing_at, hearing_room, hearing_type).
--
-- Parses the same three JED_* attributes out of case_file_event.detail_json
-- that InfosoudHearing::fromEventDetail reads: JED_D_ZAC (date and time in one
-- value, "28.07.2026 08:30"), JED_SIN and JED_DRUH. Blank and "-" normalize to
-- NULL, mirroring InfosoudEventAttribute::cleanValue; an unparseable
-- JED_D_ZAC yields NULL through STR_TO_DATE, exactly as the PHP parser does.
--
-- Scoped to rows that actually carry a detail, so thin rows keep their NULLs.
-- Rows whose detail has no JED_* attributes are included on purpose: the
-- columns are set to NULL, which is what the write path stores for them.
--
-- Idempotent - a second run recomputes the same values.
--
-- Verification (expected: 0 rows):
--   SELECT COUNT(*) FROM case_file_event
--   WHERE detail_json IS NOT NULL
--     AND NOT (hearing_room <=> NULLIF(NULLIF(TRIM(JSON_VALUE(detail_json,
--                REPLACE(JSON_UNQUOTE(JSON_SEARCH(detail_json,'one','JED_SIN')),'.typ','.hodnota'))),''),'-'));

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
