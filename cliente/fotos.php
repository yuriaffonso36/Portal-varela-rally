<?php
// cliente/fotos.php – v8: Meu carro (foto 1/4 frontal) · Corridas · Fotos por OS
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
requireRole('cliente');
$u = usuarioLogado();
$area   = 'cliente';
$titulo = 'Fotos';
$sucesso = $erro = '';

// v9: a foto 1/4 frontal (capa) do carro agora é enviada só pelo admin, na página do veículo.

$veiculos = $pdo->prepare("SELECT * FROM veiculos WHERE cliente_id=? ORDER BY id"); $veiculos->execute([$u['id']]); $veiculos = $veiculos->fetchAll();

// Fotos de corridas: gerais da corrida + as marcadas no meu carro
$fc = $pdo->prepare("
    SELECT rf.*, r.nome AS rally_nome, r.data_inicio
    FROM rally_fotos rf
    JOIN rallys r ON r.id=rf.rally_id
    WHERE rf.rally_id IN (SELECT rally_id FROM rally_participantes WHERE cliente_id=?)
      AND (rf.participante_id IS NULL OR rf.participante_id IN (SELECT id FROM rally_participantes WHERE cliente_id=?))
    ORDER BY r.data_inicio DESC, rf.criado_em DESC");
$fc->execute([$u['id'], $u['id']]);
$corridas = [];
foreach ($fc->fetchAll() as $f) { $corridas[$f['rally_id']]['nome'] = $f['rally_nome']; $corridas[$f['rally_id']]['data'] = $f['data_inicio']; $corridas[$f['rally_id']]['fotos'][] = $f; }

// Fotos por OS (só aprovadas)
$filtroOS = (int)($_GET['os'] ?? 0);
$minhasOS = $pdo->prepare("
    SELECT os.id, os.titulo, os.numero_os, os.numero_cliente, os.status, os.criado_em, v.marca, v.modelo,
           (SELECT COUNT(*) FROM fotos f WHERE f.ordem_servico_id=os.id AND f.aprovada=1) AS qtd
    FROM ordens_servico os LEFT JOIN veiculos v ON v.id=os.veiculo_id
    WHERE os.cliente_id=? ORDER BY os.criado_em DESC");
$minhasOS->execute([$u['id']]); $minhasOS = $minhasOS->fetchAll();
$osComFotos = array_values(array_filter($minhasOS, fn($o) => $o['qtd'] > 0 && (!$filtroOS || $o['id'] == $filtroOS)));
$totalFotosOS = array_sum(array_column($minhasOS, 'qtd'));

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-images me-2 text-danger"></i>Fotos</h5>
  <div class="d-flex gap-2 small">
    <a href="#meu-carro" class="btn btn-sm btn-light border">Meu carro</a>
    <a href="#corridas" class="btn btn-sm btn-light border">Corridas <span class="badge bg-secondary"><?= array_sum(array_map(fn($c)=>count($c['fotos']),$corridas)) ?></span></a>
    <a href="#ordens" class="btn btn-sm btn-light border">Ordens de serviço <span class="badge bg-secondary"><?= $totalFotosOS ?></span></a>
  </div>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- MEU CARRO -->
<h6 class="fw-bold mt-4 mb-3" id="meu-carro"><i class="bi bi-car-front me-2"></i>Meu carro</h6>
<div class="row g-3 mb-4">
  <?php foreach ($veiculos as $v): [$t, $full] = veiculoCapa($v); ?>
  <div class="col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm overflow-hidden">
      <?php if ($full): ?>
      <div class="carro-capa" style="border-radius:0"><img src="<?= $full ?>" data-galeria="carro" data-src="<?= $full ?>" data-legenda="<?= htmlspecialchars($v['marca'].' '.$v['modelo']) ?>" style="cursor:pointer">
        <div class="info"><div class="fw-bold"><?= htmlspecialchars($v['marca'].' '.$v['modelo']) ?></div><div class="small opacity-75"><?= htmlspecialchars(trim($v['ano'].' · '.$v['placa'],' ·')) ?></div></div></div>
      <?php else: ?>
      <div class="carro-capa vazia" style="border-radius:0"><i class="bi bi-camera fs-1 mb-2"></i><div class="small">Foto 1/4 frontal do carro</div></div>
      <div class="px-3 pt-2 fw-bold"><?= htmlspecialchars($v['marca'].' '.$v['modelo']) ?></div>
      <?php endif; ?>
      <div class="card-body py-2">
        <a href="carros.php#v<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-clock-history me-1"></i>Histórico e quilometragem</a>
        <?php if (!$full): ?><div class="form-text text-center">A foto do carro é colocada pela equipe Varela.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$veiculos): ?><div class="col-12 text-muted small">Nenhum veículo cadastrado. Fale com a equipe.</div><?php endif; ?>
</div>

<!-- CORRIDAS -->
<h6 class="fw-bold mt-4 mb-3" id="corridas"><i class="bi bi-flag me-2"></i>Corridas</h6>
<?php if ($corridas): foreach ($corridas as $rid => $c): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <a href="ver_corrida.php?id=<?= $rid ?>" class="fw-semibold text-decoration-none text-dark"><i class="bi bi-flag-fill text-danger me-2"></i><?= htmlspecialchars($c['nome']) ?></a>
    <span class="small text-muted"><?= $c['data'] ? date('d/m/Y', strtotime($c['data'])) : '' ?> · <?= count($c['fotos']) ?> foto(s)</span>
  </div>
  <div class="card-body"><div class="thumb-grid">
    <?php foreach ($c['fotos'] as $f): ?>
    <div class="th"><img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="r<?= $rid ?>" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($f['descricao'] ?? '') ?>"></div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php endforeach; else: ?>
<div class="card border-0 shadow-sm text-center py-4 text-muted small mb-4"><i class="bi bi-flag fs-3 d-block mb-1"></i>As fotos das corridas aparecerão aqui.</div>
<?php endif; ?>

<!-- ORDENS DE SERVIÇO -->
<div class="d-flex justify-content-between align-items-center mt-4 mb-3 flex-wrap gap-2" id="ordens">
  <h6 class="fw-bold mb-0"><i class="bi bi-wrench me-2"></i>Ordens de serviço</h6>
  <form method="get" action="fotos.php#ordens">
    <select name="os" class="form-select form-select-sm" style="min-width:240px" onchange="this.form.submit()">
      <option value="">Todas as ordens com fotos</option>
      <?php foreach ($minhasOS as $o): if (!$o['qtd']) continue; ?>
      <option value="<?= $o['id'] ?>" <?= $filtroOS == $o['id'] ? 'selected' : '' ?>>OS <?= htmlspecialchars($o['numero_os'] ?? $o['numero_cliente']) ?> – <?= htmlspecialchars($o['titulo']) ?> (<?= $o['qtd'] ?>)</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($osComFotos): ?>
<div class="accordion" id="accOS">
<?php foreach ($osComFotos as $k => $o):
    $fotos = osFotos($pdo, (int)$o['id'], true);
    $vis = $pdo->prepare("SELECT id FROM os_itens WHERE ordem_servico_id=? AND status_aprovacao='aprovado'"); $vis->execute([$o['id']]);
    $vis = array_flip(array_column($vis->fetchAll(), 'id'));
    foreach ($fotos as &$fx) $fx['itens'] = array_values(array_filter($fx['itens'], fn($it) => isset($vis[$it['id']]))); unset($fx);
    $grupos = [];
    foreach ($fotos as $f) {
        if ($f['itens']) foreach ($f['itens'] as $it) $grupos[$it['descricao']][] = $f;
        elseif ($f['afazer_desc']) $grupos[$f['afazer_desc']][] = $f;
        else $grupos['Gerais'][] = $f;
    }
    $capa = $fotos[0] ?? null; ?>
  <div class="accordion-item border-0 shadow-sm mb-2 rounded overflow-hidden">
    <h2 class="accordion-header">
      <button class="accordion-button <?= ($k === 0 || $filtroOS) ? '' : 'collapsed' ?> bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#os<?= $o['id'] ?>">
        <div class="d-flex align-items-center gap-3 w-100 me-2">
          <?php if ($capa): ?><img src="<?= fotoThumbUrl($capa['thumb'], $capa['caminho']) ?>" style="width:64px;height:48px;object-fit:cover;border-radius:6px"><?php endif; ?>
          <div class="flex-fill">
            <div class="fw-semibold"><span class="badge bg-dark me-1">OS <?= htmlspecialchars($o['numero_os'] ?? $o['numero_cliente']) ?></span><?= htmlspecialchars($o['titulo']) ?></div>
            <div class="small text-muted"><?= date('d/m/Y', strtotime($o['criado_em'])) ?><?= $o['marca'] ? ' · '.htmlspecialchars($o['marca'].' '.$o['modelo']) : '' ?> · <span class="badge badge-<?= $o['status'] ?>"><?= $statusLabel[$o['status']] ?></span></div>
          </div>
          <span class="badge bg-secondary"><?= $o['qtd'] ?> foto(s)</span>
        </div>
      </button>
    </h2>
    <div id="os<?= $o['id'] ?>" class="accordion-collapse collapse <?= ($k === 0 || $filtroOS) ? 'show' : '' ?>">
      <div class="accordion-body">
        <?php foreach ($grupos as $nome => $lista): ?>
        <div class="small fw-semibold mb-1 mt-2"><?= htmlspecialchars($nome) ?> <span class="text-muted fw-normal">(<?= count($lista) ?>)</span></div>
        <div class="thumb-grid mb-2">
          <?php foreach ($lista as $f): ?>
          <div class="th"><img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="os<?= $o['id'] ?>" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($nome.($f['descricao'] ? ' · '.$f['descricao'] : '')) ?>"></div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <a href="ver_os.php?id=<?= $o['id'] ?>" class="small">Abrir a OS <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-4 text-muted small"><i class="bi bi-camera-slash fs-3 d-block mb-1"></i>Nenhuma foto de OS disponível ainda.</div>
<?php endif; ?>

<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
