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

ALTER TABLE ambientes MODIFY script_id INT NULL;
ALTER TABLE ambientes
    ADD CONSTRAINT fk_ambientes_script
    FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE SET NULL;
