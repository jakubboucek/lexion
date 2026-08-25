-- Purge "empty" INS case files from the one-off ISIR import.
--
-- Deletes case_file rows in the INS registry that carry no value beyond the
-- bare identity: no infosoud payload, no projected events, no bound or
-- matching hearings, not favorited by any user, no journal entries. These
-- rows are cheap to recover later (one infosoud request each), so thinning
-- them out is safe. Rows failing any guard are kept.
--
-- Ordering: independent of any code deploy, can be run at any time.
-- Idempotent: a second run finds nothing to delete.
-- Take a DB backup (mysqldump) before running.
--
-- Verification - the same count the DELETE will affect:
--   SELECT COUNT(*) FROM case_file cf
--   WHERE cf.registry_norm = 'INS'
--     AND cf.infosoud_json IS NULL
--     AND NOT EXISTS (SELECT 1 FROM case_file_event e WHERE e.case_file_id = cf.id)
--     AND NOT EXISTS (SELECT 1 FROM hearing h WHERE h.case_file_id = cf.id)
--     AND NOT EXISTS (SELECT 1 FROM hearing h WHERE h.venue_court_kod = cf.court_kod
--                       AND h.registry_norm = cf.registry_norm AND h.senate = cf.senate
--                       AND h.bc_number = cf.bc_number AND h.year = cf.year)
--     AND NOT EXISTS (SELECT 1 FROM favorite f WHERE f.case_file_id = cf.id)
--     AND NOT EXISTS (SELECT 1 FROM case_file_journal j WHERE j.case_file_id = cf.id);
--
-- Local dev 2026-08-25: 12,980 INS rows total, 12,866 deletable
-- (kept: 4 with infosoud payload/events, 111 with hearings, 1 favorited).

-- Collect the ids first so every guard is evaluated against a stable snapshot
-- (a multi-table DELETE cannot subquery the table it deletes from anyway).
CREATE TEMPORARY TABLE tmp_purge_ins (
    id INT UNSIGNED NOT NULL PRIMARY KEY
) AS
SELECT cf.id
FROM case_file cf
WHERE cf.registry_norm = 'INS'
  -- no infosoud payload (isir_json alone is recoverable by one request)
  AND cf.infosoud_json IS NULL
  -- no projected events (FK is CASCADE, so guard explicitly)
  AND NOT EXISTS (SELECT 1 FROM case_file_event e WHERE e.case_file_id = cf.id)
  -- no hearing bound by FK...
  AND NOT EXISTS (SELECT 1 FROM hearing h WHERE h.case_file_id = cf.id)
  -- ...nor matching by value (hearing-bind may not have run yet)
  AND NOT EXISTS (
      SELECT 1 FROM hearing h
      WHERE h.venue_court_kod = cf.court_kod
        AND h.registry_norm = cf.registry_norm
        AND h.senate = cf.senate
        AND h.bc_number = cf.bc_number
        AND h.year = cf.year
  )
  -- user data must never silently disappear (FK is RESTRICT anyway)
  AND NOT EXISTS (SELECT 1 FROM favorite f WHERE f.case_file_id = cf.id)
  -- journaled anomalies keep their case row (FK is RESTRICT anyway)
  AND NOT EXISTS (SELECT 1 FROM case_file_journal j WHERE j.case_file_id = cf.id);

DELETE cf
FROM case_file cf
JOIN tmp_purge_ins t ON t.id = cf.id;

DROP TEMPORARY TABLE tmp_purge_ins;

-- After: SELECT COUNT(*) FROM case_file WHERE registry_norm = 'INS';
-- expects only the kept rows (114 on local dev; the guard groups overlap).
