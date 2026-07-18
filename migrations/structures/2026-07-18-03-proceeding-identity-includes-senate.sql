-- Fix: the senate IS part of the case identity. Empirically verified on
-- infosoud: OS Trutnov has distinct cases "6 C 1/2023", "7 C 1/2023",
-- "9 C 1/2023" and "30 C 1/2023" at the same time, so the case number series
-- is not unique within (court, registry, year) alone.
ALTER TABLE `proceeding`
    DROP INDEX `uq_proceeding_case`,
    ADD UNIQUE KEY `uq_proceeding_case` (`court_kod`, `registry_norm`, `senate`, `bc_number`, `year`);
