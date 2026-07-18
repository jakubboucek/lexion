-- Soft cache of court proceedings harvested from justice sources. Deliberately
-- schema-light while the upstream data shapes are still being explored: only
-- the case-identity search keys live in columns, everything else goes into
-- per-source native JSON columns (MariaDB json_valid-checked LONGTEXT with
-- full JSON_* function support). Will be re-migrated once structures settle.
--
-- Case identity is (court, registry, number, year) - the number series runs
-- per registry/year across all senates of one court, so the senate is an
-- attribute (it may even change when a case is reassigned), not identity.
CREATE TABLE `proceeding`
(
    `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `court_kod`     VARCHAR(10)       NOT NULL,
    `registry_norm` VARCHAR(10)       NOT NULL,
    `senate`        INT UNSIGNED      NOT NULL,
    `bc_number`     INT UNSIGNED      NOT NULL,
    `year`          SMALLINT UNSIGNED NOT NULL,
    `infosoud_json` JSON              NULL DEFAULT NULL,
    `infosoud_at`   DATETIME          NULL DEFAULT NULL,
    `isir_json`     JSON              NULL DEFAULT NULL,
    `isir_at`       DATETIME          NULL DEFAULT NULL,
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_proceeding_case` (`court_kod`, `registry_norm`, `bc_number`, `year`),
    KEY `ix_proceeding_registry_year` (`registry_norm`, `year`),
    CONSTRAINT `fk_proceeding_court` FOREIGN KEY (`court_kod`) REFERENCES `court` (`kod`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
