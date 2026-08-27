-- Documented deterministic misses of case lookups (docs/navrh-sken-rad.md).
-- One row per case identity that an upstream source deterministically did not
-- answer: not found (HTTP 400), a validation refusal (e.g. Nc at a regional
-- court), or a response refused on a year mismatch (the pre-2000 two-digit
-- trap). Transient failures (outage, timeout) are NEVER recorded here - they
-- go to the application log for monitoring.
--
-- A miss row is information, not a verdict: whether it is permanent is decided
-- by the reader at query time (see CaseLookupMissRepository::isPermanent) -
-- a not_found is permanent only once it was verified in a calendar year later
-- than the case's vintage year, or once a confirmed case with a higher number
-- exists in the same series (the series has provably grown past the hole).
-- A later successful fetch of the identity deletes the row (and logs the
-- resolution - a hole that fills in a closed vintage is a formerly non-public
-- case going public).
--
-- Deliberately no FK to case_file (the whole point is that no case exists) and
-- a separate table rather than a flag: case_file stays a registry of existing
-- cases only, and nothing reading it needs to know about misses.
CREATE TABLE `case_lookup_miss`
(
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `court_kod`        VARCHAR(10)       NOT NULL,
    `registry_norm`    VARCHAR(10)       NOT NULL,
    `senate`           INT UNSIGNED      NOT NULL,
    `bc_number`        INT UNSIGNED      NOT NULL,
    `year`             SMALLINT UNSIGNED NOT NULL,
    `source`           VARCHAR(16)       NOT NULL DEFAULT 'infosoud',
    `outcome`          VARCHAR(32)       NOT NULL,
    `attempts`         INT UNSIGNED      NOT NULL DEFAULT 1,
    `first_attempt_at` DATETIME          NOT NULL,
    `last_attempt_at`  DATETIME          NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_lookup_miss` (`source`, `court_kod`, `registry_norm`, `senate`, `bc_number`, `year`),
    KEY `idx_case_lookup_miss_series` (`court_kod`, `registry_norm`, `year`, `senate`, `bc_number`),
    CONSTRAINT `fk_case_lookup_miss_court` FOREIGN KEY (`court_kod`) REFERENCES `court` (`kod`),
    CONSTRAINT `chk_case_lookup_miss_outcome` CHECK (`outcome` IN ('not_found', 'rejected', 'year_mismatch'))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
