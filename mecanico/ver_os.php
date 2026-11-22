<?php
// mecanico/ver_os.php – v8: Afazeres + itens encontrados + fotos (somente dentro da OS, sem valores)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/veiculos.php';
requireRole('mecanico');
$u = usuarioLogado();
$area   = 'mecanico';
$titulo = 'Detalhes da OS';

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT os.*, uc.nome AS cliente_nome, v.marca, v.modelo, v.placa, v.foto_capa, v.foto_capa_thumb, v.km_atual, r.nome AS rally_nome
    FROM ordens_servico os
    JOIN usuarios uc ON uc.id=os.cliente_id
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN rallys r ON r.id=os.rally_id
    WHERE os.id=? AND os.mecanico_id=? AND os.status NOT IN ('concluida','cancelada')");
$stmt->execute([$osId, $u['id']]);
$os = $stmt->fetch();
if (!$os) { header('Location: index.php'); exit; }

$sucesso = $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'set_km') {
        $km = kmParse($_POST['km'] ?? '');
        if (!$os['veiculo_id']) $erro = 'Esta OS não tem veículo vinculado. Avise o administrador.';
        elseif ($km === null) $erro = 'Informe a km do painel.';
        else {
            $r = veiculoRegistrarKm($pdo, (int)$os['veiculo_id'], $km, $osId, $u['id'], 'os', !empty($_POST['confirmar_menor']));
            if ($r['ok']) $sucesso = 'Km registrada: ' . kmFmt($km);
            else { $erro = $r['aviso']; if ($r['pedir_confirmacao']) $_SESSION['mec_km_conf'] = $km; }
        }
    }
    elseif ($acao === 'toggle_afazer') {
        $pdo->prepare("UPDATE os_afazeres SET concluido=1-concluido, concluido_por=IF(concluido=1,?,NULL), concluido_em=IF(concluido=1,NOW(),NULL) WHERE id=? AND ordem_servico_id=?")
            ->execute([$u['id'], (int)$_POST['afazer_id'], $osId]);
    }
    elseif ($acao === 'add_item') {
        $desc = trim($_POST['descricao'] ?? '');
        if ($desc) {
            $pdo->prepare("INSERT INTO os_itens (ordem_servico_id,descricao,valor,origem,status_aprovacao,solicitado_por) VALUES (?,?,NULL,'mecanico','pendente',?)")->execute([$osId, $desc, $u['id']]);
            $itemId = (int)$pdo->lastInsertId();
            [$nf, $errs] = osSalvarFotos($pdo, $osId, $_FILES['fotos'] ?? [], $u['id'], false, null, $itemId);
            $sucesso = 'Item enviado para aprovação do administrador!' . ($nf ? " $nf foto(s) enviada(s)." : '');
            if ($errs) $erro = implode(' ', $errs);
        } else $erro = 'Informe a descrição do item.';
    }
    elseif ($acao === 'toggle_item') {
        $pdo->prepare("UPDATE os_itens SET concluido=1-concluido WHERE id=? AND ordem_servico_id=? AND origem='mecanico'")->execute([(int)$_POST['item_id'], $osId]);
    }
    elseif ($acao === 'del_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $c = $pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE id=? AND ordem_servico_id=? AND origem='mecanico' AND valor IS NULL AND status_aprovacao='pendente'");
        $c->execute([$itemId, $osId]);
        if ($c->fetchColumn()) {
            $pdo->prepare("DELETE FROM os_item_fotos WHERE item_id=?")->execute([$itemId]);
            $pdo->prepare("DELETE FROM os_itens WHERE id=?")->execute([$itemId]);
            $sucesso = 'Item removido.';
        }
    }
    elseif ($acao === 'upload_fotos') {
        // Destino obrigatório: esta OS. Opcional: um afazer ou um item encontrado por ele.
        $afId = (int)($_POST['afazer_id'] ?? 0) ?: null;
        $itemId = (int)($_POST['item_id'] ?? 0) ?: null;
        if ($afId) { $c = $pdo->prepare("SELECT COUNT(*) FROM os_afazeres WHERE id=? AND ordem_servico_id=?"); $c->execute([$afId, $osId]); if (!$c->fetchColumn()) $afId = null; }
        if ($itemId) { $c = $pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE id=? AND ordem_servico_id=? AND origem='mecanico'"); $c->execute([$itemId, $osId]); if (!$c->fetchColumn()) $itemId = null; }
        [$nf, $errs] = osSalvarFotos($pdo, $osId, $_FILES['fotos'] ?? [], $u['id'], false, trim($_POST['descricao'] ?? ''), $itemId, $afId);
        if ($nf) $sucesso = "$nf foto(s) enviada(s)! Aguardando aprovação do administrador.";
        else $erro = 'Nenhuma foto válida enviada.';
        if ($errs) $erro = implode(' ', $errs);
    }
    elseif ($acao === 'apagar_foto') {
        // Mecânico só apaga as próprias fotos ainda não aprovadas
        $fid = (int)($_POST['foto_id'] ?? 0);
        $c = $pdo->prepare("SELECT COUNT(*) FROM fotos WHERE id=? AND ordem_servico_id=? AND enviado_por=? AND aprovada=0"); $c->execute([$fid, $osId, $u['id']]);
        if ($c->fetchColumn()) { osApagarFoto($pdo, $fid, $osId); $sucesso = 'Foto removida.'; }
    }

    $_SESSION['flash_mec'] = [$sucesso, $erro];
    header('Location: ver_os.php?id=' . $osId . (isset($_POST['ancora']) ? '#' . preg_replace('/[^a-z0-9_-]/i', '', $_POST['ancora']) : '')); exit;
}
if (!empty($_SESSION['flash_mec'])) { [$sucesso, $erro] = $_SESSION['flash_mec']; unset($_SESSION['flash_mec']); }

