-- Timeline events of a proceeding, projected from the per-source raw JSON on
-- every sync (see docs/analyza-udalosti.md). Two-phase rows: the case overview
-- creates "thin" rows (code, order, date, cancelled, owner ref); the detail is
-- lazily fetched into detail_json on first view. Completeness signalling:
--   detail_fetched_at IS NULL                     -> thin row (never fetched)
--   detail_fetched_at set, detail_json IS NULL    -> fetched, upstream has none
--   both set                                      -> full row
--
-- event_order is the upstream `poradi`: a per-case record sequence on the
-- court side - NOT persistent over time and, for foreign events (appeals...),
-- taken from the FOREIGN case's sequence (ref_* columns identify that case,
-- no FK - the foreign case may not be loaded). URLs and internal references
-- use only the surrogate id; event_order serves sync pairing and ordering.
CREATE TABLE `proceeding_event`
(
    `id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `proceeding_id`     INT UNSIGNED      NOT NULL,
    `source`            VARCHAR(16)       NOT NULL DEFAULT 'infosoud',
    `event_code`        VARCHAR(16)       NOT NULL,
    `event_order`       INT UNSIGNED      NULL     DEFAULT NULL,
    `upstream_id`       VARCHAR(32)       NULL     DEFAULT NULL, -- udalostId (CEPR only, may be composite "id;sub")
    `event_date`        DATE              NULL     DEFAULT NULL,
    `cancelled`         TINYINT(1)        NOT NULL DEFAULT 0,
    `ref_court_kod`     VARCHAR(10)       NULL     DEFAULT NULL, -- owner case of a foreign event (NULL = own event)
    `ref_registry_norm` VARCHAR(10)       NULL     DEFAULT NULL,
    `ref_senate`        INT UNSIGNED      NULL     DEFAULT NULL,
    `ref_bc_number`     INT UNSIGNED      NULL     DEFAULT NULL,
    `ref_year`          SMALLINT UNSIGNED NULL     DEFAULT NULL,
    `detail_json`       JSON              NULL     DEFAULT NULL,
    `detail_fetched_at` DATETIME          NULL     DEFAULT NULL,
    `created_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Uniqueness guard for OWN events only: foreign events carry poradi from
    -- another sequence and may collide, so they must escape the constraint
    -- (generated column is NULL for them and NULLs escape unique enforcement).
    `own_event_order`   INT UNSIGNED GENERATED ALWAYS AS (IF(`ref_court_kod` IS NULL, `event_order`, NULL)) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_own` (`proceeding_id`, `source`, `event_code`, `own_event_order`),
    KEY `ix_event_timeline` (`proceeding_id`, `event_date`, `event_order`),
    CONSTRAINT `fk_event_proceeding` FOREIGN KEY (`proceeding_id`) REFERENCES `proceeding` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
