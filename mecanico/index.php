<?php
// mecanico/index.php – Dashboard do Mecânico
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('mecanico');
$u = usuarioLogado();
$area   = 'mecanico';
$titulo = 'Dashboard';

// Apenas OS não fechadas atribuídas ao mecânico
$stmt = $pdo->prepare("
    SELECT os.*, u.nome AS cliente_nome, v.marca, v.modelo, v.placa,
           (SELECT COUNT(*) FROM fotos f WHERE f.ordem_servico_id=os.id) AS total_fotos,
           (SELECT COUNT(*) FROM os_itens oi WHERE oi.ordem_servico_id=os.id AND oi.origem='mecanico') AS itens_mec,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos,
           r.nome AS rally_nome
    FROM ordens_servico os
    JOIN usuarios u ON u.id=os.cliente_id
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN rallys r ON r.id=os.rally_id
    WHERE os.mecanico_id=? AND os.status NOT IN ('concluida','cancelada')
    ORDER BY os.criado_em DESC");
$stmt->execute([$u['id']]);
$osAbertas = $stmt->fetchAll();

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça'];

// Próximas corridas em que o mecânico está escalado
$corridas = $pdo->prepare("SELECT r.*, e.funcao FROM rally_equipe e JOIN rallys r ON r.id=e.rally_id
    WHERE e.usuario_id=? AND r.ativo=1 AND (r.data_fim IS NULL OR r.data_fim>=CURDATE()) ORDER BY r.data_inicio LIMIT 3");
$corridas->execute([$u['id']]); $corridas = $corridas->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-person-gear me-2 text-success"></i>Minhas Ordens em Aberto</h5>
  <span class="badge bg-secondary"><?= count($osAbertas) ?> OS ativa(s)</span>
</div>

<?php if ($corridas): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body py-2">
  <div class="d-flex flex-wrap gap-3 align-items-center">
    <span class="fw-semibold small text-muted"><i class="bi bi-flag-fill text-danger me-1"></i>Próximas corridas:</span>
    <?php foreach ($corridas as $c): ?>
    <a href="ver_corrida.php?id=<?= $c['id'] ?>" class="text-decoration-none small"><strong><?= htmlspecialchars($c['nome']) ?></strong>
      <span class="text-muted"><?= $c['data_inicio'] ? date('d/m', strtotime($c['data_inicio'])) : '' ?><?= $c['funcao'] ? ' · '.htmlspecialchars($c['funcao']) : '' ?></span></a>
    <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<?php if ($osAbertas): ?>
<div class="row g-3">
<?php foreach ($osAbertas as $os): ?>
<div class="col-12 col-md-6 col-xl-4">
  <div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
      <span class="badge bg-dark">OS <?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span>
      <span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?? $os['status'] ?></span>
    </div>
    <div class="card-body">
      <h6 class="fw-bold mb-2"><?= htmlspecialchars($os['titulo']) ?></h6>
      <div class="small text-muted">
        <div><i class="bi bi-person me-1"></i><strong>Cliente:</strong> <?= htmlspecialchars($os['cliente_nome']) ?></div>
        <?php if ($os['marca']): ?>
        <div class="mt-1">
          <i class="bi bi-car-front me-1"></i><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?>
          <?= $os['placa'] ? ' <code>'.htmlspecialchars($os['placa']).'</code>' : '' ?>
        </div>
        <?php endif; ?>
        <?php if ($os['rally_nome']): ?><div class="mt-1"><i class="bi bi-flag me-1"></i><?= htmlspecialchars($os['rally_nome']) ?></div><?php endif; ?>
        <div class="mt-1"><i class="bi bi-search me-1"></i><?= $os['itens_mec'] ?> encontrado(s) · <i class="bi bi-images ms-1 me-1"></i><?= $os['total_fotos'] ?> foto(s)</div>
      </div>
      <?php if ($os['af_total']): $pc = round($os['af_feitos']/$os['af_total']*100); ?>
      <div class="mt-3 small"><div class="d-flex justify-content-between"><span><i class="bi bi-ui-checks me-1"></i>Afazeres</span><strong><?= $os['af_feitos'] ?>/<?= $os['af_total'] ?></strong></div>
        <div class="progresso-os mt-1"><div style="width:<?= $pc ?>%"></div></div></div>
      <?php else: ?><div class="mt-3 small text-muted"><i class="bi bi-ui-checks me-1"></i>Sem afazeres listados</div><?php endif; ?>
    </div>
    <div class="card-footer bg-white border-0 pt-0 d-flex gap-2">
      <a href="ver_os.php?id=<?= $os['id'] ?>" class="btn btn-success btn-sm flex-fill">
        <i class="bi bi-box-arrow-in-right me-1"></i>Abrir OS (afazeres e fotos)
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
  Nenhuma OS aberta atribuída a você.
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