$afazeres = osAfazeres($pdo, $osId); [$afT, $afF, $afPct] = osProgresso($afazeres);
$fotos = osFotos($pdo, $osId); $fotosItem = osFotosPorItem($fotos); $fotosAfazer = osFotosPorAfazer($fotos);
$itensMec = $pdo->prepare("SELECT * FROM os_itens WHERE ordem_servico_id=? AND origem='mecanico' ORDER BY criado_em ASC");
$itensMec->execute([$osId]); $itensMec = $itensMec->fetchAll();

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça'];
[$capaThumb, $capaFull] = veiculoCapa($os);
require_once '../includes/header.php';

// Botão de câmera que abre o modal de upload já com o destino
function botaoCamera(string $tipo, int $id, string $titulo, int $qtd): string {
    $cls = 'btn-foto' . ($qtd ? ' tem' : '');
    $b = $qtd ? '<span class="qtd">' . $qtd . '</span>' : '';
    return '<button type="button" class="' . $cls . '" title="Adicionar foto" onclick="abrirUpload(\'' . $tipo . '\',' . $id . ',' . htmlspecialchars(json_encode($titulo, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"><i class="bi bi-camera' . ($qtd ? '-fill' : '') . '"></i><i class="bi bi-plus" style="font-size:.6rem"></i>' . $b . '</button>';
}
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div class="d-flex gap-3 align-items-center">
    <?php if ($capaThumb): ?><img src="<?= $capaThumb ?>" class="carro-mini" style="width:84px;height:56px;cursor:pointer" data-galeria="capa" data-src="<?= $capaFull ?>"><?php endif; ?>
    <div>
      <a href="index.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
      <h5 class="fw-bold mb-0 mt-1"><span class="badge bg-dark me-2">OS <?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span><?= htmlspecialchars($os['titulo']) ?></h5>
      <div class="text-muted small mt-1">
        <i class="bi bi-person me-1"></i><?= htmlspecialchars($os['cliente_nome']) ?>
        <?php if ($os['marca']): ?> · <i class="bi bi-car-front me-1"></i><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?><?= $os['placa'] ? ' <code>'.htmlspecialchars($os['placa']).'</code>' : '' ?><?php endif; ?>
        · <?= ($os['local'] ?? 'oficina') === 'rally' ? '<i class="bi bi-flag me-1"></i>' : '<i class="bi bi-wrench me-1"></i>' ?><?= htmlspecialchars(osLocalLabel($os)) ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="badge badge-<?= $os['status'] ?> fs-6"><?= $statusLabel[$os['status']] ?? $os['status'] ?></span>
    <button class="btn btn-success btn-sm" onclick="abrirUpload('geral',0,'Foto geral da OS')"><i class="bi bi-camera me-1"></i>Foto geral</button>
  </div>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if ($os['veiculo_id']): $kmConf = $_SESSION['mec_km_conf'] ?? null; unset($_SESSION['mec_km_conf']); ?>
<div class="card border-0 shadow-sm mb-4 <?= $os['km_entrada'] === null ? 'border-start border-warning border-3' : '' ?>" id="km">
  <div class="card-body py-3">
    <form method="post" class="row g-2 align-items-center m-0">
      <input type="hidden" name="acao" value="set_km"><input type="hidden" name="ancora" value="km">
      <div class="col-md-4">
        <div class="fw-semibold"><i class="bi bi-speedometer2 me-2 text-danger"></i>Km do painel na entrada</div>
        <div class="small text-muted"><?= $os['km_entrada'] !== null ? 'Registrada: <strong>' . kmFmt($os['km_entrada']) . '</strong> — pode corrigir.' : 'Ainda não informada. Olhe o painel e registre.' ?></div>
      </div>
      <div class="col-7 col-md-3"><div class="input-group"><input type="text" name="km" class="form-control" inputmode="numeric" placeholder="ex.: 1.400" value="<?= htmlspecialchars($kmConf !== null ? number_format($kmConf, 0, ',', '.') : ($os['km_entrada'] !== null ? number_format($os['km_entrada'], 0, ',', '.') : '')) ?>" required><span class="input-group-text">km</span></div></div>
      <div class="col-5 col-md-2"><button class="btn btn-danger w-100"><i class="bi bi-check2 me-1"></i>Salvar</button></div>
      <?php if ($kmConf !== null): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirmar_menor" value="1" id="confKm"><label class="form-check-label" for="confKm">Confirmo que o painel mostra essa km mesmo (menor que a anterior)</label></div></div><?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Afazeres -->
  <div class="col-lg-7" id="afazeres">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-ui-checks me-2 text-success"></i>Afazeres desta OS</span>
          <span class="small fw-semibold"><?= $afF ?>/<?= $afT ?></span>
        </div>
        <?php if ($afT): ?><div class="progresso-os mt-2"><div style="width:<?= $afPct ?>%"></div></div><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($afazeres): foreach ($afazeres as $a): $qf = count($fotosAfazer[$a['id']] ?? []); ?>
        <div class="afazer <?= $a['concluido'] ? 'feito' : '' ?>">
          <form method="post" class="m-0"><input type="hidden" name="acao" value="toggle_afazer"><input type="hidden" name="afazer_id" value="<?= $a['id'] ?>"><input type="hidden" name="ancora" value="afazeres">
            <button class="btn p-0 border-0 bg-transparent chk" title="<?= $a['concluido'] ? 'Marcar como pendente' : 'Marcar como feito' ?>" style="font-size:1.6rem"><?= $a['concluido'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>' ?></button></form>
          <div class="flex-fill">
            <div class="txt fw-semibold"><?= htmlspecialchars($a['descricao']) ?></div>
            <?php if ($a['observacao']): ?><div class="small text-muted"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($a['observacao']) ?></div><?php endif; ?>
            <?php if ($a['concluido']): ?><div class="meta">Feito por <?= htmlspecialchars($a['concluido_nome'] ?? '') ?> em <?= date('d/m H:i', strtotime($a['concluido_em'])) ?></div><?php endif; ?>
          </div>
          <?= botaoCamera('afazer', (int)$a['id'], $a['descricao'], $qf) ?>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted text-center py-4 mb-0"><i class="bi bi-inbox d-block fs-3 mb-1"></i>O chefe de mecânica ainda não listou os afazeres desta OS.</p>
        <?php endif; ?>
      </div>
      <?php if ($afazeres): ?><div class="card-footer bg-light small text-muted"><i class="bi bi-info-circle me-1"></i>Toque no círculo para marcar como feito. Use <i class="bi bi-camera"></i> para registrar a foto daquele afazer.</div><?php endif; ?>
    </div>
  </div>

  <!-- Itens encontrados -->
  <div class="col-lg-5" id="encontrados">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-search me-2 text-warning"></i>Encontrei na revisão</span>
        <span class="badge bg-warning text-dark"><?= count($itensMec) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($itensMec): foreach ($itensMec as $item): $qf = count($fotosItem[$item['id']] ?? []); ?>
        <div class="afazer <?= $item['concluido'] ? 'feito' : '' ?>">
          <form method="post" class="m-0"><input type="hidden" name="acao" value="toggle_item"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
            <button class="btn p-0 border-0 bg-transparent chk"><?= $item['concluido'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>' ?></button></form>
          <div class="flex-fill txt"><span class="<?= $item['status_aprovacao'] === 'recusado' ? 'text-decoration-line-through text-muted' : '' ?>"><?= htmlspecialchars($item['descricao']) ?></span>
            <?php if ($item['status_aprovacao'] === 'pendente'): ?><div class="meta"><span class="badge bg-warning text-dark">Aguardando aprovação</span></div>
            <?php elseif ($item['status_aprovacao'] === 'recusado'): ?><div class="meta text-danger">Recusado<?= $item['motivo_recusa'] ? ': ' . htmlspecialchars($item['motivo_recusa']) : '' ?></div>
            <?php else: ?><div class="meta text-success"><i class="bi bi-check2"></i> Aprovado</div><?php endif; ?></div>
          <?= botaoCamera('item', (int)$item['id'], $item['descricao'], $qf) ?>
          <?php if ($item['valor'] === null && $item['status_aprovacao'] === 'pendente'): ?>
          <form method="post" class="m-0"><input type="hidden" name="acao" value="del_item"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
            <button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover este item?"><i class="bi bi-trash3"></i></button></form>
          <?php endif; ?>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted text-center py-3 mb-0 small">Nenhum item registrado ainda.</p>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-light border-top">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="add_item"><input type="hidden" name="ancora" value="encontrados">
          <label class="form-label fw-semibold mb-1 small">Registrar problema / item encontrado</label>
          <input type="text" name="descricao" class="form-control mb-2" placeholder="Ex: Pastilha desgastada, coifa rasgada..." required>
          <div class="d-flex gap-2">
            <label class="btn btn-outline-secondary btn-sm mb-0 flex-fill"><i class="bi bi-camera me-1"></i>Foto (opcional)
              <input type="file" name="fotos[]" accept="image/*" capture="environment" multiple hidden data-reduzir data-preview="prevNovoItem"></label>
            <button type="submit" class="btn btn-success btn-sm flex-fill"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
          </div>
          <div id="prevNovoItem" class="d-flex gap-1 flex-wrap mt-2"></div>
          <div class="form-text"><i class="bi bi-info-circle me-1"></i>O item vai para aprovação do administrador antes de aparecer para o cliente.</div>
        </form>
      </div>
    </div>
  </div>

  <!-- Fotos da OS -->
  <div class="col-12" id="fotos">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-images me-2 text-primary"></i>Fotos desta OS <span class="badge bg-secondary ms-1"><?= count($fotos) ?></span></span>
        <button class="btn btn-sm btn-outline-success" onclick="abrirUpload('geral',0,'Foto geral da OS')"><i class="bi bi-camera me-1"></i>Enviar foto geral</button>
      </div>
      <div class="card-body">
        <?php if ($fotos): ?>
        <div class="thumb-grid">
          <?php foreach ($fotos as $f):
            $tag = $f['afazer_desc'] ?: ($f['itens'][0]['descricao'] ?? 'Geral'); ?>
          <div>
            <div class="th">
              <img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="os" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($tag . ($f['descricao'] ? ' · ' . $f['descricao'] : '')) ?>">
              <span class="st badge <?= $f['aprovada'] ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.58rem"><?= $f['aprovada'] ? 'Aprovada' : 'Aguardando' ?></span>
              <span class="tag"><?= htmlspecialchars($tag) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-1">
              <span class="text-muted" style="font-size:.68rem"><?= date('d/m H:i', strtotime($f['criado_em'])) ?></span>
              <?php if (!$f['aprovada'] && $f['enviado_por'] == $u['id']): ?>
              <form method="post" class="m-0"><input type="hidden" name="acao" value="apagar_foto"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><input type="hidden" name="ancora" value="fotos">
                <button class="btn btn-link btn-sm text-danger p-0" data-confirm="Apagar esta foto?"><i class="bi bi-trash3"></i></button></form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4 mb-0"><i class="bi bi-camera-slash d-block fs-2 mb-2"></i>Nenhuma foto ainda.</p>
        <?php endif; ?>
        <div class="small text-muted mt-3"><i class="bi bi-shield-check me-1"></i>As fotos ficam guardadas nesta OS e só aparecem para o cliente depois da aprovação do administrador.</div>
      </div>
    </div>
  </div>
</div>

<!-- Modal de envio de fotos -->
<div class="modal fade" id="modalUpload" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <div><div class="small text-muted">OS <?= htmlspecialchars($os['numero_os'] ?? '') ?> · <?= htmlspecialchars($os['titulo']) ?></div>
      <h6 class="modal-title fw-bold" id="upTitulo"></h6></div>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="upload_fotos"><input type="hidden" name="ancora" value="fotos">
      <input type="hidden" name="afazer_id" id="upAfazer"><input type="hidden" name="item_id" id="upItem">
      <div class="modal-body">
        <div class="d-grid gap-2 mb-2">
          <label class="btn btn-success mb-0"><i class="bi bi-camera-fill me-2"></i>Tirar foto
            <input type="file" name="fotos[]" accept="image/*" capture="environment" multiple hidden data-reduzir data-preview="upPrev" id="upCam"></label>
          <label class="btn btn-outline-secondary mb-0"><i class="bi bi-images me-2"></i>Escolher da galeria
            <input type="file" name="fotos_galeria[]" accept="image/*" multiple hidden data-reduzir data-preview="upPrev" id="upGal"></label>
        </div>
        <div id="upPrev" class="d-flex gap-1 flex-wrap mb-2"></div>
        <input type="text" name="descricao" class="form-control" placeholder="Observação (opcional)">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Enviar para aprovação</button></div>
    </form>
  </div></div>
</div>

<?php fotosModalGaleria(); ?>
<script>
function abrirUpload(tipo,id,titulo){
  document.getElementById('upTitulo').innerHTML=(tipo==='afazer'?'<i class="bi bi-check2-square me-1"></i>':tipo==='item'?'<i class="bi bi-search me-1"></i>':'<i class="bi bi-image me-1"></i>')+titulo.replace(/</g,'&lt;');
  document.getElementById('upAfazer').value=tipo==='afazer'?id:'';
  document.getElementById('upItem').value=tipo==='item'?id:'';
  document.getElementById('upCam').value=''; document.getElementById('upGal').value=''; document.getElementById('upPrev').innerHTML='';
  new bootstrap.Modal(document.getElementById('modalUpload')).show();
}
// Usa o arquivo da galeria quando a câmera não foi usada
document.querySelector('#modalUpload form').addEventListener('submit',function(e){
  const cam=document.getElementById('upCam'), gal=document.getElementById('upGal');
  if(!cam.files.length && gal.files.length){ gal.name='fotos[]'; cam.name='fotos_cam[]'; }
  if(!cam.files.length && !gal.files.length){ e.preventDefault(); alert('Tire ou escolha ao menos uma foto.'); }
});
</script>

<?php require_once '../includes/footer.php'; ?>
