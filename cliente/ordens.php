<?php
// cliente/ordens.php – OS com abas Ativas e Histórico
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('cliente');
$u = usuarioLogado();
$area = 'cliente'; $titulo = 'Ordens de Serviço';

$aba = $_GET['aba'] ?? 'ativas';

// OS ativas
$stmtAtivas=$pdo->prepare("
    SELECT os.*,v.marca,v.modelo,v.placa,u.nome AS mecanico_nome,
           (SELECT COUNT(*) FROM fotos f WHERE f.ordem_servico_id=os.id AND f.aprovada=1) AS total_fotos,
           (SELECT COUNT(*) FROM os_itens oi WHERE oi.ordem_servico_id=os.id AND oi.status_aprovacao='aprovado') AS total_itens,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos
    FROM ordens_servico os
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN usuarios u ON u.id=os.mecanico_id
    WHERE os.cliente_id=? AND os.status NOT IN ('concluida','cancelada')
    ORDER BY os.numero_cliente DESC");
$stmtAtivas->execute([$u['id']]); $ativas=$stmtAtivas->fetchAll();

// Histórico
$stmtHist=$pdo->prepare("
    SELECT os.*,v.marca,v.modelo,v.placa,u.nome AS mecanico_nome
    FROM ordens_servico os
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN usuarios u ON u.id=os.mecanico_id
    WHERE os.cliente_id=? AND os.status IN ('concluida','cancelada')
    ORDER BY os.atualizado_em DESC");
$stmtHist->execute([$u['id']]); $historico=$stmtHist->fetchAll();

$statusLabel=['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-clipboard-list me-2 text-primary"></i>Ordens de Serviço</h5>
</div>

<!-- Abas -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $aba==='ativas'?'active':'' ?>" href="?aba=ativas">
      <i class="bi bi-clipboard-check me-1"></i>Ativas
      <?php if(count($ativas)>0): ?>
      <span class="badge bg-primary ms-1"><?= count($ativas) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $aba==='historico'?'active':'' ?>" href="?aba=historico">
      <i class="bi bi-archive me-1"></i>Histórico
      <?php if(count($historico)>0): ?>
      <span class="badge bg-secondary ms-1"><?= count($historico) ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php if($aba==='ativas'): ?>

<?php if($ativas): ?>
<div class="row g-3">
<?php foreach($ativas as $os): ?>
<div class="col-12 col-md-6 col-xl-4">
  <a href="<?= BASE_URL ?>/cliente/ver_os.php?id=<?= $os['id'] ?>" class="text-decoration-none">
    <div class="card border-0 shadow-sm h-100 card-link">
      <div class="card-header bg-white border-bottom d-flex justify-content-between py-2">
        <span class="badge bg-dark"><?= htmlspecialchars($os['numero_os']??$os['numero_cliente']) ?></span>
        <span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?></span>
      </div>
      <div class="card-body">
        <h6 class="fw-bold text-dark mb-2"><?= htmlspecialchars($os['titulo']) ?></h6>
        <div class="small text-muted">
          <?php if($os['marca']): ?>
          <div class="mb-1"><i class="bi bi-car-front me-1"></i><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?></div>
          <?php endif; ?>
          <?php if($os['mecanico_nome']): ?>
          <div class="mb-1"><i class="bi bi-wrench me-1"></i><?= htmlspecialchars($os['mecanico_nome']) ?></div>
          <?php endif; ?>
          <div><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y',strtotime($os['criado_em'])) ?></div>
        </div>
        <?php if($os['af_total']): $pc=round($os['af_feitos']/$os['af_total']*100); ?>
        <div class="mt-3 small"><div class="d-flex justify-content-between text-muted"><span><i class="bi bi-ui-checks me-1"></i>Andamento</span><span><?= $os['af_feitos'] ?>/<?= $os['af_total'] ?></span></div><div class="progresso-os mt-1"><div style="width:<?= $pc ?>%"></div></div></div>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center pt-0">
        <div class="d-flex gap-2">
          <?php if($os['total_itens']>0): ?><span class="badge bg-success"><i class="bi bi-list-check me-1"></i><?= $os['total_itens'] ?></span><?php endif; ?>
          <?php if($os['total_fotos']>0): ?><span class="badge bg-primary"><i class="bi bi-images me-1"></i><?= $os['total_fotos'] ?></span><?php endif; ?>
        </div>
        <span class="text-primary small">Ver detalhes <i class="bi bi-arrow-right"></i></span>
      </div>
    </div>
  </a>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-clipboard2-check d-block fs-1 mb-2 text-success"></i>
  Nenhuma OS ativa no momento.
</div>
<?php endif; ?>

<?php else: // Histórico ?>

<?php if($historico): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Nº</th><th>Serviço</th><th>Veículo</th><th>Status</th><th>Concluída em</th><th></th></tr></thead>
      <tbody>
      <?php foreach($historico as $os): ?>
      <tr class="tr-link" onclick="location.href='<?= BASE_URL ?>/cliente/ver_os.php?id=<?= $os['id'] ?>'">
        <td><span class="badge bg-secondary"><?= htmlspecialchars($os['numero_os']??$os['numero_cliente']) ?></span></td>
        <td class="fw-semibold"><?= htmlspecialchars($os['titulo']) ?></td>
        <td class="small text-muted"><?= htmlspecialchars(trim($os['marca'].' '.$os['modelo'])) ?></td>
        <td><span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?></span></td>
        <td class="small text-muted"><?= date('d/m/Y',strtotime($os['atualizado_em'])) ?></td>
        <td><i class="bi bi-chevron-right text-muted"></i></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-archive d-block fs-1 mb-2"></i>
  Nenhuma OS no histórico ainda.
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
