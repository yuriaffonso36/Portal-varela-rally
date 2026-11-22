<?php
// cliente/carros.php – v9: Meus carros — cada carro com foto, km atual e histórico próprio de OS
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/veiculos.php';
requireRole('cliente');
$u = usuarioLogado();
$area = 'cliente'; $titulo = 'Meus carros';

$veiculos = $pdo->prepare("SELECT * FROM veiculos WHERE cliente_id=? ORDER BY id"); $veiculos->execute([$u['id']]); $veiculos = $veiculos->fetchAll();
$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-car-front me-2 text-primary"></i>Meus carros</h5>
  <?php if (count($veiculos) > 1): ?><div class="d-flex gap-2 flex-wrap"><?php foreach ($veiculos as $v): ?><a href="#v<?= $v['id'] ?>" class="btn btn-sm btn-light border"><?= htmlspecialchars(trim($v['marca'] . ' ' . $v['modelo'])) ?></a><?php endforeach; ?></div><?php endif; ?>
</div>

<?php if (!$veiculos): ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted"><i class="bi bi-car-front d-block fs-1 mb-2"></i>Nenhum carro cadastrado. Fale com a equipe Varela.</div>
<?php endif; ?>

<?php foreach ($veiculos as $v):
    [$capaThumb, $capaFull] = veiculoCapa($v);
    $ordens = veiculoOrdens($pdo, (int)$v['id']);
    $ultima = $ordens[0] ?? null;
    $nome = trim($v['marca'] . ' ' . $v['modelo']);
?>
<div class="card border-0 shadow-sm mb-4 overflow-hidden" id="v<?= $v['id'] ?>">
  <div class="row g-0">
    <div class="col-md-4">
      <?php if ($capaFull): ?>
      <div class="carro-capa h-100" style="border-radius:0;min-height:200px"><img src="<?= $capaFull ?>" style="cursor:pointer" data-galeria="carro<?= $v['id'] ?>" data-src="<?= $capaFull ?>" data-legenda="<?= htmlspecialchars($nome) ?>"></div>
      <?php else: ?>
      <div class="carro-capa vazia h-100" style="border-radius:0;min-height:200px"><i class="bi bi-car-front fs-1"></i></div>
      <?php endif; ?>
    </div>
    <div class="col-md-8">
      <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <div>
            <h5 class="fw-bold mb-0"><?= htmlspecialchars($nome) ?></h5>
            <div class="small text-muted"><?= htmlspecialchars(trim(($v['ano'] ?? '') . ' · ' . ($v['cor'] ?? '') . ' · ' . ($v['placa'] ?? ''), ' ·')) ?></div>
          </div>
          <div class="text-end">
            <div class="small text-muted text-uppercase fw-semibold">Km atual</div>
            <div class="fs-4 fw-bold"><?= kmFmt($v['km_atual']) ?></div>
          </div>
        </div>
        <div class="row g-2 mt-2 small">
          <div class="col-4"><div class="bg-light rounded p-2"><div class="text-muted">Ordens de serviço</div><div class="fw-bold fs-6"><?= count($ordens) ?></div></div></div>
          <div class="col-8"><div class="bg-light rounded p-2"><div class="text-muted">Última OS</div><div class="fw-semibold text-truncate"><?= $ultima ? htmlspecialchars($ultima['titulo']) . ' · ' . date('d/m/Y', strtotime($ultima['criado_em'])) : '—' ?></div></div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="border-top">
    <?php if ($ordens): ?>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle small">
      <thead><tr><th class="ps-3">Nº OS</th><th>Serviço</th><th>Local/Rally</th><th>Km</th><th>Status</th><th class="text-end pe-3">Data</th></tr></thead>
      <tbody>
      <?php foreach ($ordens as $o): ?>
      <tr class="tr-link" onclick="location.href='ver_os.php?id=<?= $o['id'] ?>'">
        <td class="ps-3"><span class="badge bg-dark"><?= htmlspecialchars($o['numero_os'] ?? $o['numero_cliente']) ?></span></td>
        <td class="fw-semibold"><?= htmlspecialchars($o['titulo']) ?><?php if ($o['af_total']): ?><span class="text-muted fw-normal"> · <?= $o['af_feitos'] ?>/<?= $o['af_total'] ?></span><?php endif; ?></td>
        <td><?= htmlspecialchars(osLocalLabel($o)) ?></td>
        <td class="text-nowrap"><?= kmFmt($o['km_entrada']) ?></td>
        <td><span class="badge badge-<?= $o['status'] ?>"><?= $statusLabel[$o['status']] ?? $o['status'] ?></span></td>
        <td class="text-end pe-3 text-muted"><?= date('d/m/Y', strtotime($o['criado_em'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="text-muted small text-center py-3 mb-0">Nenhuma ordem de serviço para este carro ainda.</p><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
