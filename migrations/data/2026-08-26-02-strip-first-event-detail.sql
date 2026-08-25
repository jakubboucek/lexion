-- Drops the injected firstEventDetail key from the stored overview payloads.
--
-- Until 2026-08-26 the sync merged the separately fetched detail of the first
-- own event INTO the overview payload before storing it, back when there was
-- no case_file_event table to keep it in. The detail has lived on the event
-- row for a long time now (and is seeded there by the same sync), so the copy
-- is redundant - and it broke the promise the column is supposed to keep: to
-- be a verbatim snapshot of what the overview endpoint answered. Nothing
-- reads the key any more.
--
-- RUN AFTER DEPLOYING THE CODE that stops writing it (otherwise the next
-- refresh of a case puts its copy back - harmless, just pointless).
--
-- Idempotent: JSON_REMOVE on a payload without the key returns it unchanged.
-- Note that MariaDB re-renders the JSON text it rewrites (whitespace and
-- escaping may differ from what we stored); the data is identical, but a
-- byte-exact comparison against an old backup will show the difference.
--
-- Verification (expected: 0):
--   SELECT COUNT(*) FROM case_file WHERE infosoud_json LIKE '%firstEventDetail%';

UPDATE `case_file`
SET `infosoud_json` = JSON_REMOVE(`infosoud_json`, '$.firstEventDetail')
WHERE `infosoud_json` LIKE '%firstEventDetail%';
