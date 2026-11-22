<?php
// admin/ver_os.php – Visualização completa da OS pelo Admin
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
requireRole('admin');
$u = usuarioLogado();
$area   = 'admin';
$titulo = 'Visualizar OS';
// v8: a edição completa (afazeres, itens, fotos) fica em editar_os.php

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: ordens.php'); exit; }

// Carrega OS
$stmt = $pdo->prepare("
    SELECT os.*, uc.nome AS cliente_nome, um.nome AS mecanico_nome,
           v.marca, v.modelo, v.placa, v.ano, v.cor
    FROM ordens_servico os
    JOIN usuarios uc ON uc.id = os.cliente_id
    LEFT JOIN usuarios um ON um.id = os.mecanico_id
    LEFT JOIN veiculos v ON v.id = os.veiculo_id
    WHERE os.id = ?");
$stmt->execute([$osId]);
$os = $stmt->fetch();
if (!$os) { header('Location: ordens.php'); exit; }

$sucesso = $erro = '';

// ── AÇÕES ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Aprovar foto
    if ($acao === 'aprovar_foto') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        $pdo->prepare("UPDATE fotos SET aprovada=1 WHERE id=? AND ordem_servico_id=?")
            ->execute([$fotoId, $osId]);
        $sucesso = 'Foto aprovada!';
    }
    // Rejeitar foto
    elseif ($acao === 'rejeitar_foto') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        if (osApagarFoto($pdo, $fotoId, $osId)) $sucesso = 'Foto removida.';
    }
    // Apagar foto aprovada
    elseif ($acao === 'apagar_foto') {
        $fotoId = (int)($_POST['foto_id'] ?? 0);
        if (osApagarFoto($pdo, $fotoId, $osId)) $sucesso = 'Foto apagada.';
    }
}

// Dados
$itensAdmin = $pdo->prepare("SELECT * FROM os_itens WHERE ordem_servico_id=? AND origem='admin' ORDER BY criado_em");
$itensAdmin->execute([$osId]); $itensAdmin = $itensAdmin->fetchAll();

$itensMec = $pdo->prepare("SELECT * FROM os_itens WHERE ordem_servico_id=? AND origem='mecanico' ORDER BY criado_em");
$itensMec->execute([$osId]); $itensMec = $itensMec->fetchAll();

$fotosPendentes = $pdo->prepare("SELECT f.*, u.nome AS enviado_nome FROM fotos f JOIN usuarios u ON u.id=f.enviado_por WHERE f.ordem_servico_id=? AND f.aprovada=0 ORDER BY f.criado_em DESC");
$fotosPendentes->execute([$osId]); $fotosPendentes = $fotosPendentes->fetchAll();

$fotosAprovadas = $pdo->prepare("SELECT f.*, u.nome AS enviado_nome FROM fotos f JOIN usuarios u ON u.id=f.enviado_por WHERE f.ordem_servico_id=? AND f.aprovada=1 ORDER BY f.criado_em DESC");
$fotosAprovadas->execute([$osId]); $fotosAprovadas = $fotosAprovadas->fetchAll();

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];

require_once '../includes/header.php';
?>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="ordens.php" class="text-muted small text-decoration-none">
      <i class="bi bi-arrow-left me-1"></i>Voltar para OS
    </a>
    <h5 class="fw-bold mb-0 mt-1">
      <span class="badge bg-dark me-2">OS #<?= $os['numero_cliente'] ?></span>
      <?= htmlspecialchars($os['titulo']) ?>
    </h5>
    <div class="text-muted small mt-1">
      <i class="bi bi-person me-1"></i><strong><?= htmlspecialchars($os['cliente_nome']) ?></strong>
      <?php if ($os['mecanico_nome']): ?>
        &nbsp;·&nbsp;<i class="bi bi-wrench me-1"></i><?= htmlspecialchars($os['mecanico_nome']) ?>
      <?php endif; ?>
      &nbsp;·&nbsp;<i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($os['criado_em'])) ?>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="badge badge-<?= $os['status'] ?> fs-6"><?= $statusLabel[$os['status']] ?></span>
    <span class="fw-bold fs-5 text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
    <a href="editar_os.php?id=<?= $osId ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pencil-square me-1"></i>Editar OS
    </a>
  </div>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Alerta fotos pendentes -->
