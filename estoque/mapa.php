<?php
// estoque/mapa.php – Fase 4: mapa visual do estoque — estantes, prateleiras e onde está cada peça
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/estoque_mapa.php';
requireRole('estoque', 'admin');
$u = usuarioLogado(); $area = 'estoque'; $titulo = 'Mapa do estoque';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $msg = ''; $err = '';
    if ($acao === 'estante') {
        $cod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['codigo'] ?? '')); $nome = trim($_POST['nome'] ?? '') ?: null;
        $n = max(1, min(20, (int)($_POST['prateleiras'] ?? 4))); $ordem = (int)($_POST['ordem'] ?? 0);
        $cor = preg_match('/^#[0-9a-f]{6}$/i', $_POST['cor'] ?? '') ? $_POST['cor'] : '#6f42c1';
        if (!$cod) $err = 'Informe o código da estante (ex.: A, B, C1).';
        else try {
            if ($id = (int)($_POST['id'] ?? 0)) {
                $pdo->prepare("UPDATE estoque_estantes SET codigo=?,nome=?,prateleiras=?,ordem=?,cor=? WHERE id=?")->execute([$cod, $nome, $n, $ordem, $cor, $id]);
                $pdo->prepare("UPDATE estoque SET prateleira=NULL WHERE estante_id=? AND prateleira>?")->execute([$id, $n]);
                $msg = "Estante $cod atualizada.";
            } else {
                $pdo->prepare("INSERT INTO estoque_estantes (codigo,nome,prateleiras,ordem,cor) VALUES (?,?,?,?,?)")->execute([$cod, $nome, $n, $ordem, $cor]);
                $msg = "Estante $cod criada com $n prateleiras.";
            }
        } catch (PDOException $e) { $err = str_contains($e->getMessage(), 'Duplicate') ? "Já existe uma estante com o código $cod." : $e->getMessage(); }
    } elseif ($acao === 'estante_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        $q = $pdo->prepare("SELECT COUNT(*) FROM estoque WHERE estante_id=? AND ativo=1"); $q->execute([$id]);
        $ativa = $pdo->prepare("SELECT ativo FROM estoque_estantes WHERE id=?"); $ativa->execute([$id]);
        if ($ativa->fetchColumn() && $q->fetchColumn()) $err = 'Esta estante ainda tem peças. Mude as peças de lugar antes de desativar.';
        else { $pdo->prepare("UPDATE estoque_estantes SET ativo=1-ativo WHERE id=?")->execute([$id]); $msg = 'Estante atualizada.'; }
    } elseif ($acao === 'mover') {
        $estId = (int)($_POST['estante_id'] ?? 0) ?: null; $prat = $estId ? ((int)($_POST['prateleira'] ?? 0) ?: null) : null;
        $pdo->prepare("UPDATE estoque SET estante_id=?, prateleira=? WHERE id=?")->execute([$estId, $prat, (int)($_POST['item_id'] ?? 0)]);
        $msg = 'Peça mudada de lugar.';
    }
    $_SESSION['flash_mapa'] = [$msg, $err];
    header('Location: mapa.php?' . ($_POST['volta'] ?? '')); exit;
}
[$sucesso, $erro] = $_SESSION['flash_mapa'] ?? ['', '']; unset($_SESSION['flash_mapa']);

$estantes = estEstantes($pdo); $estantesPorId = array_column($estantes, null, 'id');
$q = trim($_GET['q'] ?? ''); $fEst = (int)($_GET['estante'] ?? 0); $fPrat = (int)($_GET['prat'] ?? 0); $semLugar = !empty($_GET['sem']);
$volta = http_build_query(array_filter(['q' => $q, 'estante' => $fEst ?: null, 'prat' => $fPrat ?: null, 'sem' => $semLugar ? 1 : null]));

