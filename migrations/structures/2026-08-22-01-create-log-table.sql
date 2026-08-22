-- General application log (docs/navrh-logovani.md): one table for both record
-- kinds. An instant record is inserted once in its final state ('ok'/'failed')
-- and never changes. A run record is inserted as 'pending' at start and gets a
-- single UPDATE at finish (outcome, summary payload, surviving files); between
-- the two the DB is not touched - progress goes to append-only files in the
-- application log directory, listed in `files`.
--
-- `files` maps a channel meaning ('out', 'problems', ...) to a filename
-- relative to the log directory; a NULL value means the channel existed but
-- stayed empty and its file was deleted at finish. status = 'pending' is the
-- only marker of "running or crashed" - automatic crash detection is
-- deliberately not built yet. Extending the status set = ALTER TABLE ...
-- MODIFY with the new value APPENDED to the list (metadata-only change).
--
-- user_id has no FK on purpose: the log is append-only evidence and must not
-- constrain (or be constrained by) anything; CLI runs have no user at all.
-- No secondary indexes yet - they will follow the actual filtering needs of
-- the future read side. Times are whole-second DATETIME like the rest of the
-- schema (the nette/database MySQL driver writes no fraction anyway): row
-- order is guaranteed by `id`, millisecond timestamps live on the file lines.
CREATE TABLE `log`
(
    `id`          INT UNSIGNED                     NOT NULL AUTO_INCREMENT,
    `resource`    VARCHAR(30)                      NOT NULL,
    `action`      VARCHAR(100)                     NOT NULL,
    `target`      VARCHAR(100)                     NULL     DEFAULT NULL,
    `status`      ENUM ('pending', 'ok', 'failed') NOT NULL,
    `result`      VARCHAR(100)                     NULL     DEFAULT NULL,
    `message`     VARCHAR(1000)                    NULL     DEFAULT NULL,
    `user_id`     INT UNSIGNED                     NULL     DEFAULT NULL,
    `data`        JSON                             NULL     DEFAULT NULL,
    `context`     JSON                             NULL     DEFAULT NULL,
    `result_data` JSON                             NULL     DEFAULT NULL,
    `files`       JSON                             NULL     DEFAULT NULL,
    `occurred_at` DATETIME                         NOT NULL,
    `finished_at` DATETIME                         NULL     DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
