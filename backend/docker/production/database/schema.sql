-- ChupaCabra 2.0 - MariaDB 11.8; USE o banco definido em DB_NAME.
CREATE TABLE IF NOT EXISTS supermercados (
 alias VARCHAR(100) PRIMARY KEY, name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS url_search (
 uid CHAR(36) NOT NULL PRIMARY KEY, url TEXT NOT NULL, categoria VARCHAR(100) NOT NULL,
 supermercado VARCHAR(100) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
 KEY idx_source_market (supermercado,enabled),
 CONSTRAINT fk_sources_market FOREIGN KEY (supermercado) REFERENCES supermercados(alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS data_confianca (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 titulo VARCHAR(254) NOT NULL, preco DECIMAL(16,3) NOT NULL,
 categoria VARCHAR(100) NOT NULL, imgUrl TEXT NULL,
 KEY idx_title (titulo), KEY idx_category_title (categoria,titulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS data_tauste LIKE data_confianca;
CREATE TABLE IF NOT EXISTS saved (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 titulo VARCHAR(254) NOT NULL, categoria VARCHAR(100) NOT NULL,
 qtd DECIMAL(16,3) NOT NULL DEFAULT 1, preco DECIMAL(16,3) NOT NULL DEFAULT 0,
 supermercado VARCHAR(100) NOT NULL, dataHora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 oculto TINYINT(1) NOT NULL DEFAULT 0, observacao VARCHAR(500) NULL,
 imgUrl TEXT NULL,
 KEY idx_saved_market (supermercado), KEY idx_saved_category (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Compras encerradas são registros definitivos; alterações na lista ou nas raspagens
-- não afetam os preços, quantidades e totais salvos aqui.
CREATE TABLE IF NOT EXISTS purchase_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 recorded_by VARCHAR(80) NOT NULL,
 item_count SMALLINT UNSIGNED NOT NULL,
 estimated_total DECIMAL(16,2) NOT NULL,
 actual_total DECIMAL(16,2) NOT NULL,
 note VARCHAR(250) NULL,
 KEY idx_purchase_completed (completed_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS purchase_history_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 purchase_id BIGINT UNSIGNED NOT NULL,
 saved_item_id INT NULL,
 titulo VARCHAR(254) NOT NULL,
 categoria VARCHAR(100) NOT NULL,
 supermercado VARCHAR(100) NOT NULL,
 observacao VARCHAR(500) NULL,
 quantidade DECIMAL(16,3) NOT NULL,
 estimated_unit_price DECIMAL(16,3) NOT NULL,
 estimated_subtotal DECIMAL(16,2) NOT NULL,
 CONSTRAINT fk_purchase_history_items FOREIGN KEY (purchase_id) REFERENCES purchase_history(id) ON DELETE CASCADE,
 KEY idx_purchase_items_history (purchase_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scrape_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_uid CHAR(36) NOT NULL, supermercado VARCHAR(100) NOT NULL,
 batch_key VARCHAR(64) NULL,
 categoria VARCHAR(100) NOT NULL, tipo ENUM('daily','manual') NOT NULL,
 schedule_key VARCHAR(64) NULL UNIQUE,
 status ENUM('pending','queued','running','retry','success','failed','superseded') NOT NULL DEFAULT 'pending',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL, heartbeat_at DATETIME NULL, finished_at DATETIME NULL,
 products_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 KEY idx_dispatch (status,next_attempt_at), KEY idx_source (source_uid,created_at),
 KEY idx_source_status (source_uid,status,id), KEY idx_batch (batch_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scrape_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 job_id BIGINT UNSIGNED NOT NULL,
 attempt_number SMALLINT UNSIGNED NOT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME NULL, status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
 products_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 CONSTRAINT fk_attempt_job FOREIGN KEY(job_id) REFERENCES scrape_jobs(id) ON DELETE CASCADE,
 KEY idx_job (job_id,attempt_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO supermercados(alias,name) VALUES('confianca','Confiança'),('tauste','Tauste')
 ON DUPLICATE KEY UPDATE name=VALUES(name);
