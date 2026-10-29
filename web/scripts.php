<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$erros = [];
$mensagemVisualizacao = null;
$conteudoVisualizacao = null;
$scriptVisualizacao = null;
$nomeNovo = '';
$conteudoNovo = '';
$nomeUpload = '';

if (empty($_SESSION['scripts_csrf'])) {
    $_SESSION['scripts_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['scripts_csrf'];

function normalizar_quebras_script(string $conteudo): string {
    return str_replace(["\r\n", "\r"], "\n", $conteudo);
}

function caminho_script_salvo(string $caminho): ?string {
    $diretorio = realpath(SCRIPTS_DIR);
    $arquivo = realpath($caminho);

    if (
        $diretorio === false
        || $arquivo === false
        || is_link($caminho)
        || dirname($arquivo) !== $diretorio
        || !is_file($arquivo)
    ) {
        return null;
    }

    return $arquivo;
}

function preparar_conteudo_script(string $conteudo): string {
    $conteudo = normalizar_quebras_script($conteudo);

    if (trim($conteudo) === '') {
        throw new RuntimeException('O conteúdo do script não pode ficar vazio.');
    }
    if (strlen($conteudo) > 1024 * 1024) {
        throw new RuntimeException('O script deve ter no máximo 1 MB.');
    }
    if (str_contains($conteudo, "\0") || preg_match('//u', $conteudo) !== 1) {
        throw new RuntimeException('O arquivo precisa ser um texto UTF-8 válido, sem conteúdo binário.');
    }
    if (!str_starts_with($conteudo, '#!')) {
        $conteudo = "#!/usr/bin/env bash\n\n" . $conteudo;
    }
    if (strlen($conteudo) > 1024 * 1024) {
        throw new RuntimeException('O script deve ter no máximo 1 MB.');
    }

    return $conteudo;
}

function armazenar_script(PDO $pdo, string $nome, string $conteudo): void {
    $nome = trim($nome);
    if ($nome === '' || preg_match('//u', $nome) !== 1 || preg_match_all('/./us', $nome, $caracteres) > 100) {
        throw new RuntimeException('Informe um nome com até 100 caracteres.');
    }

    $conteudo = preparar_conteudo_script($conteudo);
    if (!is_dir(SCRIPTS_DIR) && !mkdir(SCRIPTS_DIR, 0775, true) && !is_dir(SCRIPTS_DIR)) {
        throw new RuntimeException('Não foi possível criar a pasta de scripts no servidor.');
    }

    $caminho = SCRIPTS_DIR . '/script_' . bin2hex(random_bytes(16)) . '.sh';
    $bytesGravados = file_put_contents($caminho, $conteudo, LOCK_EX);
    if ($bytesGravados === false || $bytesGravados !== strlen($conteudo)) {
        if (is_file($caminho) && !unlink($caminho)) {
            error_log("Não foi possível remover um arquivo de script gravado parcialmente: {$caminho}");
        }
        throw new RuntimeException('Não foi possível gravar o arquivo de script no servidor.');
    }
    if (!chmod($caminho, 0755)) {
        unlink($caminho);
        throw new RuntimeException('Não foi possível habilitar a execução do script salvo.');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO scripts (nome, caminho) VALUES (:nome, :caminho)');
        $stmt->execute(['nome' => $nome, 'caminho' => $caminho]);
    } catch (PDOException $e) {
        if (is_file($caminho) && !unlink($caminho)) {
            error_log("Não foi possível remover o arquivo de script após falha no banco: {$caminho}");
        }
        error_log('Falha ao cadastrar script: ' . $e->getMessage());
        throw new RuntimeException('O arquivo foi criado, mas não foi possível cadastrá-lo no banco de dados.');
    }
}

function redirecionar_scripts(): never {
    header('Location: scripts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenEnviado = $_POST['csrf_token'] ?? '';
    if (!is_string($tokenEnviado) || !hash_equals($csrfToken, $tokenEnviado)) {
        $erros[] = 'A solicitação expirou ou é inválida. Atualize a página e tente novamente.';
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $nomeRecebido = $_POST['nome_script'] ?? '';
            $conteudoRecebido = $_POST['conteudo_script'] ?? '';
            $nomeNovo = is_string($nomeRecebido) ? trim($nomeRecebido) : '';
            $conteudoNovo = is_string($conteudoRecebido) ? $conteudoRecebido : '';

            try {
                if (!is_string($nomeRecebido) || !is_string($conteudoRecebido)) {
                    throw new RuntimeException('Os campos do script são inválidos.');
                }
                armazenar_script($pdo, $nomeNovo, $conteudoNovo);
                set_flash('success', "Script '{$nomeNovo}' salvo com sucesso.");
                redirecionar_scripts();
            } catch (RuntimeException $e) {
                $erros[] = $e->getMessage();
            }
        } elseif ($acao === 'upload') {
            $nomeRecebido = $_POST['nome_upload'] ?? '';
            $nomeUpload = is_string($nomeRecebido) ? trim($nomeRecebido) : '';
            $arquivo = $_FILES['script_file'] ?? null;

            if (!is_string($nomeRecebido)) {
                $erros[] = 'O nome informado para o script é inválido.';
            } elseif (!is_array($arquivo) || !isset($arquivo['error']) || !is_int($arquivo['error'])) {
                $erros[] = 'Os dados do arquivo enviado são inválidos.';
            } elseif ($arquivo['error'] !== UPLOAD_ERR_OK) {
                $codigoErro = $arquivo['error'] ?? UPLOAD_ERR_NO_FILE;
                $erros[] = match ($codigoErro) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite permitido pelo servidor.',
                    UPLOAD_ERR_PARTIAL => 'O upload foi recebido parcialmente. Tente novamente.',
                    UPLOAD_ERR_NO_FILE => 'Selecione um arquivo de script para enviar.',
                    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu receber o arquivo.',
                    default => 'Falha no upload do arquivo de script.',
                };
            } elseif (
                !isset($arquivo['tmp_name'], $arquivo['name'], $arquivo['size'])
                || !is_string($arquivo['tmp_name'])
                || !is_string($arquivo['name'])
                || !is_int($arquivo['size'])
            ) {
                $erros[] = 'Os dados do arquivo enviado são inválidos.';
            } elseif (!is_uploaded_file($arquivo['tmp_name'])) {
                $erros[] = 'O arquivo enviado não foi reconhecido como um upload válido.';
            } elseif (!in_array(strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION)), ['sh', 'bash'], true)) {
                $erros[] = 'Envie um arquivo com extensão .sh ou .bash.';
            } elseif ($arquivo['size'] > 1024 * 1024) {
                $erros[] = 'O script deve ter no máximo 1 MB.';
            } else {
                $conteudo = file_get_contents($arquivo['tmp_name']);
                if ($conteudo === false) {
                    $erros[] = 'Não foi possível ler o arquivo enviado.';
                } else {
                    if ($nomeUpload === '') {
                        $nomeUpload = pathinfo(basename($arquivo['name']), PATHINFO_FILENAME);
                    }

                    try {
                        armazenar_script($pdo, $nomeUpload, $conteudo);
                        set_flash('success', "Script '{$nomeUpload}' enviado e salvo com sucesso.");
                        redirecionar_scripts();
                    } catch (RuntimeException $e) {
                        $erros[] = $e->getMessage();
                    }
                }
            }
        } elseif ($acao === 'remover') {
            $idScript = filter_var($_POST['script_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($idScript === false) {
                $erros[] = 'O script selecionado é inválido.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmtScript = $pdo->prepare('SELECT id, nome, caminho FROM scripts WHERE id = :id FOR UPDATE');
                    $stmtScript->execute(['id' => $idScript]);
                    $script = $stmtScript->fetch();

                    if (!$script) {
                        $pdo->rollBack();
                        set_flash('danger', 'O script selecionado não foi encontrado.');
                        redirecionar_scripts();
                    }

                    $caminhoReal = caminho_script_salvo($script['caminho']);
                    if ($caminhoReal === null && (file_exists($script['caminho']) || is_link($script['caminho']))) {
                        $pdo->rollBack();
                        set_flash('danger', 'O arquivo não está na pasta permitida de scripts; nada foi removido.');
                        redirecionar_scripts();
                    }

                    $stmtDelete = $pdo->prepare('DELETE FROM scripts WHERE id = :id');
                    $stmtDelete->execute(['id' => $idScript]);
                    if ($caminhoReal !== null && !unlink($caminhoReal)) {
                        throw new RuntimeException('O registro foi localizado, mas o arquivo não pôde ser removido.');
                    }

                    $pdo->commit();
                    set_flash('success', "Script '{$script['nome']}' removido com sucesso.");
                    redirecionar_scripts();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Falha ao remover script: ' . $e->getMessage());
                    $erros[] = 'Não foi possível remover o script do banco de dados.';
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $erros[] = $e->getMessage();
                }
            }
        } else {
            $erros[] = 'Ação inválida.';
        }
    }
}

