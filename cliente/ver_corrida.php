<?php
// cliente/ver_corrida.php – v8: organização da corrida (programação, equipe, carros, informações)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corrida_publica.php';
requireRole('cliente');
$u = usuarioLogado();
$area = 'cliente'; $titulo = 'Corrida';
require_once '../includes/header.php';
renderDetalheCorrida($pdo, 'cliente', (int)$u['id'], (int)($_GET['id'] ?? 0));
require_once '../includes/footer.php';
