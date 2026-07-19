-- Directed N:M relations between proceedings (see docs/analyza-udalosti.md).
-- Both endpoints are the case identity tuple (court, registry, senate, number,
-- year) instead of FKs: the target case may not be loaded at all, and PRED_VEC
-- style references may even point outside the courts (prosecutor files) -
-- dst_court_kod is then NULL and dst_registry_norm may be a code missing from
-- the registry codelist. "Bidirectional" browsing = querying both endpoint
-- indexes; no reverse rows are stored.

-- Admin-editable codelist of relation types (pattern of `registry`). Rows are
-- directed src -> dst; `label` describes dst from src's viewpoint and
-- `label_reverse` describes src from dst's viewpoint.
CREATE TABLE `relation_type`
(
    `code`          VARCHAR(20)  NOT NULL,
    `label`         VARCHAR(100) NOT NULL, -- Czech UI label (src -> dst direction)
    `label_reverse` VARCHAR(100) NOT NULL, -- Czech UI label seen from the dst side
    PRIMARY KEY (`code`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;

INSERT INTO `relation_type` (`code`, `label`, `label_reverse`)
VALUES ('PRED_VEC', 'předchozí věc', 'navazující věc'),
       ('NAVAZNA_VEC', 'navazující věc', 'předchozí věc'),
       ('ODVOLANI', 'odvolací řízení', 'řízení I. stupně'),
       ('NAD_RIZENI', 'řízení u nadřízeného soudu', 'řízení u podřízeného soudu'),
       ('DOVOL_RIZ', 'dovolací řízení', 'napadené řízení'),
       ('PREVD_SPIS', 'převedeno pod značku', 'převedeno ze značky'),
       ('SOUVISEJICI', 'související řízení', 'související řízení');

CREATE TABLE `proceeding_relation`
(
    `id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `src_court_kod`     VARCHAR(10)       NOT NULL,
    `src_registry_norm` VARCHAR(10)       NOT NULL,
    `src_senate`        INT UNSIGNED      NOT NULL,
    `src_bc_number`     INT UNSIGNED      NOT NULL,
    `src_year`          SMALLINT UNSIGNED NOT NULL,
    `dst_court_kod`     VARCHAR(10)       NULL     DEFAULT NULL, -- NULL = court unknown (e.g. PRED_VEC without court)
    `dst_registry_norm` VARCHAR(10)       NOT NULL,
    `dst_senate`        INT UNSIGNED      NOT NULL,
    `dst_bc_number`     INT UNSIGNED      NOT NULL,
    `dst_year`          SMALLINT UNSIGNED NOT NULL,
    `relation_type`     VARCHAR(20)       NOT NULL,
    `source`            VARCHAR(16)       NOT NULL, -- 'infosoud' rows are rebuilt by sync; 'manual' rows always survive
    `note`              TEXT              NULL     DEFAULT NULL, -- free text for manual relations
    `created_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- NULL would escape unique enforcement, so the unique key uses a generated
    -- NOT NULL variant of dst_court_kod ('' = unknown court).
    `dst_court_key`     VARCHAR(10) GENERATED ALWAYS AS (IFNULL(`dst_court_kod`, '')) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_relation` (`src_court_kod`, `src_registry_norm`, `src_senate`, `src_bc_number`, `src_year`,
                              `dst_court_key`, `dst_registry_norm`, `dst_senate`, `dst_bc_number`, `dst_year`,
                              `relation_type`, `source`),
    KEY `ix_relation_dst` (`dst_court_kod`, `dst_registry_norm`, `dst_senate`, `dst_bc_number`, `dst_year`),
    CONSTRAINT `fk_relation_type` FOREIGN KEY (`relation_type`) REFERENCES `relation_type` (`code`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
