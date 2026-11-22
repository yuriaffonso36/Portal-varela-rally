<?php
// cliente/aprovar_nav_os.php – Cliente visualiza e aprova OS do navegador
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/aprovacoes.php';
requireRole('cliente');
$u = usuarioLogado();
$area = 'cliente'; $titulo = 'Aprovar OS Navegador';

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT n.*, u.nome AS navegador_nome
    FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id
    WHERE n.id=? AND n.piloto_id=? AND n.status='encerrada' AND n.cobrar_de='piloto'");
$stmt->execute([$osId,$u['id']]); $os=$stmt->fetch();
if (!$os) { header('Location: index.php'); exit; }

$sucesso = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['acao']??'')==='aprovar') {
    $pdo->prepare("UPDATE nav_os SET ok_cliente=1 WHERE id=?")->execute([$osId]);
    $sucesso = 'Aprovação registrada!';
    $stmt->execute([$osId,$u['id']]); $os=$stmt->fetch();

    // Se o admin também já aprovou, lança na conta corrente (regra única em includes/aprovacoes.php)
    if (navOsTentarConcluir($pdo, $osId, null) === 'piloto') $sucesso = 'Aprovado! OS lançada na sua conta corrente.';
}

$itens=$pdo->prepare("SELECT * FROM nav_os_itens WHERE nav_os_id=? ORDER BY criado_em");
$itens->execute([$osId]);$itens=$itens->fetchAll();

require_once '../includes/header.php';
?>

<div class="mb-4">
  <a href="index.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
  <h5 class="fw-bold mt-1">Aprovar OS do Navegador</h5>
  <div class="text-muted small"><i class="bi bi-compass me-1"></i>Navegador: <strong><?= htmlspecialchars($os['navegador_nome']) ?></strong></div>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><?= htmlspecialchars($os['titulo']) ?></span>
        <span class="fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0 small">
          <thead><tr><th>Descrição</th><th class="text-end">Valor</th><th class="text-center">Comprovante</th></tr></thead>
          <tbody>
          <?php foreach($itens as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td class="text-end fw-semibold text-success">R$ <?= number_format($item['valor'],2,',','.') ?></td>
            <td class="text-center">
              <?php if($item['comprovante']): ?>
              <a href="<?= BASE_URL ?>/uploads/nav_comprovantes/<?= htmlspecialchars($item['comprovante']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-eye me-1"></i>Ver</a>
              <?php elseif($item['sem_comprovante']): ?>
              <span class="badge bg-secondary">Sem comprovante</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr><td class="fw-bold text-end">Total:</td><td class="text-end fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></td><td></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Sua Aprovação</div>
      <div class="card-body">
        <?php if(!$os['ok_cliente']): ?>
        <p class="text-muted small mb-3">Revise os itens acima. Ao aprovar, esta OS será lançada na sua conta corrente quando o administrador também aprovar.</p>
        <form method="post">
          <input type="hidden" name="acao" value="aprovar">
          <button class="btn btn-success w-100 fw-semibold"
                  data-confirm="Aprovar esta OS de R$ <?= number_format($os['valor_total'],2,',','.') ?>?">
            <i class="bi bi-check-lg me-2"></i>Aprovar OS
          </button>
        </form>
        <?php else: ?>
        <div class="text-center py-3">
          <i class="bi bi-check-circle-fill text-success d-block fs-1 mb-2"></i>
          <div class="fw-semibold">Você já aprovou!</div>
          <div class="text-muted small">Aguardando aprovação do administrador.</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
