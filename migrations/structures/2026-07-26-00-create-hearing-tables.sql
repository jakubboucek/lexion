-- Court hearings, aggregated across sources (infoJednani now, infoSoud later).
-- A hearing is a scheduled/held session of a case in a courtroom on a given
-- day and time. Kept as a merged projection (`hearing`) fed from raw per-source
-- observations (`hearing_observation`), mirroring the proceeding raw+projection
-- pattern so the projection can be rebuilt from observations at any time.
--
-- WHY SEPARATE FROM `proceeding`:
-- infoJednani lists "all" hearings but only for a rolling 30-day window (our
-- own limit) and is very request-heavy/slow; infoSoud scans only the handful of
-- tracked cases but reaches far into the future AND the past, and carries
-- metadata infoJednani cannot (subject, past results). The same hearing may
-- therefore arrive from both sources with complementary fields, so it lives in
-- its own entity that several sources contribute to.
--
-- COURT ATTRIBUTION (soft link, see docs/infojednani-api.md):
-- From infoJednani we know only the court of the ROOM (= venue), not the case's
-- home court. For the vast majority venue == home court, but hearings held off
-- site (prison, hospital, "na miste samem", examination by request) may not be.
-- `venue_court_kod` is therefore a CANDIDATE home court; `proceeding_id` and
-- `court_binding = 'confirmed'` are set only once corroborated (e.g. infoSoud
-- reports the same hearing at the same time and place). The case-identity
-- columns are denormalised so a hearing can be recorded and searched even when
-- no matching `proceeding` row exists yet (infoJednani sees ~34k cases, the
-- cache holds far fewer).
CREATE TABLE `hearing`
(
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    -- Soft link to the tracked case; NULL until a home-court match is known.
    `proceeding_id`   INT UNSIGNED      NULL     DEFAULT NULL,
    -- Case identity as seen from the hearing (venue court = candidate home court).
    `venue_court_kod` VARCHAR(10)       NOT NULL,
    `registry_norm`   VARCHAR(10)       NOT NULL,
    `senate`          INT UNSIGNED      NOT NULL,          -- `cislo`; may be 0
    `bc_number`       INT UNSIGNED      NOT NULL,          -- `bcVec`
    `year`            SMALLINT UNSIGNED NOT NULL,          -- `rocnik`, always 4-digit (importer expands 2-digit upstream years via CaseYear::fromUpstream(); raw value stays in hearing_observation.raw_json)
    -- Schedule.
    `hearing_date`    DATE              NOT NULL,
    `hearing_time`    TIME              NOT NULL,          -- `cas` "HH:MM" (synthetic for some non-public sessions)
    -- Merged attributes (from infoJednani for now; codelists may follow).
    `room`            VARCHAR(255)      NULL     DEFAULT NULL, -- free-text label; primary room (rarely a hearing shows in 2 rooms)
    `hearing_type`    VARCHAR(64)       NULL     DEFAULT NULL, -- `druhJednani` (e.g. "Hlavni liceni", "Vyhlaseni rozsudku")
    `judge`           VARCHAR(128)      NULL     DEFAULT NULL, -- `resitel`
    `cancelled`       TINYINT(1)        NOT NULL DEFAULT 0,    -- `jednaniZruseno` = "Ano"
    `non_public`      TINYINT(1)        NOT NULL DEFAULT 0,    -- `neverejneJednani` = "Ano"
    `result`          VARCHAR(255)      NULL     DEFAULT NULL, -- `vysledek` (odroceno/odvolano/rozsudek/smir/...)
    -- Strength of the court/case binding.
    `court_binding`   VARCHAR(16)       NOT NULL DEFAULT 'venue_guess', -- 'venue_guess' (from room) | 'confirmed' (cross-checked)
    -- Bookkeeping. `created_at` is the first-seen time; `last_seen_at` is bumped
    -- on every observation from any source (even if data is unchanged) = "still
    -- scheduled as of". Per-source freshness lives in `hearing_observation`.
    `last_seen_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One hearing = one case at one date+time. The room is NOT part of identity:
    -- a case cannot sit in two rooms at once, so the (rare) same-hearing-in-two-
    -- rooms listings collapse to one row (extra rooms are kept as observations).
    UNIQUE KEY `uq_hearing` (`venue_court_kod`, `registry_norm`, `senate`, `bc_number`, `year`, `hearing_date`, `hearing_time`),
    -- Court-less lookup by the file number (for HP court preselect from a spisovka).
    KEY `ix_hearing_spisovka` (`registry_norm`, `senate`, `bc_number`, `year`),
    KEY `ix_hearing_date` (`hearing_date`),
    KEY `ix_hearing_proceeding` (`proceeding_id`),
    CONSTRAINT `fk_hearing_court` FOREIGN KEY (`venue_court_kod`) REFERENCES `court` (`kod`),
    -- No cascade delete of hearing data when a cached proceeding is removed; just
    -- drop the link so it can be re-bound later.
    CONSTRAINT `fk_hearing_proceeding` FOREIGN KEY (`proceeding_id`) REFERENCES `proceeding` (`id`) ON DELETE SET NULL,
    CONSTRAINT `chk_hearing_binding` CHECK (`court_binding` IN ('venue_guess', 'confirmed'))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;

-- Raw per-source observations of a hearing. Lets the `hearing` projection be
-- rebuilt and preserves what each source said and when. One row per distinct
-- (hearing, source, observation time, room) so re-importing the same scan is
-- idempotent, while a hearing seen in two rooms keeps both observations.
CREATE TABLE `hearing_observation`
(
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hearing_id`  INT UNSIGNED NOT NULL,
    `source`      VARCHAR(16)  NOT NULL,                  -- 'infojednani' | 'infosoud'
    `observed_at` DATETIME     NOT NULL,                  -- infoJednani `platneK` / infoSoud fetch time
    `room`        VARCHAR(255) NULL     DEFAULT NULL,     -- room as seen in this observation (query context)
    `raw_json`    JSON         NULL     DEFAULT NULL,     -- the source event object, verbatim
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hearing_observation` (`hearing_id`, `source`, `observed_at`, `room`),
    KEY `ix_hearing_observation_hearing` (`hearing_id`),
    CONSTRAINT `fk_hearing_observation_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearing` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