// Ocupação por prateleira: quantidade de peças + até 4 fotos
$ocup = [];
foreach ($pdo->query("SELECT id, estante_id, prateleira, foto, nome, quantidade FROM estoque WHERE ativo=1 AND estante_id IS NOT NULL ORDER BY nome")->fetchAll() as $it) {
    $k = $it['estante_id'] . '-' . (int)$it['prateleira'];
    $ocup[$k]['n'] = ($ocup[$k]['n'] ?? 0) + 1;
    if ($it['foto'] && count($ocup[$k]['fotos'] ?? []) < 4) $ocup[$k]['fotos'][] = $it['foto'];
}
// Busca: destaca as prateleiras onde a peça está
$achados = []; $resultado = [];
if ($q !== '' || $fEst || $semLugar) {
    $where = 'e.ativo=1'; $par = [];
    if ($q !== '') { $where .= ' AND (e.nome LIKE ? OR e.codigo LIKE ? OR e.part_number LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
    if ($fEst) { $where .= ' AND e.estante_id=?'; $par[] = $fEst; if ($fPrat) { $where .= ' AND e.prateleira=?'; $par[] = $fPrat; } }
    if ($semLugar) $where .= ' AND e.estante_id IS NULL';
    $s = $pdo->prepare("SELECT e.* FROM estoque e WHERE $where ORDER BY e.estante_id IS NULL, e.estante_id, e.prateleira, e.nome LIMIT 300"); $s->execute($par); $resultado = $s->fetchAll();
    foreach ($resultado as $r) if ($r['estante_id']) $achados[$r['estante_id'] . '-' . (int)$r['prateleira']] = true;
}
$nSemLugar = (int)$pdo->query("SELECT COUNT(*) FROM estoque WHERE ativo=1 AND estante_id IS NULL")->fetchColumn();
$todasEstantes = estEstantes($pdo, false);

require_once '../includes/header.php';
?>
<style>
  .mapa{display:flex;gap:16px;overflow-x:auto;padding-bottom:8px;align-items:flex-start}
  .estante{min-width:190px;max-width:230px;flex:0 0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);overflow:hidden}
  .estante .topo{padding:8px 12px;color:#fff;font-weight:700;display:flex;justify-content:space-between;align-items:center}
  .estante .topo .cod{font-size:1.4rem;line-height:1}
  .prat{display:block;border-top:3px solid #d9d2c5;background:linear-gradient(#faf8f4,#f1ede5);padding:6px 10px;min-height:58px;text-decoration:none;color:inherit;position:relative;transition:background .15s}
  .prat:hover{background:#efe9dd}
  .prat .num{position:absolute;left:6px;top:4px;font-size:.65rem;color:#999;font-weight:700}
  .prat .qtd{position:absolute;right:8px;top:4px;font-size:.7rem}
  .prat .fotos{display:flex;gap:3px;margin-top:14px}
  .prat .fotos img{width:30px;height:30px;object-fit:cover;border-radius:4px;border:1px solid #ddd}
  .prat.vazia{opacity:.55}
  .prat.achou{background:#fff3cd;box-shadow:inset 0 0 0 3px #ffc107}
  .prat.atual{box-shadow:inset 0 0 0 3px #0d6efd}
  .estante .base{height:10px;background:#8d7b68}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div><a href="index.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Lista do estoque</a>
    <h5 class="fw-bold mb-0 mt-1"><i class="bi bi-grid-3x3-gap me-2" style="color:#6f42c1"></i>Mapa do estoque</h5></div>
  <form method="get" class="d-flex gap-2"><input type="text" name="q" class="form-control form-control-sm" placeholder="Onde está... (nome, código, PN)" value="<?= htmlspecialchars($q) ?>" style="min-width:240px">
    <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button><?php if ($volta): ?><a href="mapa.php" class="btn btn-sm btn-light border">Limpar</a><?php endif; ?></form>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if (!$estantes): ?>
<div class="card border-0 shadow-sm text-center py-5 mb-4"><i class="bi bi-bookshelf fs-1 text-muted d-block mb-2"></i>Nenhuma estante cadastrada ainda. Cadastre abaixo (ex.: estante <strong>A</strong> com 5 prateleiras) e depois indique o lugar de cada peça.</div>
<?php else: ?>
<div class="mapa mb-2">
  <?php foreach ($estantes as $e): ?>
  <div class="estante">
    <div class="topo" style="background:<?= htmlspecialchars($e['cor']) ?>"><span class="cod"><?= htmlspecialchars($e['codigo']) ?></span><span class="small text-truncate ms-2"><?= htmlspecialchars($e['nome'] ?? '') ?></span></div>
    <?php for ($p = 1; $p <= (int)$e['prateleiras']; $p++): $k = $e['id'] . '-' . $p; $o = $ocup[$k] ?? ['n' => 0]; ?>
    <a href="?estante=<?= $e['id'] ?>&prat=<?= $p ?>" class="prat <?= !$o['n'] ? 'vazia' : '' ?> <?= isset($achados[$k]) && $q !== '' ? 'achou' : '' ?> <?= $fEst == $e['id'] && $fPrat == $p ? 'atual' : '' ?>">
      <span class="num"><?= htmlspecialchars($e['codigo']) . $p ?></span>
      <span class="qtd badge <?= $o['n'] ? 'bg-dark' : 'bg-light text-muted' ?>"><?= $o['n'] ?></span>
      <div class="fotos"><?php foreach ($o['fotos'] ?? [] as $f): ?><img src="<?= BASE_URL ?>/uploads/estoque/<?= is_file(UPLOAD_DIR . 'estoque/thumbs/' . $f) ? 'thumbs/' : '' ?><?= htmlspecialchars($f) ?>" loading="lazy"><?php endforeach; ?></div>
    </a>
    <?php endfor; ?>
    <div class="base"></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="small text-muted mb-4"><i class="bi bi-info-circle me-1"></i>Prateleira 1 = topo. Clique numa prateleira para ver as peças. <?php if ($nSemLugar): ?><a href="?sem=1"><?= $nSemLugar ?> peça(s) ainda sem lugar →</a><?php endif; ?></div>
<?php endif; ?>

<?php if ($volta): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold border-bottom">
    <?php if ($semLugar): ?>Peças sem lugar definido
    <?php elseif ($fEst && isset($estantesPorId[$fEst])): ?>Estante <?= htmlspecialchars($estantesPorId[$fEst]['codigo']) ?><?= $fPrat ? ' · prateleira ' . $fPrat : '' ?>
    <?php else: ?>Resultado da busca "<?= htmlspecialchars($q) ?>"<?php endif; ?>
    <span class="badge bg-secondary ms-1"><?= count($resultado) ?></span>
  </div>
  <?php if ($resultado): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th style="width:50px"></th><th>Peça</th><th>Lugar</th><th class="text-center">Qtd</th><th>Mudar de lugar</th></tr></thead>
    <tbody>
    <?php foreach ($resultado as $it): ?>
    <tr>
      <td><?php if ($it['foto']): ?><img src="<?= BASE_URL ?>/uploads/estoque/<?= is_file(UPLOAD_DIR . 'estoque/thumbs/' . $it['foto']) ? 'thumbs/' : '' ?><?= htmlspecialchars($it['foto']) ?>" style="width:42px;height:42px;object-fit:cover;border-radius:6px"><?php endif; ?></td>
      <td><a href="index.php?editar=<?= $it['id'] ?>" class="fw-semibold text-decoration-none text-reset"><?= htmlspecialchars($it['nome']) ?></a>
        <div class="text-muted"><?= htmlspecialchars($it['codigo'] ?? '') ?><?= $it['part_number'] ? ' · PN ' . htmlspecialchars($it['part_number']) : '' ?></div>
        <?php if ($it['part_number']): ?><div class="mt-1"><?= estBotoesBrp($it['part_number']) ?></div><?php endif; ?></td>
      <td class="fs-6"><?= estLocalBadge($it, $estantesPorId) ?><?= $it['posicao'] ? '<div class="small text-muted">' . htmlspecialchars($it['posicao']) . '</div>' : '' ?></td>
      <td class="text-center"><span class="badge <?= $it['quantidade'] <= 0 ? 'bg-danger' : 'bg-success' ?>"><?= (int)$it['quantidade'] ?></span></td>
      <td><form method="post" class="d-flex gap-1 m-0"><input type="hidden" name="acao" value="mover"><input type="hidden" name="item_id" value="<?= $it['id'] ?>"><input type="hidden" name="volta" value="<?= htmlspecialchars($volta) ?>">
        <select name="estante_id" class="form-select form-select-sm js-estante" style="max-width:110px" onchange="estPrateleiras(this)"><option value="" data-n="0">—</option>
          <?php foreach ($estantes as $e): ?><option value="<?= $e['id'] ?>" data-n="<?= (int)$e['prateleiras'] ?>" <?= $it['estante_id'] == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['codigo']) ?></option><?php endforeach; ?></select>
        <select name="prateleira" class="form-select form-select-sm js-prateleira" style="max-width:110px" data-atual="<?= (int)$it['prateleira'] ?>"><option value="">—</option></select>
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2"></i></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0">Nada aqui.</p><?php endif; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-bookshelf me-2"></i>Estantes</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end mb-3"><input type="hidden" name="acao" value="estante">
      <div class="col-3 col-md-1"><label class="small mb-0">Código *</label><input type="text" name="codigo" class="form-control form-control-sm" maxlength="10" placeholder="A" required style="text-transform:uppercase"></div>
      <div class="col-9 col-md-4"><label class="small mb-0">Nome / o que guarda</label><input type="text" name="nome" class="form-control form-control-sm" maxlength="80" placeholder="Ex.: Suspensão e freio"></div>
      <div class="col-4 col-md-2"><label class="small mb-0">Prateleiras</label><input type="number" name="prateleiras" class="form-control form-control-sm" min="1" max="20" value="5"></div>
      <div class="col-4 col-md-2"><label class="small mb-0">Ordem</label><input type="number" name="ordem" class="form-control form-control-sm" value="<?= count($todasEstantes) + 1 ?>"></div>
      <div class="col-2 col-md-1"><label class="small mb-0">Cor</label><input type="color" name="cor" class="form-control form-control-sm form-control-color" value="#6f42c1"></div>
      <div class="col-2 col-md-2"><button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Criar</button></div>
    </form>
    <?php foreach ($todasEstantes as $e): ?>
    <form method="post" class="row g-2 align-items-center mb-2 <?= $e['ativo'] ? '' : 'opacity-50' ?>"><input type="hidden" name="acao" value="estante"><input type="hidden" name="id" value="<?= $e['id'] ?>">
      <div class="col-3 col-md-1"><input type="text" name="codigo" class="form-control form-control-sm fw-bold" value="<?= htmlspecialchars($e['codigo']) ?>" maxlength="10"></div>
      <div class="col-9 col-md-4"><input type="text" name="nome" class="form-control form-control-sm" value="<?= htmlspecialchars($e['nome'] ?? '') ?>" maxlength="80"></div>
      <div class="col-4 col-md-2"><input type="number" name="prateleiras" class="form-control form-control-sm" min="1" max="20" value="<?= (int)$e['prateleiras'] ?>" title="Prateleiras"></div>
      <div class="col-4 col-md-2"><input type="number" name="ordem" class="form-control form-control-sm" value="<?= (int)$e['ordem'] ?>" title="Ordem no mapa"></div>
      <div class="col-2 col-md-1"><input type="color" name="cor" class="form-control form-control-sm form-control-color" value="<?= htmlspecialchars($e['cor']) ?>"></div>
      <div class="col-2 col-md-2 text-nowrap"><button class="btn btn-sm btn-outline-primary py-0" title="Salvar"><i class="bi bi-save"></i></button>
        <button class="btn btn-sm <?= $e['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?> py-0" name="acao" value="estante_ativo"><?= $e['ativo'] ? 'Desativar' : 'Ativar' ?></button></div>
    </form>
    <?php endforeach; ?>
    <div class="small text-muted">Ao diminuir o número de prateleiras, as peças das prateleiras que deixaram de existir ficam só com a estante (sem prateleira).</div>
  </div>
</div>
<?php estScriptLocal(); ?>
<?php require_once '../includes/footer.php'; ?>
