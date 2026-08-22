-- Journal of data-loss anomalies on case files (docs/architektura.md, section
-- "Zurnal ztrat dat"). One row per situation where data was destroyed, lost or
-- refused: a projection run that dropped events/details/relations, an upstream
-- event detail rejected as describing a different record (renumbering), a case
-- response refused on a year mismatch, an unreadable stored payload.
--
-- state_before/state_after are full JSON snapshots of the case's state (the
-- case_file row + all its case_file_event and case_file_relation rows),
-- serialized by the Hydrator Json format - field names already use the target
-- domain naming (caseFileId, ...), so they never need migrating. They exist to
-- make the lost state fully reconstructible later, even after further changes
-- to the live rows; restore tooling is deliberately not implemented yet.
-- context carries what the snapshots cannot: the refused upstream payload and
-- the list of destructive operations.
--
-- case_file_id is NULL only when the anomaly has no local case row (a refused
-- response for a case never stored). No CASCADE: journal rows are evidence and
-- must block a case_file delete rather than vanish with it.
CREATE TABLE `case_file_journal`
(
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_file_id` INT UNSIGNED NULL     DEFAULT NULL,
    `type`         VARCHAR(32)  NOT NULL,
    `occurred_at`  DATETIME     NOT NULL,
    `state_before` JSON         NULL     DEFAULT NULL,
    `state_after`  JSON         NULL     DEFAULT NULL,
    `context`      JSON         NULL     DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_case_file_journal_case` (`case_file_id`, `occurred_at`),
    CONSTRAINT `fk_case_file_journal_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_file` (`id`),
    CONSTRAINT `chk_case_file_journal_type` CHECK (`type` IN (
        'projection_data_loss', 'event_detail_rejected', 'case_response_rejected', 'payload_unreadable'))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
