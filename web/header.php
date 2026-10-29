<?php
// web/header.php - Topo e barra de navegação comum
require_once __DIR__ . '/db.php';
$flash = get_flash();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Ambientes - Namespaces & Cgroups</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar-brand {
            font-weight: 600;
            letter-spacing: -0.5px;
        }
        .card {
            border-radius: 0.75rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .terminal-box {
            background-color: #1e1e1e;
            color: #d4d4d4;
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            padding: 1rem;
            border-radius: 0.5rem;
            max-height: 480px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <i class="bi bi-box-seam"></i>
            <span>Projeto 2 &bull; Ambientes Isolados</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-cpu"></i> Ambientes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'novo.php') ? 'active' : '' ?>" href="novo.php">
                        <i class="bi bi-plus-circle"></i> Novo Ambiente
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'scripts.php') ? 'active' : '' ?>" href="scripts.php">
                        <i class="bi bi-file-earmark-code"></i> Scripts Salvos
                    </a>
                </li>
            </ul>
            <!-- <div class="d-flex align-items-center text-light small">
                <span class="badge bg-secondary me-2">cgroups v2</span>
                <span class="badge bg-secondary">unshare</span>
            </div> -->
        </div>
    </div>
</nav>

<div class="container mb-5">
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flash['mensagem']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif; ?>
