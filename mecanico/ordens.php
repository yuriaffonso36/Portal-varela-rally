<?php
// mecanico/ordens.php – OS do mecânico (sem fechadas)
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('mecanico');
$u = usuarioLogado();
$area   = 'mecanico';
$titulo = 'Minhas Ordens';

$stmt = $pdo->prepare("
    SELECT os.*, u.nome AS cliente_nome, v.marca, v.modelo, v.placa,
           (SELECT COUNT(*) FROM fotos f WHERE f.ordem_servico_id=os.id) AS total_fotos,
           (SELECT COUNT(*) FROM os_itens oi WHERE oi.ordem_servico_id=os.id AND oi.origem='mecanico') AS itens_mec,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos
    FROM ordens_servico os
    JOIN usuarios u ON u.id=os.cliente_id
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    WHERE os.mecanico_id=? AND os.status NOT IN ('concluida','cancelada')
    ORDER BY os.criado_em DESC");
$stmt->execute([$u['id']]);
$ordens = $stmt->fetchAll();

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça'];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-clipboard-list me-2 text-success"></i>Minhas Ordens de Serviço</h5>
  <span class="badge bg-secondary"><?= count($ordens) ?> ativa(s)</span>
</div>

<?php if ($ordens): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Nº</th><th>Título</th><th>Cliente</th><th>Veículo</th><th>Status</th><th>Afazeres</th><th>Fotos</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($ordens as $os): ?>
      <tr>
        <td><span class="badge bg-dark"><?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span></td>
        <td class="fw-semibold"><?= htmlspecialchars($os['titulo']) ?></td>
        <td><?= htmlspecialchars($os['cliente_nome']) ?></td>
        <td class="small">
          <?= htmlspecialchars(trim($os['marca'].' '.$os['modelo'])) ?>
          <?= $os['placa'] ? '<br><code>'.htmlspecialchars($os['placa']).'</code>' : '' ?>
        </td>
        <td><span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?? $os['status'] ?></span></td>
        <td class="small"><?= $os['af_total'] ? $os['af_feitos'].'/'.$os['af_total'] : '—' ?></td>
        <td><?= $os['total_fotos'] ?></td>
        <td class="d-flex gap-1">
          <a href="ver_os.php?id=<?= $os['id'] ?>" class="btn btn-sm btn-success">
            <i class="bi bi-box-arrow-in-right me-1"></i>Abrir
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
  Nenhuma OS ativa no momento.
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
