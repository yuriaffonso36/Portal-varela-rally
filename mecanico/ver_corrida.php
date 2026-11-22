<?php
// mecanico/ver_corrida.php – v8: organização da corrida (programação, equipe, carros, informações)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corrida_publica.php';
requireRole('mecanico');
$u = usuarioLogado();
$area = 'mecanico'; $titulo = 'Corrida';
require_once '../includes/header.php';
renderDetalheCorrida($pdo, 'mecanico', (int)$u['id'], (int)($_GET['id'] ?? 0));
require_once '../includes/footer.php';
