<?php
// mecanico/upload.php – v8: o envio de fotos agora é feito somente dentro da OS
// (ver_os.php), para a foto sempre ficar na OS e no item/afazer certos.
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('mecanico');
$osId = (int)($_GET['os'] ?? 0);
header('Location: ' . BASE_URL . ($osId ? '/mecanico/ver_os.php?id=' . $osId . '#fotos' : '/mecanico/ordens.php'));
exit;
