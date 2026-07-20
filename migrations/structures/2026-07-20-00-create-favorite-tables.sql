-- Per-user favorite proceedings: a user bookmarks cached cases, optionally
-- names them, orders them manually and files them into manually ordered
-- groups. Ordering is per bucket (a group, or the ungrouped section =
-- group_id NULL) and rows are renumbered 1..n on every mutation.

CREATE TABLE `favorite_group`
(
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(100) NOT NULL,
    `position`   INT UNSIGNED NOT NULL, -- manual order within the user, 1..n
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_favorite_group_name` (`user_id`, `name`),
    KEY `ix_favorite_group_order` (`user_id`, `position`),
    CONSTRAINT `fk_favorite_group_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;

CREATE TABLE `favorite`
(
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `proceeding_id` INT UNSIGNED NOT NULL,
    `group_id`      INT UNSIGNED NULL     DEFAULT NULL, -- NULL = ungrouped section
    `name`          VARCHAR(255) NULL     DEFAULT NULL, -- user's custom case name
    `position`      INT UNSIGNED NOT NULL,              -- manual order within the (user, group) bucket
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_favorite_case` (`user_id`, `proceeding_id`),
    KEY `ix_favorite_order` (`user_id`, `group_id`, `position`),
    CONSTRAINT `fk_favorite_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
    -- No cascade: favorites are user data and must not vanish silently when
    -- a cached proceeding row would be deleted.
    CONSTRAINT `fk_favorite_proceeding` FOREIGN KEY (`proceeding_id`) REFERENCES `proceeding` (`id`),
    -- Safety net only; the app moves the group's favorites to the ungrouped
    -- bucket before deleting the group.
    CONSTRAINT `fk_favorite_group` FOREIGN KEY (`group_id`) REFERENCES `favorite_group` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_520_ci;