<?php if ($fotosPendentes): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-camera-fill fs-5"></i>
  <span><strong><?= count($fotosPendentes) ?> foto(s) aguardando aprovação</strong> — veja abaixo.</span>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- ── Coluna Esquerda ── -->
  <div class="col-lg-5">

    <!-- Dados do Veículo -->
    <?php if ($os['marca']): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom">
        <i class="bi bi-car-front me-2 text-secondary"></i>Veículo
      </div>
      <div class="card-body small">
        <div class="row g-1">
          <div class="col-6"><span class="text-muted">Marca/Modelo:</span><br>
            <strong><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?></strong></div>
          <div class="col-6"><span class="text-muted">Placa:</span><br>
            <code><?= htmlspecialchars($os['placa'] ?? '—') ?></code></div>
          <?php if ($os['ano']): ?>
          <div class="col-6 mt-1"><span class="text-muted">Ano:</span><br>
            <strong><?= $os['ano'] ?></strong></div>
          <?php endif; ?>
          <?php if ($os['cor']): ?>
          <div class="col-6 mt-1"><span class="text-muted">Cor:</span><br>
            <strong><?= htmlspecialchars($os['cor']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Itens do Admin -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-list-check me-2 text-primary"></i>Itens / Serviços / Peças</span>
        <span class="badge bg-primary"><?= count($itensAdmin) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($itensAdmin): ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Descrição</th><th class="text-end">Valor</th></tr></thead>
          <tbody>
          <?php foreach ($itensAdmin as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td class="text-end fw-semibold text-success">
              <?= $item['valor'] ? 'R$ '.number_format($item['valor'],2,',','.') : '<span class="text-muted">—</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td class="fw-bold text-end">Total:</td>
              <td class="text-end fw-bold text-success">
                R$ <?= number_format(array_sum(array_column($itensAdmin,'valor')),2,',','.') ?>
              </td>
            </tr>
          </tfoot>
        </table>
        <?php else: ?><p class="text-muted text-center py-3 mb-0">Nenhum item.</p><?php endif; ?>
      </div>
    </div>

    <!-- Itens do Mecânico -->
    <?php if ($itensMec): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-wrench me-2 text-warning"></i>Itens do Mecânico</span>
        <span class="badge bg-warning text-dark"><?= count($itensMec) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Descrição</th><th class="text-center">Feito</th><th class="text-end">Valor</th></tr></thead>
          <tbody>
          <?php foreach ($itensMec as $item): ?>
          <tr class="<?= $item['valor'] === null ? 'table-warning' : '' ?>">
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td class="text-center">
              <?php if ($item['concluido']): ?>
                <i class="bi bi-check-circle-fill text-success"></i>
              <?php else: ?>
                <i class="bi bi-circle text-muted"></i>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold">
              <?= $item['valor'] !== null ? 'R$ '.number_format($item['valor'],2,',','.') : '<span class="text-muted small">Sem valor</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── Coluna Direita: Fotos ── -->
  <div class="col-lg-7">

    <!-- Fotos pendentes de aprovação -->
    <?php if ($fotosPendentes): ?>
    <div class="card border-0 shadow-sm mb-3 border-warning border-2">
      <div class="card-header bg-warning bg-opacity-10 fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-hourglass-split me-2 text-warning"></i>Fotos Aguardando Aprovação</span>
        <span class="badge bg-warning text-dark"><?= count($fotosPendentes) ?></span>
      </div>
      <div class="card-body">
        <div class="row g-2">
          <?php foreach ($fotosPendentes as $f): ?>
          <div class="col-6 col-md-4">
            <div class="position-relative">
              <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($f['caminho']) ?>"
                   class="w-100 rounded foto-thumb"
                   style="height:130px;object-fit:cover;cursor:pointer"
                   data-src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($f['caminho']) ?>"
                   data-desc="<?= htmlspecialchars($f['descricao'] ?? '') ?>">
              <span class="badge bg-warning text-dark position-absolute top-0 start-0 m-1" style="font-size:.65rem">
                Pendente
              </span>
            </div>
            <div class="small text-muted mt-1"><?= htmlspecialchars($f['enviado_nome']) ?></div>
            <?php if ($f['descricao']): ?>
            <div class="small text-truncate"><?= htmlspecialchars($f['descricao']) ?></div>
            <?php endif; ?>
            <div class="d-flex gap-1 mt-1">
              <form method="post" class="flex-fill">
                <input type="hidden" name="acao" value="aprovar_foto">
                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm w-100">
                  <i class="bi bi-check-lg"></i> Aprovar
                </button>
              </form>
              <form method="post">
                <input type="hidden" name="acao" value="rejeitar_foto">
                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        data-confirm="Rejeitar e apagar esta foto?">
                  <i class="bi bi-x-lg"></i>
                </button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Fotos aprovadas -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-images me-2 text-primary"></i>Fotos Aprovadas</span>
        <span class="badge bg-primary"><?= count($fotosAprovadas) ?></span>
      </div>
      <div class="card-body">
        <?php if ($fotosAprovadas): ?>
        <div class="row g-2">
          <?php foreach ($fotosAprovadas as $f): ?>
          <div class="col-6 col-md-4">
            <div class="position-relative foto-card">
              <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($f['caminho']) ?>"
                   class="w-100 rounded foto-thumb"
                   style="height:130px;object-fit:cover;cursor:pointer"
                   data-src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($f['caminho']) ?>"
                   data-desc="<?= htmlspecialchars($f['descricao'] ?? '') ?>">
              <!-- Botão apagar (só admin) -->
              <form method="post" class="position-absolute top-0 end-0 m-1">
                <input type="hidden" name="acao" value="apagar_foto">
                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm py-0 px-1"
                        style="font-size:.75rem"
                        data-confirm="Apagar esta foto permanentemente?">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </div>
            <div class="small text-muted mt-1"><?= htmlspecialchars($f['enviado_nome']) ?></div>
            <?php if ($f['descricao']): ?>
            <div class="small text-truncate"><?= htmlspecialchars($f['descricao']) ?></div>
            <?php endif; ?>
            <div class="small text-muted" style="font-size:.72rem">
              <?= date('d/m/Y H:i', strtotime($f['criado_em'])) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4 mb-0">
          <i class="bi bi-camera-slash d-block fs-2 mb-2"></i>Nenhuma foto aprovada.
        </p>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Lightbox -->
<div class="modal fade" id="modalFoto" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 bg-black">
      <div class="modal-header border-0 bg-black">
        <h6 class="modal-title text-white" id="modalFotoDesc"></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-1 text-center">
        <img id="modalFotoImg" src="" class="img-fluid" style="max-height:80vh">
      </div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
