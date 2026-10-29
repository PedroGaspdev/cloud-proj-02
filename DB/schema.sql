CREATE DATABASE IF NOT EXISTS projeto2;
CREATE USER IF NOT EXISTS 'projeto2'@'localhost' IDENTIFIED BY 'SenhaSegura!';
GRANT ALL PRIVILEGES ON projeto2.* TO 'projeto2'@'localhost';
USE projeto2;

CREATE TABLE IF NOT EXISTS scripts (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    nome    VARCHAR(100) NOT NULL,
    caminho VARCHAR(255) NOT NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ambientes(
    id          VARCHAR(40) PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    script_id   INT NULL,
    cpu_pct     INT NOT NULL,
    cpu_weight  INT NOT NULL DEFAULT 100,
    mem_mb      INT NOT NULL,
    io_mbps     INT NOT NULL,
    estado      ENUM('EXECUTANDO', 'TERMINADO', 'ERRO', 'REMOVIDO') DEFAULT 'EXECUTANDO',
    created_in  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_in TIMESTAMP NULL,
    CONSTRAINT fk_ambientes_script
        FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE SET NULL
);

SET @script_fk = (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ambientes'
      AND COLUMN_NAME = 'script_id'
      AND REFERENCED_TABLE_NAME = 'scripts'
    LIMIT 1
);
SET @drop_script_fk = IF(
    @script_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE ambientes DROP FOREIGN KEY `', REPLACE(@script_fk, '`', '``'), '`')
);
PREPARE drop_script_fk_stmt FROM @drop_script_fk;
EXECUTE drop_script_fk_stmt;
DEALLOCATE PREPARE drop_script_fk_stmt;

-- Bancos criados antes da prioridade de CPU não têm a coluna cpu_weight.
-- O MySQL 8 não suporta ADD COLUMN IF NOT EXISTS, então só adiciona se ela ainda não existir.
SET @tem_cpu_weight = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ambientes'
      AND COLUMN_NAME = 'cpu_weight'
);
SET @add_cpu_weight = IF(
    @tem_cpu_weight > 0,
    'SELECT 1',
    'ALTER TABLE ambientes ADD COLUMN cpu_weight INT NOT NULL DEFAULT 100 AFTER cpu_pct'
);
PREPARE add_cpu_weight_stmt FROM @add_cpu_weight;
EXECUTE add_cpu_weight_stmt;
DEALLOCATE PREPARE add_cpu_weight_stmt;

ALTER TABLE ambientes MODIFY script_id INT NULL;
ALTER TABLE ambientes
    ADD CONSTRAINT fk_ambientes_script
    FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE SET NULL;