$stmtLista = $pdo->query(
    'SELECT s.id, s.nome, s.caminho, s.created, COUNT(a.id) AS ambientes_vinculados
     FROM scripts s
     LEFT JOIN ambientes a ON a.script_id = s.id
     GROUP BY s.id, s.nome, s.caminho, s.created
     ORDER BY s.created DESC, s.id DESC'
);
$scripts = $stmtLista->fetchAll();

$idVisualizacao = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idVisualizacao !== false && $idVisualizacao !== null) {
    $stmtVisualizacao = $pdo->prepare('SELECT nome, caminho FROM scripts WHERE id = :id');
    $stmtVisualizacao->execute(['id' => $idVisualizacao]);
    $registroVisualizacao = $stmtVisualizacao->fetch();

    if (!$registroVisualizacao) {
        $mensagemVisualizacao = 'O script selecionado não foi encontrado.';
    } else {
        $scriptVisualizacao = $registroVisualizacao;
        $caminhoVisualizacao = caminho_script_salvo($scriptVisualizacao['caminho']);
        if ($caminhoVisualizacao === null || !is_readable($caminhoVisualizacao)) {
            $mensagemVisualizacao = 'O arquivo não existe ou não pode ser lido pelo servidor.';
        } else {
            $conteudoVisualizacao = file_get_contents($caminhoVisualizacao);
            if ($conteudoVisualizacao === false) {
                $mensagemVisualizacao = 'Não foi possível ler o conteúdo do arquivo.';
                $conteudoVisualizacao = null;
            }
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 mb-1">Scripts Salvos</h2>
        <p class="text-muted small mb-0">Visualize, crie, envie e gerencie scripts reutilizáveis.</p>
    </div>
    <a href="novo.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-plus-circle"></i> Novo Ambiente
    </a>
</div>

<?php if (!empty($erros)): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            <?php foreach ($erros as $erro): ?>
                <li><?= htmlspecialchars($erro) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h3 class="h6 mb-0 fw-bold"><i class="bi bi-code-square text-primary me-1"></i> Criar script</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="scripts.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="acao" value="criar">
                    <div class="mb-3">
                        <label for="nome_script" class="form-label">Nome</label>
                        <input class="form-control" id="nome_script" name="nome_script" maxlength="100" required value="<?= htmlspecialchars($nomeNovo) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="conteudo_script" class="form-label">Conteúdo Bash</label>
                        <textarea class="form-control font-monospace" id="conteudo_script" name="conteudo_script" rows="8" maxlength="1048576" required><?= htmlspecialchars($conteudoNovo) ?></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Salvar script</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h3 class="h6 mb-0 fw-bold"><i class="bi bi-upload text-primary me-1"></i> Enviar script do computador</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="scripts.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="acao" value="upload">
                    <input type="hidden" name="MAX_FILE_SIZE" value="1048576">
                    <div class="mb-3">
                        <label for="nome_upload" class="form-label">Nome do script <span class="text-muted">(opcional)</span></label>
                        <input class="form-control" id="nome_upload" name="nome_upload" maxlength="100" value="<?= htmlspecialchars($nomeUpload) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="script_file" class="form-label">Arquivo (.sh ou .bash)</label>
                        <input class="form-control" type="file" id="script_file" name="script_file" accept=".sh,.bash" required>
                        <div class="form-text">Tamanho máximo: 1 MB. O arquivo será normalizado e salvo para reuso.</div>
                    </div>
                    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-cloud-arrow-up"></i> Enviar e salvar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($scriptVisualizacao !== null): ?>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h3 class="h6 mb-0 fw-bold">Conteúdo: <?= htmlspecialchars($scriptVisualizacao['nome']) ?></h3>
            <a href="scripts.php" class="btn btn-outline-secondary btn-sm">Fechar</a>
        </div>
        <div class="card-body">
            <?php if ($mensagemVisualizacao !== null): ?>
                <div class="alert alert-warning mb-0"><?= htmlspecialchars($mensagemVisualizacao) ?></div>
            <?php else: ?>
                <pre class="terminal-box mb-0"><code><?= htmlspecialchars($conteudoVisualizacao ?? '') ?></code></pre>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($mensagemVisualizacao !== null): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($mensagemVisualizacao) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white py-3">
        <h3 class="h6 mb-0 fw-bold">Scripts cadastrados</h3>
    </div>
    <?php if (empty($scripts)): ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-file-earmark-code text-muted display-5"></i>
            <p class="text-muted mb-0 mt-2">Nenhum script salvo. Crie um ou envie um arquivo acima.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th>Salvo em</th>
                        <th>Ambientes vinculados</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scripts as $script): ?>
                        <tr>
                            <td><?= htmlspecialchars($script['nome']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($script['created']) ?></td>
                            <td><?= (int)$script['ambientes_vinculados'] ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-outline-secondary btn-sm" href="scripts.php?id=<?= (int)$script['id'] ?>">
                                        <i class="bi bi-eye"></i> Visualizar
                                    </a>
                                    <form method="POST" action="scripts.php" onsubmit="return confirm('Remover o script <?= htmlspecialchars(json_encode($script['nome'], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>? Ambientes já criados continuarão executando sua própria cópia.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="acao" value="remover">
                                        <input type="hidden" name="script_id" value="<?= (int)$script['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">
                                            <i class="bi bi-trash"></i> Remover
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

<?php require_once __DIR__ . '/footer.php'; ?>
