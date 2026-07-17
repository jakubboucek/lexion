-- Users that may log into the app (watch lists and notification channels
-- will reference this table). Passwords are bcrypt hashes.
CREATE TABLE `user`
(
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(255)    NOT NULL,
    `password`   VARCHAR(255)    NOT NULL,
    `nick`       VARCHAR(100)    NOT NULL,
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_email` (`email`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
