<?php
// admin/ajax_veiculos.php – retorna veículos de um cliente em JSON (v9: inclui km_atual)
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('admin','mecanico');

header('Content-Type: application/json');
$clienteId = (int)($_GET['cliente_id'] ?? 0);
if (!$clienteId) { echo '[]'; exit; }

$stmt = $pdo->prepare("SELECT id, marca, modelo, placa, km_atual FROM veiculos WHERE cliente_id=? ORDER BY marca");
$stmt->execute([$clienteId]);
echo json_encode($stmt->fetchAll());
