-- Data migration: drops the invented court of a predecessor case.
--
-- Until 4ac38c1 the projection filled proceeding_relation.dst_court_kod of a
-- PRED_VEC relation with the SOURCE case's court whenever the cache could not
-- identify the referenced case. That reads plausibly for a civil case converted
-- from an electronic payment order (same court), but it is provably wrong for
-- an appeal: 12 Co 130/2019 at MS Praha then claimed its predecessor
-- 29 C 139/2017 sat at MS Praha too, when an appeal by definition reviews a
-- subordinate court's case. Infosoud itself renders the attribute as plain text
-- for that very reason - the court is simply unknown there.
--
-- This recomputes the court exactly as the fixed projection does: the unique
-- cache match across all courts, or NULL. Idempotent - a second run changes
-- nothing. Scoped to PRED_VEC rows of the infosoud projection, so relations
-- whose court comes from upstream (and any manual ones) are left alone.
--
-- Written as SQL, not the usual PHP script, because only web/ is deployed:
-- migrations/ does not exist on the production host, so this has to be
-- runnable straight from Adminer. Apply AFTER deploying the code, otherwise a
-- sync in between writes the guess back.
--
-- Verification (expected: no rows after the update; on dev it changed 16 of 34):
--   SELECT r.id, IFNULL(r.dst_court_kod,'(NULL)') AS now,
--          IFNULL(IF(c.n = 1, c.kod, NULL),'(NULL)') AS expected
--   FROM proceeding_relation r
--   LEFT JOIN (SELECT registry_norm, senate, bc_number, year, COUNT(*) n, MIN(court_kod) kod
--              FROM proceeding GROUP BY registry_norm, senate, bc_number, year) c
--     ON c.registry_norm = r.dst_registry_norm AND c.senate = r.dst_senate
--    AND c.bc_number = r.dst_bc_number AND c.year = r.dst_year
--   WHERE r.relation_type = 'PRED_VEC' AND r.source = 'infosoud'
--     AND NOT (r.dst_court_kod <=> IF(c.n = 1, c.kod, NULL));

UPDATE `proceeding_relation` r
    LEFT JOIN (
        SELECT `registry_norm`, `senate`, `bc_number`, `year`,
               COUNT(*) AS n, MIN(`court_kod`) AS kod
        FROM `proceeding`
        GROUP BY `registry_norm`, `senate`, `bc_number`, `year`
    ) c ON c.`registry_norm` = r.`dst_registry_norm`
       AND c.`senate` = r.`dst_senate`
       AND c.`bc_number` = r.`dst_bc_number`
       AND c.`year` = r.`dst_year`
SET r.`dst_court_kod` = IF(c.n = 1, c.kod, NULL)
WHERE r.`relation_type` = 'PRED_VEC'
  AND r.`source` = 'infosoud';
