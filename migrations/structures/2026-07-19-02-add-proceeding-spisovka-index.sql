-- Court-less lookup by the file number itself (registry, senate, number, year):
-- used by the live validation to preselect the court from the cache. The
-- unique key starts with court_kod, so it cannot serve this query.
ALTER TABLE `proceeding`
    ADD INDEX `idx_proceeding_spisovka` (`registry_norm`, `senate`, `bc_number`, `year`);
