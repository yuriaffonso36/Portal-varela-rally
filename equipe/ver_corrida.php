<?php
// equipe/ver_corrida.php – Fase 3: organização da corrida para a equipe (programação, equipe, carros)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corrida_publica.php';
requireRole('equipe');
$u = usuarioLogado();
$area = 'equipe'; $titulo = 'Corrida';
require_once '../includes/header.php';
renderDetalheCorrida($pdo, 'mecanico', (int)$u['id'], (int)($_GET['id'] ?? 0));
require_once '../includes/footer.php';
