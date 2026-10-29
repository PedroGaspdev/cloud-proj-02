<?php
// web/logs.php - Visualizador da saída (output.log) de um ambiente
require_once __DIR__ . '/db.php';
$pdo = get_db();

$id = trim($_GET['id'] ?? '');

// Validação de segurança do ID antes de qualquer consulta ou chamada ao envctl
if (!preg_match('/^[a-z0-9-]+$/', $id)) {
    set_flash('danger', 'ID de ambiente inválido.');
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT a.*, s.nome as script_nome FROM ambientes a LEFT JOIN scripts s ON a.script_id = s.id WHERE a.id = :id AND a.estado != 'REMOVIDO'");
$stmt->execute(['id' => $id]);
$amb = $stmt->fetch();

if (!$amb) {
    set_flash('danger', "Ambiente '{$id}' não encontrado.");
    header("Location: index.php");
    exit;
}

// Status real vem do kernel (cgroup), igual à listagem
$amb['estado'] = sync_ambiente_status($pdo, $id, $amb['estado']);

// envctl log devolve as últimas 200 linhas do output.log; falha se o arquivo ainda não existe
$res = exec_envctl('log', $id);
$log = $res['code'] === 0 ? $res['output'] : null;

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 mb-1">Saída do Ambiente: <?= htmlspecialchars($amb['nome']) ?></h2>
        <div class="text-muted small"><code><?= htmlspecialchars($amb['id']) ?></code></div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
        <a href="logs.php?id=<?= urlencode($amb['id']) ?>" class="btn btn-primary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-clockwise"></i> Atualizar
        </a>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3 small">
        <div>
            <span class="text-muted">Status:</span>
            <?php if ($amb['estado'] === 'EXECUTANDO'): ?>
                <span class="badge bg-success">Executando</span>
            <?php elseif ($amb['estado'] === 'TERMINADO'): ?>
                <span class="badge bg-secondary">Terminado</span>
            <?php elseif ($amb['estado'] === 'ERRO'): ?>
                <span class="badge bg-danger">Erro (OOM / Falha)</span>
            <?php else: ?>
                <span class="badge bg-dark"><?= htmlspecialchars($amb['estado']) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <span class="text-muted">Script:</span>
            <?= htmlspecialchars($amb['script_nome'] ?? 'Script removido') ?>
        </div>
        <div>
            <span class="text-muted">Limites:</span>
            CPU <?= (int)$amb['cpu_pct'] ?>% (peso <?= (int)$amb['cpu_weight'] ?>) &bull; RAM <?= (int)$amb['mem_mb'] ?> MB &bull; I/O <?= (int)$amb['io_mbps'] ?> MB/s
        </div>
        <div>
            <span class="text-muted">Criado em:</span>
            <?= date('d/m/Y H:i:s', strtotime($amb['created_in'])) ?>
        </div>
    </div>
</div>

<?php if ($log === null): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> O arquivo de saída deste ambiente ainda não existe ou não pôde ser lido.
    </div>
<?php else: ?>
    <div class="text-muted small mb-1">Últimas 200 linhas de <code>/var/lib/cloud-projeto_2/envs/<?= htmlspecialchars($amb['id']) ?>/output.log</code></div>
    <div class="terminal-box" id="log"><?= $log === '' ? '<span class="text-secondary">(o programa ainda não escreveu nada)</span>' : htmlspecialchars($log) ?></div>
<?php endif; ?>

<script>
// Rola o painel até o fim, onde estão as linhas mais recentes
const logBox = document.getElementById('log');
if (logBox) logBox.scrollTop = logBox.scrollHeight;
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
