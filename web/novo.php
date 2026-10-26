<?php
// web/novo.php - Formulário e criação de novos ambientes
require_once __DIR__ . '/db.php';
$pdo = get_db();

$erros = [];

/**
 * Converte quebras de linha Windows (\r\n) e antigas do Mac (\r) para Unix (\n).
 * Sem isso, o bash lê "do\r" em vez de "do" e o script falha com erro de sintaxe.
 */
function normalizar_quebras(string $texto): string {
    return str_replace(["\r\n", "\r"], "\n", $texto);
}

// Busca scripts já existentes para a opção de reuso
$stmtScripts = $pdo->query("SELECT * FROM scripts ORDER BY created DESC");
$scriptsCadastrados = $stmtScripts->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $cpu = (int)($_POST['cpu_pct'] ?? 20);
    $mem = (int)($_POST['mem_mb'] ?? 128);
    $io = (int)($_POST['io_mbps'] ?? 10);
    $tipoScript = $_POST['tipo_script'] ?? 'comando';

    // 1. Validações básicas
    if (empty($nome)) {
        $erros[] = "O nome do ambiente é obrigatório.";
    }

    if ($cpu < 1 || $cpu > 100) {
        $erros[] = "A CPU deve ser entre 1% e 100%.";
    }

    if ($mem < 16 || $mem > 4096) {
        $erros[] = "A memória deve ser entre 16 MB e 4096 MB.";
    }

    if ($io < 1 || $io > 1000) {
        $erros[] = "O limite de I/O deve ser entre 1 MB/s e 1000 MB/s.";
    }

    // Gera ID único e seguro (apenas letras minúsculas, números e hífen, max 40 chars)
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nome));
    $slug = substr($slug, 0, 15);
    if (empty($slug)) {
        $slug = 'env';
    }
    $id = sprintf('%s-%s-%s', $slug, date('Ymd'), substr(uniqid(), -5));

    // Garante que o diretório de scripts existe e tem permissão
    if (!is_dir(SCRIPTS_DIR)) {
        @mkdir(SCRIPTS_DIR, 0775, true);
    }

    $scriptId = null;
    $scriptPath = '';

    // 2. Trata a origem do script
    if (empty($erros)) {
        if ($tipoScript === 'existente') {
            $selScriptId = (int)($_POST['script_id'] ?? 0);
            $stmtFind = $pdo->prepare("SELECT * FROM scripts WHERE id = :id");
            $stmtFind->execute(['id' => $selScriptId]);
            $scriptExistente = $stmtFind->fetch();

            if (!$scriptExistente || !file_exists($scriptExistente['caminho'])) {
                $erros[] = "O script selecionado não foi encontrado no servidor.";
            } else {
                $scriptId = $scriptExistente['id'];
                $scriptPath = $scriptExistente['caminho'];
            }
        } elseif ($tipoScript === 'upload') {
            if (!isset($_FILES['script_file']) || $_FILES['script_file']['error'] !== UPLOAD_ERR_OK) {
                $erros[] = "Falha no envio do arquivo de script. Verifique se selecionou um arquivo válido.";
            } else {
                $origName = basename($_FILES['script_file']['name']);
                $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $targetFile = SCRIPTS_DIR . '/' . uniqid('up_') . '_' . $cleanName;

                if (move_uploaded_file($_FILES['script_file']['tmp_name'], $targetFile)) {
                    // Scripts salvos no Windows vêm com CRLF, que o bash não entende
                    file_put_contents($targetFile, normalizar_quebras(file_get_contents($targetFile)));
                    chmod($targetFile, 0755);
                    $stmtIns = $pdo->prepare("INSERT INTO scripts (nome, caminho) VALUES (:nome, :caminho)");
                    $stmtIns->execute(['nome' => $origName, 'caminho' => $targetFile]);
                    $scriptId = (int)$pdo->lastInsertId();
                    $scriptPath = $targetFile;
                } else {
                    $erros[] = "Não foi possível salvar o arquivo enviado em " . SCRIPTS_DIR;
                }
            }
        } elseif ($tipoScript === 'comando') {
            // O navegador envia o textarea com CRLF (\r\n); o bash só aceita LF (\n)
            $comandos = normalizar_quebras(trim($_POST['comandos'] ?? ''));
            if (empty($comandos)) {
                $erros[] = "Digite ao menos um comando para ser executado.";
            } else {
                // Adiciona shebang bash caso não tenha
                if (!str_starts_with($comandos, '#!')) {
                    $comandos = "#!/usr/bin/env bash\n\n" . $comandos;
                }
                $scriptName = "cmd_" . date('Ymd_His') . ".sh";
                $targetFile = SCRIPTS_DIR . '/' . $scriptName;

                if (file_put_contents($targetFile, $comandos) !== false) {
                    chmod($targetFile, 0755);
                    $stmtIns = $pdo->prepare("INSERT INTO scripts (nome, caminho) VALUES (:nome, :caminho)");
                    $stmtIns->execute(['nome' => "Comando: " . substr($nome, 0, 30), 'caminho' => $targetFile]);
                    $scriptId = (int)$pdo->lastInsertId();
                    $scriptPath = $targetFile;
                } else {
                    $erros[] = "Não foi possível salvar o script de comandos em " . SCRIPTS_DIR;
                }
            }
        } else {
            $erros[] = "Origem do script inválida.";
        }
    }

    // 3. Grava no banco e invoca o envctl create
    if (empty($erros) && $scriptId && $scriptPath) {
        try {
            // Insere o registro na tabela ambientes
            $stmtAmb = $pdo->prepare("INSERT INTO ambientes (id, nome, script_id, cpu_pct, mem_mb, io_mbps, estado) VALUES (:id, :nome, :script_id, :cpu, :mem, :io, 'EXECUTANDO')");
            $stmtAmb->execute([
                'id'        => $id,
                'nome'      => $nome,
                'script_id' => $scriptId,
                'cpu'       => $cpu,
                'mem'       => $mem,
                'io'        => $io,
            ]);

            // Dispara a criação via envctl como root
            $res = exec_envctl('create', $id, [$cpu, $mem, $io, $scriptPath]);

            if ($res['code'] === 0) {
                set_flash('success', "Ambiente '{$nome}' ({$id}) criado e iniciado com sucesso!");
                header("Location: index.php");
                exit;
            } else {
                // Atualiza o banco com status de erro caso o envctl falhe
                $pdo->prepare("UPDATE ambientes SET estado = 'ERRO' WHERE id = :id")->execute(['id' => $id]);
                $erros[] = "O comando envctl falhou: " . htmlspecialchars($res['output']);
            }
        } catch (Exception $e) {
            $erros[] = "Erro ao processar criação: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="h4 mb-1">Criar Novo Ambiente</h2>
                <p class="text-muted small mb-0">Configure os limites de hardware e o programa a ser executado isoladamente.</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
        </div>

        <?php if (!empty($erros)): ?>
            <div class="alert alert-danger" role="alert">
                <h6 class="alert-heading fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill"></i> Corrija os erros abaixo:</h6>
                <ul class="mb-0 ps-3">
                    <?php foreach ($erros as $erro): ?>
                        <li><?= htmlspecialchars($erro) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="novo.php" enctype="multipart/form-data">
            <!-- Dados Básicos -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title h6 mb-0 fw-bold">
                        <i class="bi bi-info-circle text-primary me-1"></i> Informações do Ambiente
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="nome" class="form-label fw-semibold">Nome de Identificação *</label>
                        <input type="text" class="form-control" id="nome" name="nome" placeholder="Ex: Servidor Web ou Teste de Carga" value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" required>
                        <div class="form-text">O sistema gerará um ID seguro único no padrão Linux para o cgroup.</div>
                    </div>
                </div>
            </div>

            <!-- Limites de Recursos (cgroups v2) -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title h6 mb-0 fw-bold">
                        <i class="bi bi-speedometer2 text-primary me-1"></i> Limites de Recursos (cgroups v2)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="cpu_pct" class="form-label fw-semibold">CPU (% de 1 Core)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="cpu_pct" name="cpu_pct" min="1" max="100" value="<?= htmlspecialchars($_POST['cpu_pct'] ?? '20') ?>" required>
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">Ex: 20 limita o processo a no máximo 20% da CPU.</div>
                        </div>

                        <div class="col-md-4">
                            <label for="mem_mb" class="form-label fw-semibold">Memória RAM (MB)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="mem_mb" name="mem_mb" min="16" max="4096" value="<?= htmlspecialchars($_POST['mem_mb'] ?? '128') ?>" required>
                                <span class="input-group-text">MB</span>
                            </div>
                            <div class="form-text">Ao estourar este limite, ocorre OOM-Killer.</div>
                        </div>

                        <div class="col-md-4">
                            <label for="io_mbps" class="form-label fw-semibold">I/O de Disco (MB/s)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="io_mbps" name="io_mbps" min="1" max="1000" value="<?= htmlspecialchars($_POST['io_mbps'] ?? '10') ?>" required>
                                <span class="input-group-text">MB/s</span>
                            </div>
                            <div class="form-text">Limite de leitura/escrita no disco principal.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programa / Script a Executar -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title h6 mb-0 fw-bold">
                        <i class="bi bi-terminal text-primary me-1"></i> Programa a Executar
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">Origem do Programa:</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="tipo_script" id="tipo_comando" value="comando" <?= (!isset($_POST['tipo_script']) || $_POST['tipo_script'] === 'comando') ? 'checked' : '' ?> onchange="atualizarAbasScript()">
                            <label class="btn btn-outline-primary" for="tipo_comando">
                                <i class="bi bi-code-slash"></i> Digitar Comandos
                            </label>

                            <input type="radio" class="btn-check" name="tipo_script" id="tipo_upload" value="upload" <?= (isset($_POST['tipo_script']) && $_POST['tipo_script'] === 'upload') ? 'checked' : '' ?> onchange="atualizarAbasScript()">
                            <label class="btn btn-outline-primary" for="tipo_upload">
                                <i class="bi bi-upload"></i> Enviar Arquivo .sh
                            </label>

                            <input type="radio" class="btn-check" name="tipo_script" id="tipo_existente" value="existente" <?= (isset($_POST['tipo_script']) && $_POST['tipo_script'] === 'existente') ? 'checked' : '' ?> onchange="atualizarAbasScript()">
                            <label class="btn btn-outline-primary" for="tipo_existente">
                                <i class="bi bi-collection"></i> Script Já Salvo
                            </label>
                        </div>
                    </div>

                    <!-- Opção 1: Digitar comandos -->
                    <div id="aba_comando" class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="comandos" class="form-label fw-semibold mb-0">Comandos Shell:</label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0" onclick="inserirExemplo('loop')">Ex: Loop CPU</button>
                                <button type="button" class="btn btn-outline-secondary py-0" onclick="inserirExemplo('servico')">Ex: Servidor HTTP</button>
                                <button type="button" class="btn btn-outline-secondary py-0" onclick="inserirExemplo('pid')">Ex: Isolamento PID</button>
                            </div>
                        </div>
                        <textarea class="form-control font-monospace" id="comandos" name="comandos" rows="6" placeholder="# Digite seus comandos bash aqui...&#10;while true; do&#10;    echo 'Serviço ativo em: ' $(date)&#10;    sleep 2&#10;done"><?= htmlspecialchars($_POST['comandos'] ?? '') ?></textarea>
                        <div class="form-text">Será salvo automaticamente na pasta de scripts e cadastrado no banco para reuso.</div>
                    </div>

                    <!-- Opção 2: Upload de arquivo .sh -->
                    <div id="aba_upload" class="mt-3 d-none">
                        <label for="script_file" class="form-label fw-semibold">Selecione o arquivo de script (.sh):</label>
                        <input class="form-control" type="file" id="script_file" name="script_file" accept=".sh,.bash,.txt">
                        <div class="form-text">O script receberá permissão de execução (chmod +x) automaticamente.</div>
                    </div>

                    <!-- Opção 3: Reuso de script existente -->
                    <div id="aba_existente" class="mt-3 d-none">
                        <label for="script_id" class="form-label fw-semibold">Escolha um script cadastrado:</label>
                        <?php if (empty($scriptsCadastrados)): ?>
                            <div class="alert alert-warning py-2 mb-0 small">
                                Nenhum script cadastrado previamente. Digite comandos ou envie um arquivo acima.
                            </div>
                        <?php else: ?>
                            <select class="form-select" id="script_id" name="script_id">
                                <?php foreach ($scriptsCadastrados as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>">
                                        <?= htmlspecialchars($s['nome']) ?> (<?= date('d/m/Y H:i', strtotime($s['created'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Botão de Envio -->
            <div class="d-flex justify-content-end gap-2">
                <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-play-circle me-1"></i> Criar e Executar Ambiente
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function atualizarAbasScript() {
    const isComando = document.getElementById('tipo_comando').checked;
    const isUpload = document.getElementById('tipo_upload').checked;
    const isExistente = document.getElementById('tipo_existente').checked;

    document.getElementById('aba_comando').classList.toggle('d-none', !isComando);
    document.getElementById('aba_upload').classList.toggle('d-none', !isUpload);
    document.getElementById('aba_existente').classList.toggle('d-none', !isExistente);
}

function inserirExemplo(tipo) {
    const textarea = document.getElementById('comandos');
    if (tipo === 'loop') {
        textarea.value = "# Teste de limite de CPU\necho 'Iniciando loop de CPU...'\nwhile true; do\n    :\ndone\n";
    } else if (tipo === 'servico') {
        textarea.value = "# Serviço contínuo (emite log a cada 2s)\necho 'Iniciando servico continuo...'\ncontador=1\nwhile true; do\n    echo \"[$(date '+%H:%M:%S')] Servico operando normalmente - ciclo $contador\"\n    contador=$((contador + 1))\n    sleep 2\ndone\n";
    } else if (tipo === 'pid') {
        textarea.value = "# Verificacao de isolamento de processos (PID)\necho 'Processos visiveis dentro deste ambiente:'\nps aux\n\necho ''\necho 'Hostname interno:'\nhostname\n";
    }
}

// Inicializa o estado visual das abas
document.addEventListener('DOMContentLoaded', atualizarAbasScript);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
