<?php
// admin/oficina.php – Fase 3: OFICINA (só admin) — gastos do dia, gastos fixos mensais, categorias e relatório oficina × corridas
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/financeiro.php';
requireRole('admin');
$u = usuarioLogado(); $area = 'admin'; $titulo = 'Oficina';

$abas = ['lancamentos' => ['Gastos do mês', 'bi-receipt-cutoff'], 'fixos' => ['Gastos fixos', 'bi-calendar-check'], 'relatorio' => ['Relatório', 'bi-bar-chart'], 'categorias' => ['Categorias', 'bi-tags']];
$aba = $_GET['aba'] ?? 'lancamentos'; if (!isset($abas[$aba])) $aba = 'lancamentos';
$comp = finComp($_GET['comp'] ?? null);
$volta = function (array $x = []) use (&$aba, &$comp) { return 'oficina.php?' . http_build_query(array_merge(['aba' => $aba, 'comp' => $comp], $x)); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $msg = ''; $err = '';
    switch ($acao) {
    case 'lancar':
        $desc = trim($_POST['descricao'] ?? ''); $valor = aprovNum($_POST['valor'] ?? '');
        if (!$desc || !$valor) { $err = 'Informe descrição e valor.'; break; }
        [$arq, $e] = finSalvarArquivo($_FILES['comprovante'] ?? [], 'despesas', 'desp');
        if ($e) { $err = $e; break; }
        $data = finData($_POST['data'] ?? '') ?? date('Y-m-d');
        finLancarDespesa($pdo, ['categoria_id' => (int)($_POST['categoria_id'] ?? 0) ?: null, 'descricao' => $desc, 'valor' => $valor, 'data' => $data,
            'forma_pagamento' => $_POST['forma_pagamento'] ?? 'pix', 'fornecedor' => trim($_POST['fornecedor'] ?? '') ?: null, 'comprovante' => $arq, 'lancado_por' => $u['id']]);
        $msg = 'Gasto lançado: ' . finR($valor); $comp = substr($data, 0, 7); break;
    case 'del_despesa':
        $d = $pdo->prepare("SELECT * FROM despesas WHERE id=?"); $d->execute([(int)($_POST['id'] ?? 0)]); $d = $d->fetch();
        if (!$d) break;
        if (in_array($d['origem'], ['folha', 'reembolso'], true)) { $err = $d['origem'] === 'folha' ? 'Este gasto veio da folha de pagamento.' : 'Este gasto veio de um reembolso aprovado.'; break; }
        fotoApagar($d['comprovante']); $pdo->prepare("DELETE FROM despesas WHERE id=?")->execute([$d['id']]);
        $msg = $d['origem'] === 'fixa' ? 'Pagamento do gasto fixo desfeito (voltou para "a pagar").' : 'Gasto removido.'; break;

    case 'fixa_salvar':
        $desc = trim($_POST['descricao'] ?? ''); $valor = aprovNum($_POST['valor'] ?? ''); $dia = max(1, min(31, (int)($_POST['dia_vencimento'] ?? 10)));
        if (!$desc || !$valor) { $err = 'Informe descrição e valor do gasto fixo.'; break; }
        $cat = (int)($_POST['categoria_id'] ?? 0) ?: null;
        if ($fid = (int)($_POST['id'] ?? 0)) $pdo->prepare("UPDATE despesas_fixas SET descricao=?,categoria_id=?,valor=?,dia_vencimento=? WHERE id=?")->execute([$desc, $cat, $valor, $dia, $fid]);
        else $pdo->prepare("INSERT INTO despesas_fixas (descricao,categoria_id,valor,dia_vencimento) VALUES (?,?,?,?)")->execute([$desc, $cat, $valor, $dia]);
        $msg = 'Gasto fixo salvo!'; break;
    case 'fixa_ativo':
        $pdo->prepare("UPDATE despesas_fixas SET ativo=1-ativo WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Gasto fixo atualizado.'; break;
    case 'fixa_pagar':
        $f = $pdo->prepare("SELECT * FROM despesas_fixas WHERE id=?"); $f->execute([(int)($_POST['id'] ?? 0)]); $f = $f->fetch();
        $c = finComp($_POST['comp'] ?? null);
        if (!$f) break;
        $ja = $pdo->prepare("SELECT COUNT(*) FROM despesas WHERE fixa_id=? AND competencia=? AND origem='fixa'"); $ja->execute([$f['id'], $c]);
        if ($ja->fetchColumn()) { $err = 'Este gasto fixo já está pago em ' . finCompLabel($c) . '.'; break; }
        [$arq, $e] = finSalvarArquivo($_FILES['comprovante'] ?? [], 'despesas', 'fixa' . $f['id']);
        if ($e) { $err = $e; break; }
        $valor = aprovNum($_POST['valor'] ?? '') ?? (float)$f['valor'];
        finLancarDespesa($pdo, ['categoria_id' => $f['categoria_id'], 'descricao' => $f['descricao'] . ' – ' . finCompLabel($c), 'valor' => $valor,
            'data' => finData($_POST['data'] ?? '') ?? date('Y-m-d'), 'forma_pagamento' => $_POST['forma_pagamento'] ?? 'pix', 'comprovante' => $arq,
            'origem' => 'fixa', 'fixa_id' => $f['id'], 'competencia' => $c, 'lancado_por' => $u['id']]);
        $msg = $f['descricao'] . ' pago: ' . finR($valor); $comp = $c; break;

    case 'cat_salvar':
        $nome = trim($_POST['nome'] ?? ''); $cor = preg_match('/^#[0-9a-f]{6}$/i', $_POST['cor'] ?? '') ? $_POST['cor'] : '#6c757d';
        if (!$nome) { $err = 'Informe o nome da categoria.'; break; }
        if ($cid = (int)($_POST['id'] ?? 0)) $pdo->prepare("UPDATE despesa_categorias SET nome=?,cor=? WHERE id=?")->execute([$nome, $cor, $cid]);
        else $pdo->prepare("INSERT INTO despesa_categorias (nome,cor) VALUES (?,?)")->execute([$nome, $cor]);
        $msg = 'Categoria salva!'; break;
    case 'cat_ativo':
        $pdo->prepare("UPDATE despesa_categorias SET ativo=1-ativo WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Categoria atualizada.'; break;
    }
    $_SESSION['flash_ofi'] = [$msg, $err];
    header('Location: ' . $volta()); exit;
}
[$sucesso, $erro] = $_SESSION['flash_ofi'] ?? ['', '']; unset($_SESSION['flash_ofi']);

$categorias = finCategorias($pdo);
$ini = $comp . '-01'; $fim = date('Y-m-t', strtotime($ini));
$fixas = finFixasDoMes($pdo, $comp);
$fixasAPagar = array_filter($fixas, fn($f) => !$f['despesa_id']);
$totMes = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM despesas WHERE data BETWEEN '$ini' AND '$fim'")->fetchColumn();
$origemLabel = ['manual' => '', 'fixa' => '<span class="badge bg-light text-dark border">Fixo</span>', 'folha' => '<span class="badge bg-light text-dark border">Folha</span>', 'reembolso' => '<span class="badge bg-light text-dark border">Reembolso</span>'];

if ($aba === 'lancamentos') {
    $fCat = (int)($_GET['cat'] ?? 0);
    $s = $pdo->prepare("SELECT d.*, c.nome AS cat_nome, c.cor AS cat_cor, u.nome AS lancado_nome FROM despesas d LEFT JOIN despesa_categorias c ON c.id=d.categoria_id LEFT JOIN usuarios u ON u.id=d.lancado_por
        WHERE d.data BETWEEN ? AND ?" . ($fCat ? " AND d.categoria_id=" . $fCat : "") . " ORDER BY d.data DESC, d.id DESC");
    $s->execute([$ini, $fim]); $despesas = $s->fetchAll();
}
if ($aba === 'relatorio') {
    $s = $pdo->prepare("SELECT c.id, COALESCE(c.nome,'Sem categoria') nome, COALESCE(c.cor,'#adb5bd') cor, SUM(d.valor) total, COUNT(*) n FROM despesas d LEFT JOIN despesa_categorias c ON c.id=d.categoria_id
        WHERE d.data BETWEEN ? AND ? GROUP BY c.id, c.nome, c.cor ORDER BY total DESC");
    $s->execute([$ini, $fim]); $porCat = $s->fetchAll();
    $comparativo = finComparativo($pdo, $comp, 12);
    $maxComp = max(1, max(array_map(fn($m) => max($m['oficina'], $m['corridas']), $comparativo)));
    $mesAnt = $comparativo[finCompMais($comp, -1)]['oficina'] ?? 0;
}
if ($aba === 'fixos' || $aba === 'categorias') {
    $todasFixas = $pdo->query("SELECT f.*, c.nome AS cat_nome FROM despesas_fixas f LEFT JOIN despesa_categorias c ON c.id=f.categoria_id ORDER BY f.ativo DESC, f.dia_vencimento, f.descricao")->fetchAll();
    $todasCats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM despesas d WHERE d.categoria_id=c.id) AS usos FROM despesa_categorias c ORDER BY c.ativo DESC, c.nome")->fetchAll();
}
$catSelect = function (?int $sel = null, string $name = 'categoria_id') use ($categorias) {
    $h = '<select name="' . $name . '" class="form-select form-select-sm"><option value="">— Categoria —</option>';
    foreach ($categorias as $c) $h .= '<option value="' . $c['id'] . '"' . ($sel == $c['id'] ? ' selected' : '') . '>' . htmlspecialchars($c['nome']) . '</option>';
    return $h . '</select>';
};
$formaSelect = function (string $sel = 'pix') {
    $h = '<select name="forma_pagamento" class="form-select form-select-sm">';
    foreach (FORMAS_PAGAMENTO as $k => $l) $h .= '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $l . '</option>';
    return $h . '</select>';
};

require_once '../includes/header.php';
?>
<style>
  .barra{height:10px;border-radius:5px;background:#e9ecef;overflow:hidden}
  .barra > div{height:100%}
  .comp-nav .btn{min-width:36px}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-tools me-2 text-danger"></i>Oficina</h5>
  <?php if ($aba !== 'categorias'): ?>
  <div class="d-flex align-items-center gap-1 comp-nav">
    <a href="<?= htmlspecialchars($volta(['comp' => finCompMais($comp, -1)])) ?>" class="btn btn-sm btn-light border"><i class="bi bi-chevron-left"></i></a>
    <span class="fw-semibold px-2"><?= finCompLabel($comp) ?></span>
    <a href="<?= htmlspecialchars($volta(['comp' => finCompMais($comp, 1)])) ?>" class="btn btn-sm btn-light border"><i class="bi bi-chevron-right"></i></a>
    <?php if ($comp !== date('Y-m')): ?><a href="<?= htmlspecialchars($volta(['comp' => date('Y-m')])) ?>" class="btn btn-sm btn-link">mês atual</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Gastos da oficina em <?= finCompLabel($comp) ?></div><div class="fs-4 fw-bold text-danger"><?= finR($totMes) ?></div></div></div></div>
  <div class="col-md-4"><a href="<?= htmlspecialchars($volta(['aba' => 'fixos'])) ?>" class="text-decoration-none"><div class="card border-0 shadow-sm card-link"><div class="card-body py-3"><div class="small text-muted">Fixos a pagar no mês</div>
    <div class="fs-4 fw-bold <?= $fixasAPagar ? 'text-warning' : 'text-success' ?>"><?= count($fixasAPagar) ?> <span class="fs-6 fw-normal text-muted"><?= finR(array_sum(array_column($fixasAPagar, 'valor'))) ?></span></div></div></div></a></div>
  <div class="col-md-4"><a href="folha.php?comp=<?= $comp ?>" class="text-decoration-none"><div class="card border-0 shadow-sm card-link"><div class="card-body py-3"><div class="small text-muted">Folha de pagamento</div><div class="fs-5 fw-bold text-dark"><i class="bi bi-people me-1"></i>Abrir folha de <?= finCompLabel($comp) ?> →</div></div></div></a></div>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($abas as $k => [$l, $ic]): ?><li class="nav-item"><a class="nav-link text-nowrap <?= $aba === $k ? 'active fw-semibold' : '' ?>" href="?aba=<?= $k ?>&comp=<?= $comp ?>"><i class="bi <?= $ic ?> me-1"></i><?= $l ?></a></li><?php endforeach; ?>
</ul>

<?php if ($aba === 'lancamentos'): ?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-plus-circle me-2 text-success"></i>Lançar gasto</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="lancar">
          <div class="mb-2"><input type="text" name="descricao" class="form-control" placeholder="O que foi pago *" required maxlength="255"></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">R$</span><input type="text" name="valor" class="form-control" placeholder="0,00" inputmode="decimal" required></div></div>
            <div class="col-6"><input type="date" name="data" class="form-control form-control-sm" value="<?= $comp === date('Y-m') ? date('Y-m-d') : $comp . '-01' ?>"></div>
            <div class="col-6"><?= $catSelect() ?></div>
            <div class="col-6"><?= $formaSelect() ?></div>
            <div class="col-12"><input type="text" name="fornecedor" class="form-control form-control-sm" placeholder="Fornecedor / onde (opcional)"></div>
          </div>
          <label class="btn btn-outline-secondary btn-sm w-100 mb-2"><i class="bi bi-camera me-1"></i>Comprovante (opcional)<input type="file" name="comprovante" accept="image/*,.pdf" hidden data-reduzir data-preview="prevDesp"></label>
          <div id="prevDesp" class="d-flex gap-1 mb-2"></div>
          <button class="btn btn-success w-100"><i class="bi bi-save me-1"></i>Lançar</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Gastos de <?= finCompLabel($comp) ?></span>
        <form method="get" class="m-0"><input type="hidden" name="aba" value="lancamentos"><input type="hidden" name="comp" value="<?= $comp ?>">
          <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()"><option value="0">Todas as categorias</option>
            <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= $fCat == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></form>
      </div>
      <?php if ($despesas): ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
        <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Forma</th><th class="text-end">Valor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($despesas as $d): ?>
        <tr>
          <td class="text-nowrap"><?= date('d/m', strtotime($d['data'])) ?></td>
          <td><?= htmlspecialchars($d['descricao']) ?> <?= $origemLabel[$d['origem']] ?? '' ?><?php if ($d['fornecedor']): ?><div class="text-muted"><?= htmlspecialchars($d['fornecedor']) ?></div><?php endif; ?></td>
          <td><?php if ($d['cat_nome']): ?><span class="badge" style="background:<?= htmlspecialchars($d['cat_cor']) ?>"><?= htmlspecialchars($d['cat_nome']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?= FORMAS_PAGAMENTO[$d['forma_pagamento']] ?? '' ?></td>
          <td class="text-end fw-semibold"><?= finR($d['valor']) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($d['comprovante']): ?><a href="<?= fotoUrl($d['comprovante']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i></a><?php endif; ?>
            <?php if (in_array($d['origem'], ['manual', 'fixa'], true)): ?><form method="post" class="d-inline"><input type="hidden" name="acao" value="del_despesa"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="<?= $d['origem'] === 'fixa' ? 'Desfazer o pagamento deste gasto fixo?' : 'Remover este gasto?' ?>"><i class="bi bi-trash3"></i></button></form><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light"><tr><td colspan="4" class="fw-bold text-end">Total<?= $fCat ? ' da categoria' : '' ?>:</td><td class="text-end fw-bold text-danger"><?= finR(array_sum(array_column($despesas, 'valor'))) ?></td><td></td></tr></tfoot>
      </table></div>
      <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum gasto lançado em <?= finCompLabel($comp) ?>.</p><?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($aba === 'fixos'): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-calendar-check me-2 text-primary"></i>Gastos fixos de <?= finCompLabel($comp) ?></div>
  <?php if ($fixas): ?>
  <div class="list-group list-group-flush">
    <?php foreach ($fixas as $f): ?>
    <div class="list-group-item">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><strong><?= htmlspecialchars($f['descricao']) ?></strong> <?php if ($f['cat_nome']): ?><span class="badge" style="background:<?= htmlspecialchars($f['cat_cor']) ?>"><?= htmlspecialchars($f['cat_nome']) ?></span><?php endif; ?>
          <div class="small text-muted">Vence <?= date('d/m', strtotime($f['vencimento'])) ?> · previsto <?= finR($f['valor']) ?></div></div>
        <div class="text-end">
          <?php if ($f['despesa_id']): ?>
            <span class="badge bg-success">Pago <?= date('d/m', strtotime($f['data_pago'])) ?> · <?= finR($f['valor_pago']) ?></span>
            <?php if ($f['comp_pago']): ?><a href="<?= fotoUrl($f['comp_pago']) ?>" target="_blank" class="small ms-1">comprovante</a><?php endif; ?>
          <?php else: ?>
            <span class="badge <?= $f['atrasada'] ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $f['atrasada'] ? 'Atrasado' : 'A pagar' ?></span>
            <button class="btn btn-sm btn-success ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#pg<?= $f['id'] ?>"><i class="bi bi-check2 me-1"></i>Pagar</button>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$f['despesa_id']): ?>
      <div class="collapse mt-2" id="pg<?= $f['id'] ?>">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end bg-light rounded p-2 m-0">
          <input type="hidden" name="acao" value="fixa_pagar"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="comp" value="<?= $comp ?>">
          <div class="col-md-2"><label class="small mb-0">Valor pago</label><input type="text" name="valor" class="form-control form-control-sm" value="<?= number_format($f['valor'], 2, ',', '.') ?>" inputmode="decimal"></div>
          <div class="col-md-3"><label class="small mb-0">Data</label><input type="date" name="data" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-2"><label class="small mb-0">Forma</label><?= $formaSelect('boleto') ?></div>
          <div class="col-md-3"><label class="small mb-0">Comprovante</label><input type="file" name="comprovante" accept="image/*,.pdf" class="form-control form-control-sm" data-reduzir></div>
          <div class="col-md-2"><button class="btn btn-success btn-sm w-100">Confirmar</button></div>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer bg-white small text-muted">Pago = <?= finR(array_sum(array_map(fn($f) => (float)$f['valor_pago'], $fixas))) ?> · A pagar = <?= finR(array_sum(array_column($fixasAPagar, 'valor'))) ?>. Ao pagar, o gasto entra em "Gastos do mês".</div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum gasto fixo cadastrado. Cadastre abaixo (aluguel, energia, internet...).</p><?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-gear me-2"></i>Cadastro de gastos fixos</div>
  <div class="card-body">
    <form method="post" class="row g-2 mb-3">
      <input type="hidden" name="acao" value="fixa_salvar">
      <div class="col-md-4"><input type="text" name="descricao" class="form-control form-control-sm" placeholder="Ex.: Aluguel do galpão" required></div>
      <div class="col-md-3"><?= $catSelect() ?></div>
      <div class="col-md-2"><input type="text" name="valor" class="form-control form-control-sm" placeholder="Valor" inputmode="decimal" required></div>
      <div class="col-md-2"><div class="input-group input-group-sm"><span class="input-group-text">dia</span><input type="number" name="dia_vencimento" class="form-control" min="1" max="31" value="10"></div></div>
      <div class="col-md-1"><button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg"></i></button></div>
    </form>
    <?php if ($todasFixas): ?>
    <table class="table table-sm align-middle mb-0 small">
      <thead><tr><th>Descrição</th><th>Categoria</th><th>Valor</th><th>Vence dia</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($todasFixas as $f): ?>
      <tr class="<?= $f['ativo'] ? '' : 'text-muted' ?>">
        <td colspan="5" class="p-0">
          <form method="post" class="row g-1 align-items-center m-0 py-1">
            <input type="hidden" name="acao" value="fixa_salvar"><input type="hidden" name="id" value="<?= $f['id'] ?>">
            <div class="col-md-4"><input type="text" name="descricao" class="form-control form-control-sm" value="<?= htmlspecialchars($f['descricao']) ?>"></div>
            <div class="col-md-3"><?= $catSelect($f['categoria_id'] ? (int)$f['categoria_id'] : null) ?></div>
            <div class="col-md-2"><input type="text" name="valor" class="form-control form-control-sm" value="<?= number_format($f['valor'], 2, ',', '.') ?>"></div>
            <div class="col-md-1"><input type="number" name="dia_vencimento" class="form-control form-control-sm" min="1" max="31" value="<?= (int)$f['dia_vencimento'] ?>"></div>
            <div class="col-md-2 text-nowrap"><button class="btn btn-sm btn-outline-primary py-0" title="Salvar"><i class="bi bi-save"></i></button>
              <button class="btn btn-sm <?= $f['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?> py-0" formaction="?aba=fixos&comp=<?= $comp ?>" name="acao" value="fixa_ativo"><?= $f['ativo'] ? 'Desativar' : 'Ativar' ?></button></div>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($aba === 'relatorio'): $totCat = array_sum(array_column($porCat, 'total')); $maxCat = $porCat ? max(array_column($porCat, 'total')) : 1; ?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between"><span><i class="bi bi-pie-chart me-2 text-danger"></i>Por categoria — <?= finCompLabel($comp) ?></span><span class="fw-bold"><?= finR($totCat) ?></span></div>
      <div class="card-body">
        <?php if ($porCat): foreach ($porCat as $c): ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?= htmlspecialchars($c['nome']) ?> <span class="text-muted">(<?= $c['n'] ?>)</span></span><span class="fw-semibold"><?= finR($c['total']) ?> <span class="text-muted fw-normal"><?= $totCat ? round($c['total'] / $totCat * 100) : 0 ?>%</span></span></div>
          <div class="barra"><div style="width:<?= round($c['total'] / $maxCat * 100) ?>%;background:<?= htmlspecialchars($c['cor']) ?>"></div></div>
        </div>
        <?php endforeach; else: ?><p class="text-muted text-center py-4 mb-0">Sem gastos neste mês.</p><?php endif; ?>
        <?php if ($mesAnt > 0): $dif = $totCat - $mesAnt; ?>
        <div class="small mt-3 <?= $dif > 0 ? 'text-danger' : 'text-success' ?>"><i class="bi bi-arrow-<?= $dif > 0 ? 'up' : 'down' ?>-right me-1"></i><?= finR(abs($dif)) ?> (<?= round(abs($dif) / $mesAnt * 100) ?>%) <?= $dif > 0 ? 'a mais' : 'a menos' ?> que <?= finCompLabel(finCompMais($comp, -1)) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between flex-wrap gap-2"><span><i class="bi bi-bar-chart me-2 text-primary"></i>Oficina × Corridas — últimos 12 meses</span>
        <span class="small"><span class="badge" style="background:#dc3545">Oficina</span> <span class="badge" style="background:#0d6efd">Corridas</span></span></div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0 small">
          <thead><tr><th>Mês</th><th style="width:45%"></th><th class="text-end">Oficina</th><th class="text-end">Corridas</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($comparativo, true) as $m => $v): ?>
          <tr class="<?= $m === $comp ? 'table-warning' : '' ?>">
            <td class="text-nowrap"><a href="?aba=relatorio&comp=<?= $m ?>" class="text-decoration-none"><?= finCompLabel($m) ?></a></td>
            <td><div class="barra mb-1" style="height:6px"><div style="width:<?= round($v['oficina'] / $maxComp * 100) ?>%;background:#dc3545"></div></div>
                <div class="barra" style="height:6px"><div style="width:<?= round($v['corridas'] / $maxComp * 100) ?>%;background:#0d6efd"></div></div></td>
            <td class="text-end"><?= finR($v['oficina']) ?></td>
            <td class="text-end"><?= finR($v['corridas']) ?></td>
            <td class="text-end fw-semibold"><?= finR($v['oficina'] + $v['corridas']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold"><tr><td colspan="2">12 meses</td><td class="text-end"><?= finR(array_sum(array_column($comparativo, 'oficina'))) ?></td><td class="text-end"><?= finR(array_sum(array_column($comparativo, 'corridas'))) ?></td><td class="text-end"><?= finR(array_sum(array_column($comparativo, 'oficina')) + array_sum(array_column($comparativo, 'corridas'))) ?></td></tr></tfoot>
        </table>
      </div>
      <div class="card-footer bg-white small text-muted">Oficina = gastos desta página (inclui folha e reembolsos da oficina). Corridas = gastos da equipe nas corridas (inclui reembolsos de corrida), diárias de corrida e OS de navegador cobradas da equipe. Não inclui o que é cobrado dos clientes.</div>
    </div>
  </div>
</div>

<?php else: /* categorias */ ?>
<div class="card border-0 shadow-sm" style="max-width:720px">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-tags me-2"></i>Categorias de gastos da oficina</div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2 mb-3"><input type="hidden" name="acao" value="cat_salvar">
      <input type="text" name="nome" class="form-control form-control-sm" placeholder="Nova categoria" required maxlength="60">
      <input type="color" name="cor" class="form-control form-control-sm form-control-color" value="#6c757d">
      <button class="btn btn-success btn-sm text-nowrap"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></form>
    <?php foreach ($todasCats as $c): ?>
    <form method="post" class="d-flex gap-2 align-items-center mb-2 <?= $c['ativo'] ? '' : 'opacity-50' ?>">
      <input type="hidden" name="acao" value="cat_salvar"><input type="hidden" name="id" value="<?= $c['id'] ?>">
      <input type="color" name="cor" class="form-control form-control-sm form-control-color" value="<?= htmlspecialchars($c['cor']) ?>">
      <input type="text" name="nome" class="form-control form-control-sm" value="<?= htmlspecialchars($c['nome']) ?>" maxlength="60">
      <span class="small text-muted text-nowrap"><?= (int)$c['usos'] ?> uso(s)</span>
      <button class="btn btn-sm btn-outline-primary py-0" title="Salvar"><i class="bi bi-save"></i></button>
      <button class="btn btn-sm <?= $c['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?> py-0 text-nowrap" name="acao" value="cat_ativo"><?= $c['ativo'] ? 'Desativar' : 'Ativar' ?></button>
    </form>
    <?php endforeach; ?>
    <div class="small text-muted mt-2">"Folha de pagamento" e "Reembolsos" são usadas automaticamente pelo sistema — não desative.</div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
