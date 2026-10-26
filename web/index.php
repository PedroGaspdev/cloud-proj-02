<?php
// web/index.php - Painel de controle e lista de ambientes
require_once __DIR__ . '/db.php';
$pdo = get_db();

// Busca ambientes cadastrados no banco
$stmt = $pdo->query("SELECT a.*, s.nome as script_nome FROM ambientes a LEFT JOIN scripts s ON a.script_id = s.id WHERE a.estado != 'REMOVIDO' ORDER BY a.created_in DESC");
$ambientes = $stmt->fetchAll();

// Sincroniza o status dos ambientes ativos com o cgroup no Linux
foreach ($ambientes as &$amb) {
    if ($amb['estado'] === 'EXECUTANDO') {
        $amb['estado'] = sync_ambiente_status($pdo, $amb['id'], $amb['estado']);
    }
}
unset($amb);

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 mb-1">Ambientes de Execução</h2>
        <!-- <p class="text-muted small mb-0">Gerencie processos isolados por namespaces e com limites de cgroups v2.</p> -->
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-clockwise"></i> Atualizar Status
        </a>
        <a href="novo.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-plus-lg"></i> Novo Ambiente
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($ambientes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-box text-muted display-4"></i>
                <h5 class="mt-3 text-secondary">Nenhum ambiente ativo no momento</h5>
                <p class="text-muted small">Crie seu primeiro ambiente isolado definindo limites de CPU, memória e I/O.</p>
                <a href="novo.php" class="btn btn-primary btn-sm mt-2">
                    <i class="bi bi-plus-lg"></i> Criar Primeiro Ambiente
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID / Nome</th>
                            <th>Script</th>
                            <th>Limites (CPU / Mem / I/O)</th>
                            <th>Status</th>
                            <th>Criado em</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ambientes as $amb): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($amb['nome']) ?></strong>
                                    <div class="text-muted small"><code><?= htmlspecialchars($amb['id']) ?></code></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-file-code"></i> <?= htmlspecialchars($amb['script_nome'] ?? 'Script avulso') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        CPU: <?= (int)$amb['cpu_pct'] ?>%
                                    </span>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                        RAM: <?= (int)$amb['mem_mb'] ?> MB
                                    </span>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                        I/O: <?= (int)$amb['io_mbps'] ?> MB/s
                                    </span>
                                </td>
                                <td>
                                    <?php if ($amb['estado'] === 'EXECUTANDO'): ?>
                                        <span class="badge bg-success d-inline-flex align-items-center gap-1">
                                            <span class="spinner-grow spinner-grow-sm" style="width: 0.5rem; height: 0.5rem;"></span>
                                            Executando
                                        </span>
                                    <?php elseif ($amb['estado'] === 'TERMINADO'): ?>
                                        <span class="badge bg-secondary">Terminado</span>
                                    <?php elseif ($amb['estado'] === 'ERRO'): ?>
                                        <span class="badge bg-danger">Erro (OOM / Falha)</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark"><?= htmlspecialchars($amb['estado']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= date('d/m/Y H:i', strtotime($amb['created_in'])) ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <button class="btn btn-outline-secondary btn-sm" disabled title="Logs (Será implementado no Passo 4)">
                                            <i class="bi bi-terminal"></i> Logs
                                        </button>
                                        <!-- Ações via POST para acoes.php: um simples link (GET) não pode parar nem remover ambientes -->
                                        <?php if ($amb['estado'] === 'EXECUTANDO'): ?>
                                            <form method="POST" action="acoes.php" class="d-inline">
                                                <input type="hidden" name="acao" value="stop">
                                                <input type="hidden" name="id" value="<?= htmlspecialchars($amb['id']) ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Parar a execução (mantém o registro)">
                                                    <i class="bi bi-stop-circle"></i> Parar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="acoes.php" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode("Remover o ambiente '{$amb['nome']}'? Os processos serão encerrados e o log apagado.", JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                                            <input type="hidden" name="acao" value="remove">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($amb['id']) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Remover (encerra, apaga cgroup e log)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
