<?php
// mecanico/corridas.php – v8: corridas do usuário
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corrida_publica.php';
requireRole('mecanico');
$u = usuarioLogado();
$area = 'mecanico'; $titulo = 'Corridas';
require_once '../includes/header.php';
renderListaCorridas($pdo, 'mecanico', (int)$u['id']);
require_once '../includes/footer.php';
