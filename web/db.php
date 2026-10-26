<?php
// web/db.php - Conexão ao banco e utilitários de integração com envctl

// Constantes de caminhos do sistema
define('SCRIPTS_DIR', '/var/lib/cloud-projeto_2/scripts');
define('ENVS_DIR', '/var/lib/cloud-projeto_2/envs');
define('ENVCTL_BIN', 'sudo /usr/local/bin/envctl');

// Configurações do banco de dados MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'projeto2');
define('DB_USER', 'projeto2');
define('DB_PASS', 'SenhaSegura!');

/**
 * Retorna uma instância PDO conectada ao banco MySQL.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Erro de conexão ao banco de dados: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

/**
 * Executa comandos do script auxiliar envctl de forma segura.
 *
 * @param string $action Ação: create, status, log, stop, remove
 * @param string $id Identificador do ambiente (^[a-z0-9-]+$)
 * @param array $extraArgs Argumentos adicionais (cpu, mem, io, script)
 * @return array ['output' => string, 'lines' => array, 'code' => int]
 */
function exec_envctl(string $action, string $id, array $extraArgs = []): array {
    $action = trim($action);
    $id = trim($id);

    // Validação de segurança do ID: letras minúsculas, números e hífens apenas
    if (!preg_match('/^[a-z0-9-]+$/', $id)) {
        return [
            'output' => 'ID inválido. Use apenas letras minúsculas, números e hífen.',
            'lines'  => ['ID inválido'],
            'code'   => 1
        ];
    }

    // Ações permitidas
    $allowedActions = ['create', 'status', 'log', 'stop', 'remove'];
    if (!in_array($action, $allowedActions, true)) {
        return [
            'output' => "Ação '$action' não permitida.",
            'lines'  => ["Ação não permitida"],
            'code'   => 1
        ];
    }

    // Monta comando escapando rigorosamente todos os argumentos
    $cmdParts = [ENVCTL_BIN, escapeshellarg($action), escapeshellarg($id)];
    foreach ($extraArgs as $arg) {
        $cmdParts[] = escapeshellarg((string)$arg);
    }

    $cmd = implode(' ', $cmdParts);

    $outputLines = [];
    $returnCode = 0;
    exec($cmd, $outputLines, $returnCode);

    return [
        'output' => implode("\n", $outputLines),
        'lines'  => $outputLines,
        'code'   => $returnCode,
    ];
}

/**
 * Consulta o status real no kernel via envctl e sincroniza com o banco de dados.
 */
function sync_ambiente_status(PDO $pdo, string $id, string $estadoAtual): string {
    // Se já está marcado como REMOVIDO, não precisa checar
    if ($estadoAtual === 'REMOVIDO') {
        return 'REMOVIDO';
    }

    $res = exec_envctl('status', $id);
    $statusReal = trim(strtolower($res['output']));

    $novoEstado = match ($statusReal) {
        'executando' => 'EXECUTANDO',
        'terminado'  => 'TERMINADO',
        'erro'       => 'ERRO',
        default      => $estadoAtual,
    };

    if ($novoEstado !== $estadoAtual) {
        if ($novoEstado === 'TERMINADO' || $novoEstado === 'ERRO') {
            $stmt = $pdo->prepare("UPDATE ambientes SET estado = :estado, finished_in = COALESCE(finished_in, NOW()) WHERE id = :id");
        } else {
            $stmt = $pdo->prepare("UPDATE ambientes SET estado = :estado WHERE id = :id");
        }
        $stmt->execute(['estado' => $novoEstado, 'id' => $id]);
    }

    return $novoEstado;
}

/**
 * Helpers para mensagens de feedback (flash messages) via sessão.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function set_flash(string $tipo, string $mensagem): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
