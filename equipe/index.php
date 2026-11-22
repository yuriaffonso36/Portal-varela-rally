<?php
// equipe/index.php – Fase 3: área da equipe (motoristas, apoio...) — corridas em que está escalado
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corrida_publica.php';
requireRole('equipe');
$u = usuarioLogado();
$area = 'equipe'; $titulo = 'Corridas';
require_once '../includes/header.php';
?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="<?= BASE_URL ?>/reembolsos.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-cash-coin me-1"></i>Lançar reembolso</a>
  <a href="<?= BASE_URL ?>/perfil.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-gear me-1"></i>Meu perfil e PIX</a>
</div>
<?php
renderListaCorridas($pdo, 'mecanico', (int)$u['id']);
require_once '../includes/footer.php';
