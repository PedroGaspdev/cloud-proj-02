<?php
// web/acoes.php - Ações de controle do ciclo de vida dos ambientes (parar)
require_once __DIR__ . '/db.php';

/**
 * Define a mensagem de feedback e volta para a listagem de ambientes.
 */
function voltar(string $tipo, string $mensagem): never {
    set_flash($tipo, $mensagem);
    header("Location: index.php");
    exit;
}

// Só aceita POST: ações que alteram um ambiente não podem ser disparadas por um simples link
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$acao = $_POST['acao'] ?? '';
$id = trim($_POST['id'] ?? '');

// Validação de segurança do ID antes de qualquer consulta ou chamada ao envctl
if (!preg_match('/^[a-z0-9-]+$/', $id)) {
    voltar('danger', 'ID de ambiente inválido.');
}

$pdo = get_db();

$stmt = $pdo->prepare("SELECT * FROM ambientes WHERE id = :id AND estado != 'REMOVIDO'");
$stmt->execute(['id' => $id]);
$amb = $stmt->fetch();

if (!$amb) {
    voltar('danger', "Ambiente '{$id}' não encontrado.");
}

if ($acao === 'stop') {
    // Confere o status real no kernel antes de agir: o programa pode já ter terminado sozinho
    $estado = sync_ambiente_status($pdo, $id, $amb['estado']);
    if ($estado !== 'EXECUTANDO') {
        voltar('warning', "O ambiente '{$amb['nome']}' já não estava em execução (status: " . strtolower($estado) . ").");
    }

    // envctl stop cria a marca "stopped" e escreve 1 em cgroup.kill, matando todos os processos do cgroup
    $res = exec_envctl('stop', $id);
    if ($res['code'] !== 0) {
        voltar('danger', "Falha ao parar o ambiente '{$amb['nome']}': " . $res['output']);
    }

    $stmtUpd = $pdo->prepare("UPDATE ambientes SET estado = 'TERMINADO', finished_in = COALESCE(finished_in, NOW()) WHERE id = :id");
    $stmtUpd->execute(['id' => $id]);

    voltar('success', "Ambiente '{$amb['nome']}' ({$id}) parado com sucesso.");
}

voltar('danger', 'Ação inválida.');
