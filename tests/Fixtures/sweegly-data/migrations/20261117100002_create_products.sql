-- migrate:up
CREATE TABLE {{prefix}}products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    brand VARCHAR(100) NULL,
    pack_size DECIMAL(10,3) NULL,
    pack_unit VARCHAR(8) NULL,
    barcode VARCHAR(14) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY {{prefix}}products_barcode_uq (barcode),
    KEY {{prefix}}products_category_idx (category_id),
    KEY {{prefix}}products_name_idx (name),
    CONSTRAINT {{prefix}}products_category_fk FOREIGN KEY (category_id) REFERENCES {{prefix}}categories (id) ON DELETE RESTRICT,
    CONSTRAINT {{prefix}}products_pack_unit_ck CHECK (pack_unit IS NULL OR pack_unit IN ('g', 'kg', 'ml', 'l', 'each')),
    CONSTRAINT {{prefix}}products_barcode_ck CHECK (barcode IS NULL OR barcode REGEXP '^[0-9]{8,14}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down
DROP TABLE {{prefix}}products;
