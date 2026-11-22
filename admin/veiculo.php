<?php
// admin/veiculo.php – v9: página do veículo — dados, foto de capa, km atual, histórico de OS e de quilometragem
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/veiculos.php';
requireRole('admin');
$u = usuarioLogado(); $area = 'admin'; $titulo = 'Veículo';

$vid = (int)($_GET['id'] ?? 0);
$carregar = function () use ($pdo, $vid) {
    $s = $pdo->prepare("SELECT v.*, u.nome AS dono_nome, u.id AS dono_id FROM veiculos v JOIN usuarios u ON u.id=v.cliente_id WHERE v.id=?");
    $s->execute([$vid]); return $s->fetch();
};
$v = $carregar();
if (!$v) { header('Location: clientes.php'); exit; }

$sucesso = $erro = ''; $pedirConfirmacao = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar_dados') {
        $pdo->prepare("UPDATE veiculos SET marca=?,modelo=?,placa=?,ano=?,cor=?,chassi=? WHERE id=?")
            ->execute([trim($_POST['marca'] ?? ''), trim($_POST['modelo'] ?? ''), strtoupper(trim($_POST['placa'] ?? '')), (int)($_POST['ano'] ?? 0) ?: null,
                       trim($_POST['cor'] ?? ''), strtoupper(trim($_POST['chassi'] ?? '')) ?: null, $vid]);
        $sucesso = 'Dados do veículo atualizados!';
    }
    elseif ($acao === 'capa' && !empty($_FILES['foto']['name'])) {
        $r = fotoSalvarUpload($_FILES['foto'], 'veiculos', 'carro' . $vid);
        if (isset($r['erro'])) $erro = $r['erro'];
        else { fotoApagar($v['foto_capa'], $v['foto_capa_thumb']); $pdo->prepare("UPDATE veiculos SET foto_capa=?,foto_capa_thumb=? WHERE id=?")->execute([$r['caminho'], $r['thumb'], $vid]); $sucesso = 'Foto de capa atualizada!'; }
    }
    elseif ($acao === 'registrar_km') {
        $km = kmParse($_POST['km'] ?? '');
        if ($km === null) $erro = 'Informe a quilometragem.';
        else {
            $r = veiculoRegistrarKm($pdo, $vid, $km, null, $u['id'], 'manual', !empty($_POST['confirmar_menor']), trim($_POST['observacao'] ?? '') ?: null);
            if ($r['ok']) $sucesso = 'Km registrada: ' . kmFmt($km);
            else { $erro = $r['aviso']; $pedirConfirmacao = $r['pedir_confirmacao']; }
        }
    }
    elseif ($acao === 'del_leitura') {
        // Leituras de OS são corrigidas na própria OS; aqui só as manuais/de cadastro
        $pdo->prepare("DELETE FROM veiculo_km WHERE id=? AND veiculo_id=? AND ordem_servico_id IS NULL")->execute([(int)($_POST['leitura_id'] ?? 0), $vid]);
        veiculoRecalcularKm($pdo, $vid); $sucesso = 'Leitura removida.';
    }
    if (!$erro) { $_SESSION['flash_veic'] = $sucesso; header('Location: veiculo.php?id=' . $vid); exit; }
    $v = $carregar();
}
if (!empty($_SESSION['flash_veic'])) { $sucesso = $_SESSION['flash_veic']; unset($_SESSION['flash_veic']); }

$ordens   = veiculoOrdens($pdo, $vid);
$leituras = veiculoLeiturasKm($pdo, $vid);
$outros   = $pdo->prepare("SELECT id, marca, modelo, placa FROM veiculos WHERE cliente_id=? AND id<>? ORDER BY marca"); $outros->execute([$v['dono_id'], $vid]); $outros = $outros->fetchAll();
[$capaThumb, $capaFull] = veiculoCapa($v);

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
$totalGasto  = array_sum(array_map(fn($o) => $o['status'] === 'concluida' ? (float)$o['valor_total'] : 0, $ordens));
$ativas      = count(array_filter($ordens, fn($o) => !in_array($o['status'], ['concluida','cancelada'])));

// Gráfico simples da evolução (SVG)
$serie = array_reverse($leituras);
$grafico = '';
if (count($serie) >= 2) {
    $W = 600; $H = 140; $p = 24;
    $kms = array_map(fn($l) => (int)$l['km'], $serie); $min = min($kms); $max = max($kms); $faixa = max(1, $max - $min);
    $n = count($serie); $pts = []; $dots = '';
    foreach ($serie as $i => $l) {
        $x = $p + ($W - 2 * $p) * ($n > 1 ? $i / ($n - 1) : 0);
        $y = $H - $p - ($H - 2 * $p) * (((int)$l['km'] - $min) / $faixa);
        $pts[] = round($x, 1) . ',' . round($y, 1);
        $dots .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4" fill="#dc3545"><title>' . htmlspecialchars(kmFmt($l['km']) . ' · ' . date('d/m/Y', strtotime($l['criado_em']))) . '</title></circle>';
    }
    $grafico = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;max-height:160px"><polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#dc3545" stroke-width="2.5" stroke-linejoin="round"/>' . $dots
             . '<text x="' . $p . '" y="14" font-size="11" fill="#888">' . kmFmt($max) . '</text><text x="' . $p . '" y="' . ($H - 6) . '" font-size="11" fill="#888">' . kmFmt($min) . '</text></svg>';
}

