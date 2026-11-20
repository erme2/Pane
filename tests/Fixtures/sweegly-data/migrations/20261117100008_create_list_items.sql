-- migrate:up
CREATE TABLE {{prefix}}list_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id BIGINT UNSIGNED NOT NULL,
    text VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,3) NULL,
    unit VARCHAR(16) NULL,
    note VARCHAR(500) NULL,
    checked_at DATETIME(3) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    category_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY {{prefix}}list_items_list_position_idx (list_id, position),
    KEY {{prefix}}list_items_product_idx (product_id),
    KEY {{prefix}}list_items_category_idx (category_id),
    CONSTRAINT {{prefix}}list_items_list_fk FOREIGN KEY (list_id) REFERENCES {{prefix}}lists (id) ON DELETE CASCADE,
    CONSTRAINT {{prefix}}list_items_category_fk FOREIGN KEY (category_id) REFERENCES {{prefix}}categories (id) ON DELETE RESTRICT,
    CONSTRAINT {{prefix}}list_items_product_fk FOREIGN KEY (product_id) REFERENCES {{prefix}}products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down
DROP TABLE {{prefix}}list_items;
