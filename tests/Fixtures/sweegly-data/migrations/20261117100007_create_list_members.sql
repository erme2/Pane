-- migrate:up
CREATE TABLE {{prefix}}list_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    membership_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY {{prefix}}list_members_list_membership_uq (list_id, membership_id),
    KEY {{prefix}}list_members_membership_idx (membership_id),
    CONSTRAINT {{prefix}}list_members_list_fk FOREIGN KEY (list_id) REFERENCES {{prefix}}lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down
DROP TABLE {{prefix}}list_members;
