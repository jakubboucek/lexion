-- Courtroom codelist, keyed by (court, label).
--
-- WHY THIS IS A REAL CODELIST (empirically verified 2026-07-26):
-- The room has no upstream identifier - the label itself IS the identifier, and
-- it is the SAME string in both systems: infoJednani's room codelist entry, the
-- room used in its query, and infoSoud's JED_SIN attribute in a NAR_JED event
-- detail all match byte-for-byte (21/21 distinct (court, label) pairs matched
-- the codelist; 12/12 hearings cross-matched between the two sources with an
-- identical label). So (court_kod, label) is the strongest key we can ever get,
-- and both sources can be joined on it. See docs/infojednani-api.md.
--
-- WHAT IT IS FOR:
-- 1) A stable surrogate id for rooms, so hearings reference a row instead of
--    repeating a 113-char free-text label.
-- 2) Off-site classification, which feeds the hearing -> proceeding court
--    binding: the venue court is only a CANDIDATE home court, and hearings held
--    off site (prison, hospital, "na miste samem", examination elsewhere) are
--    where that guess is least safe. Classification is heuristic (derived from
--    the label) plus manual curation, hence plain stored columns, not generated.
-- 3) Room lifecycle: rooms get renamed, added and retired upstream. `first_seen`
--    / `last_seen` track presence in the codelist, `retired_at` marks a room that
--    disappeared (hearings already linked to it must keep resolving).
CREATE TABLE `hearing_room`
(
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `court_kod`  VARCHAR(10)  NOT NULL,
    -- Verbatim upstream label; also infoSoud's JED_SIN. Never normalise in place:
    -- it is the query parameter and the join key across sources.
    `label`      VARCHAR(255) NOT NULL,
    -- Heuristic classification of the place (curatable):
    --   courtroom = ordinary room in a court building
    --   video     = videoconference room
    --   office    = judge's office / kancelar
    --   prison    = prison / remand prison
    --   hospital  = hospital, psychiatric hospital
    --   onsite    = "na miste samem", local inspection
    --   external  = other place outside the court building
    --   unknown   = not classified yet
    `kind`       VARCHAR(16)  NOT NULL DEFAULT 'unknown',
    -- Denormalised "not a room in this court's building" flag: when set, the
    -- venue court is a weak signal for the case's home court.
    `off_site`   TINYINT(1)   NOT NULL DEFAULT 0,
    `note`       VARCHAR(255) NULL     DEFAULT NULL, -- manual curation remarks
    -- Presence in the upstream codelist.
    `first_seen` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `retired_at` DATETIME     NULL     DEFAULT NULL, -- no longer offered upstream
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Labels are unique within a court (verified across all 96 courts), but NOT
    -- across courts ("na miste samem" exists at 13 of them), so the court is
    -- part of the key.
    UNIQUE KEY `uq_hearing_room` (`court_kod`, `label`),
    KEY `ix_hearing_room_kind` (`kind`),
    KEY `ix_hearing_room_off_site` (`off_site`),
    CONSTRAINT `fk_hearing_room_court` FOREIGN KEY (`court_kod`) REFERENCES `court` (`kod`),
    CONSTRAINT `chk_hearing_room_kind` CHECK (`kind` IN
        ('courtroom', 'video', 'office', 'prison', 'hospital', 'onsite', 'external', 'unknown'))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;

-- Link hearings to the codelist. Nullable and ON DELETE SET NULL: a hearing may
-- reference a room we have not seen in the codelist yet (or one since removed),
-- and `hearing`.`room` keeps the verbatim label either way, so the hearing never
-- depends on the codelist row existing.
ALTER TABLE `hearing`
    ADD COLUMN `room_id` INT UNSIGNED NULL DEFAULT NULL AFTER `room`,
    ADD KEY `ix_hearing_room_id` (`room_id`),
    ADD CONSTRAINT `fk_hearing_room` FOREIGN KEY (`room_id`) REFERENCES `hearing_room` (`id`) ON DELETE SET NULL;