$titulo = trim($v['marca'] . ' ' . $v['modelo']) ?: 'Veículo';
require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="clientes.php?editar=<?= $v['dono_id'] ?>" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($v['dono_nome']) ?></a>
    <h5 class="fw-bold mb-0 mt-1"><i class="bi bi-car-front me-2 text-danger"></i><?= htmlspecialchars($titulo) ?>
      <?php if ($v['placa']): ?><code class="ms-2 fs-6"><?= htmlspecialchars($v['placa']) ?></code><?php endif; ?></h5>
    <?php if ($outros): ?><div class="small mt-1 text-muted">Outros carros do cliente:
      <?php foreach ($outros as $o): ?><a href="veiculo.php?id=<?= $o['id'] ?>" class="badge bg-light text-dark border text-decoration-none ms-1"><?= htmlspecialchars(trim($o['marca'] . ' ' . $o['modelo'] . ' ' . $o['placa'])) ?></a><?php endforeach; ?></div><?php endif; ?>
  </div>
  <a href="ordens.php?nova_veiculo=<?= $vid ?>" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-plus me-1"></i>Nova OS para este carro</a>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-<?= $pedirConfirmacao ? 'warning' : 'danger' ?> py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <!-- Capa -->
    <div class="card border-0 shadow-sm mb-4 overflow-hidden">
      <?php if ($capaThumb): ?>
      <div class="carro-capa" style="border-radius:0"><img src="<?= $capaFull ?>" style="cursor:pointer" data-galeria="capa" data-src="<?= $capaFull ?>" data-legenda="<?= htmlspecialchars($titulo) ?>"></div>
      <?php else: ?>
      <div class="carro-capa vazia" style="border-radius:0;aspect-ratio:4/3"><i class="bi bi-car-front fs-1"></i></div>
      <?php endif; ?>
      <div class="card-body py-2">
        <form method="post" enctype="multipart/form-data" class="m-0">
          <input type="hidden" name="acao" value="capa">
          <label class="btn btn-sm btn-outline-secondary w-100 mb-0"><i class="bi bi-camera me-1"></i><?= $capaThumb ? 'Trocar' : 'Enviar' ?> foto de capa (1/4 frontal)
            <input type="file" name="foto" accept="image/*" hidden data-reduzir data-autoenviar></label>
        </form>
        <div class="small text-muted text-center mt-1">Só o admin envia esta foto. O cliente vê na área dele.</div>
      </div>
    </div>

    <!-- Km -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Km atual</div>
        <div class="display-6 fw-bold"><?= kmFmt($v['km_atual']) ?></div>
        <?php if ($leituras): ?><div class="small text-muted">Última leitura em <?= date('d/m/Y', strtotime($leituras[0]['criado_em'])) ?></div><?php endif; ?>
        <form method="post" class="mt-3">
          <input type="hidden" name="acao" value="registrar_km">
          <div class="input-group input-group-sm mb-2"><input type="text" name="km" class="form-control" placeholder="Km do painel" inputmode="numeric" value="<?= htmlspecialchars($pedirConfirmacao ? ($_POST['km'] ?? '') : '') ?>" required><span class="input-group-text">km</span></div>
          <input type="text" name="observacao" class="form-control form-control-sm mb-2" placeholder="Observação (opcional)" value="<?= htmlspecialchars($pedirConfirmacao ? ($_POST['observacao'] ?? '') : '') ?>">
          <?php if ($pedirConfirmacao): ?><div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="confirmar_menor" value="1" id="cm"><label class="form-check-label" for="cm">Confirmo que a km é menor (ex.: troca de painel)</label></div><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-speedometer2 me-1"></i>Registrar leitura avulsa</button>
        </form>
        <div class="small text-muted mt-2">Leituras feitas na abertura da OS entram aqui sozinhas.</div>
      </div>
    </div>

    <!-- Dados -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom">Dados do veículo</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="acao" value="salvar_dados">
          <div class="row g-2">
            <div class="col-6"><label class="form-label small mb-0">Marca</label><input type="text" name="marca" class="form-control form-control-sm" value="<?= htmlspecialchars($v['marca'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label small mb-0">Modelo</label><input type="text" name="modelo" class="form-control form-control-sm" value="<?= htmlspecialchars($v['modelo'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label small mb-0">Placa</label><input type="text" name="placa" class="form-control form-control-sm" style="text-transform:uppercase" value="<?= htmlspecialchars($v['placa'] ?? '') ?>"></div>
            <div class="col-3"><label class="form-label small mb-0">Ano</label><input type="number" name="ano" class="form-control form-control-sm" value="<?= htmlspecialchars($v['ano'] ?? '') ?>"></div>
            <div class="col-3"><label class="form-label small mb-0">Cor</label><input type="text" name="cor" class="form-control form-control-sm" value="<?= htmlspecialchars($v['cor'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label small mb-0">Chassi</label><input type="text" name="chassi" class="form-control form-control-sm" style="text-transform:uppercase" value="<?= htmlspecialchars($v['chassi'] ?? '') ?>"></div>
          </div>
          <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save me-1"></i>Salvar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="row g-3 mb-4">
      <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="text-muted small">Ordens de serviço</div><div class="fs-4 fw-bold"><?= count($ordens) ?></div></div></div></div>
      <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="text-muted small">Em andamento</div><div class="fs-4 fw-bold"><?= $ativas ?></div></div></div></div>
      <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="text-muted small">Total concluído</div><div class="fs-5 fw-bold text-success">R$ <?= number_format($totalGasto, 2, ',', '.') ?></div></div></div></div>
    </div>

    <?php if ($grafico): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-graph-up me-2 text-danger"></i>Evolução da quilometragem</div>
      <div class="card-body"><?= $grafico ?></div>
    </div>
    <?php endif; ?>

    <!-- Histórico de OS -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-clock-history me-2 text-primary"></i>Histórico de manutenções</div>
      <div class="card-body p-0">
        <?php if ($ordens): ?>
        <div class="table-responsive"><table class="table table-hover mb-0 align-middle">
          <thead><tr><th>Nº OS</th><th>Título</th><th>Local/Rally</th><th>Km</th><th>Status</th><th class="text-end">Valor</th><th>Data</th></tr></thead>
          <tbody>
          <?php foreach ($ordens as $o): ?>
          <tr class="tr-link" onclick="location.href='editar_os.php?id=<?= $o['id'] ?>'">
            <td><span class="badge bg-dark"><?= htmlspecialchars($o['numero_os'] ?? $o['numero_cliente']) ?></span></td>
            <td class="fw-semibold"><?= htmlspecialchars($o['titulo']) ?>
              <?php if ($o['af_total']): ?><div class="small text-muted fw-normal"><?= $o['af_feitos'] ?>/<?= $o['af_total'] ?> afazeres</div><?php endif; ?></td>
            <td class="small"><?= ($o['local'] ?? 'oficina') === 'rally' ? '<i class="bi bi-flag me-1"></i>' : '<i class="bi bi-wrench me-1"></i>' ?><?= htmlspecialchars(osLocalLabel($o)) ?></td>
            <td class="small fw-semibold text-nowrap"><?= kmFmt($o['km_entrada']) ?></td>
            <td><span class="badge badge-<?= $o['status'] ?>"><?= $statusLabel[$o['status']] ?? $o['status'] ?></span></td>
            <td class="text-end small fw-semibold">R$ <?= number_format($o['valor_total'], 2, ',', '.') ?></td>
            <td class="small text-muted"><?= date('d/m/Y', strtotime($o['criado_em'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhuma OS para este carro ainda.</p><?php endif; ?>
      </div>
    </div>

    <!-- Leituras de km -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-speedometer2 me-2 text-secondary"></i>Leituras de km</div>
      <div class="card-body p-0">
        <?php if ($leituras): ?>
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Data</th><th>Km</th><th>Origem</th><th>Registrado por</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($leituras as $l): ?>
          <tr>
            <td class="small"><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
            <td class="fw-semibold"><?= kmFmt($l['km']) ?></td>
            <td class="small"><?php if ($l['ordem_servico_id']): ?><a href="editar_os.php?id=<?= $l['ordem_servico_id'] ?>">OS <?= htmlspecialchars($l['numero_os'] ?? '') ?></a><?php else: ?><?= $l['origem'] === 'cadastro' ? 'Cadastro' : 'Avulsa' ?><?php endif; ?>
              <?php if ($l['observacao']): ?><div class="text-muted"><?= htmlspecialchars($l['observacao']) ?></div><?php endif; ?></td>
            <td class="small text-muted"><?= htmlspecialchars($l['registrado_nome'] ?? '—') ?></td>
            <td class="text-end"><?php if (!$l['ordem_servico_id']): ?><form method="post" class="m-0"><input type="hidden" name="acao" value="del_leitura"><input type="hidden" name="leitura_id" value="<?= $l['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-2" data-confirm="Remover esta leitura?"><i class="bi bi-trash3"></i></button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhuma leitura registrada.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
