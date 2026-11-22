<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

// v8: envio maior que o post_max_size do servidor chega com $_POST vazio.
// Avisa o usuário em vez de "não acontecer nada".
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['flash_limite'] = 'O envio foi maior que o limite do servidor (' . ini_get('post_max_size') . '). Envie menos fotos por vez.';
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/')); exit;
}

function requireLogin() {
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /index.php'); exit;
    }
}

function requireRole(string ...$roles) {
    requireLogin();
    if (!in_array($_SESSION['usuario_tipo'], $roles, true)) {
        header('Location: /index.php'); exit;
    }
}

function usuarioLogado(): array {
    return [
        'id'       => $_SESSION['usuario_id']       ?? 0,
        'nome'     => $_SESSION['usuario_nome']     ?? '',
        'tipo'     => $_SESSION['usuario_tipo']     ?? '',
        'email'    => $_SESSION['usuario_email']    ?? '',
        'username' => $_SESSION['usuario_username'] ?? '',
    ];
}

function isAdmin():     bool { return ($_SESSION['usuario_tipo']??'') === 'admin'; }
function isMecanico():  bool { return ($_SESSION['usuario_tipo']??'') === 'mecanico'; }
function isCliente():   bool { return ($_SESSION['usuario_tipo']??'') === 'cliente'; }
function isEstoque():   bool { return ($_SESSION['usuario_tipo']??'') === 'estoque'; }
function isNavegador(): bool { return ($_SESSION['usuario_tipo']??'') === 'navegador'; }
