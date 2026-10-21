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
    script_id   INT NOT NULL,
    cpu_pct     INT NOT NULL,
    mem_mb      INT NOT NULL,
    io_mbps     INT NOT NULL,
    estado      ENUM('EXECUTANDO', 'TERMINADO', 'ERRO', 'REMOVIDO') DEFAULT 'EXECUTANDO',
    created_in  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_in TIMESTAMP NULL,
    FOREIGN KEY (script_id) REFERENCES scripts(id)
);