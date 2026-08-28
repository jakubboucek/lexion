-- Ledger of systematically scanned number-series blocks (docs/navrh-sken-rad.md).
-- One row per scanned block = a contiguous number range within a case-number
-- series (court, registry, senate, year) starting at number_from. A senate
-- series may hold more than one block (offset bands, see Nc/Nt), so number_from
-- is part of the identity - two bands of one senate are two independent rows,
-- never one overwriting the other.
--
-- The row records THAT a scan ran (scanned_at) and, ONLY once the scanner
-- confirmed it by its rules, the series end (number_confirmed_end/confirmed_at).
-- A run stopped early or capped by a hard `to` writes just scanned_at and
-- leaves the end NULL - the ledger never stores a guessed conclusion. The
-- highest known case number is intentionally NOT duplicated here (it derives
-- from case_file); a later case_file row numbered above number_confirmed_end
-- then reveals from the data alone that the scan stopped short of a big hole.
--
-- Number columns carry a number_ prefix so they cannot be misread as instants.
CREATE TABLE `case_series_scan`
(
    `id`                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `court_kod`            VARCHAR(10)       NOT NULL,
    `registry_norm`        VARCHAR(10)       NOT NULL,
    `senate`               INT UNSIGNED      NOT NULL,
    `year`                 SMALLINT UNSIGNED NOT NULL,
    `number_from`          INT UNSIGNED      NOT NULL DEFAULT 1,
    `number_confirmed_end` INT UNSIGNED      NULL     DEFAULT NULL,
    `confirmed_at`         DATETIME          NULL     DEFAULT NULL,
    `scanned_at`           DATETIME          NOT NULL,
    `created_at`           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_series_scan` (`court_kod`, `registry_norm`, `senate`, `year`, `number_from`),
    CONSTRAINT `fk_case_series_scan_court` FOREIGN KEY (`court_kod`) REFERENCES `court` (`kod`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
