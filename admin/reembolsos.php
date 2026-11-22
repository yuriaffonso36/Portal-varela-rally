<?php
// admin/reembolsos.php – pagamento dos reembolsos por pessoa (PIX, total, marcar pago) + v13: gerenciar todos (aprovar, recusar, excluir mesmo após aprovado/pago)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/aprovacoes.php';
requireRole('admin');
$u = usuarioLogado(); $area = 'admin'; $titulo = 'Pagamento de reembolsos';

// Filtros: onde (todas / oficina / corrida X) e situação (a pagar / pagos / todos aprovados)
$fOnde = $_GET['onde'] ?? 'todas';
$fSit  = $_GET['sit'] ?? 'apagar'; if (!in_array($fSit, ['apagar', 'pagos', 'todos', 'pendentes', 'tudo'], true)) $fSit = 'apagar';
$qs = fn(array $extra = []) => http_build_query(array_merge(['onde' => $fOnde, 'sit' => $fSit], $extra));

// v13: aprovar, recusar e excluir direto daqui
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['aprovar', 'recusar', 'excluir'], true)) {
    $rid = (int)($_POST['id'] ?? 0); $ac = $_POST['acao'];
    if ($ac === 'aprovar')     { $err = reembolsoAprovar($pdo, $rid, $u['id']);                              $msg = 'Reembolso aprovado.'; }
    elseif ($ac === 'recusar') { $err = reembolsoRecusar($pdo, $rid, $u['id'], $_POST['motivo'] ?? '');      $msg = 'Reembolso recusado.'; }
    else                       { $err = reembolsoExcluir($pdo, $rid, $u['id'], $_POST['motivo'] ?? '');      $msg = 'Reembolso excluído (e retirado dos gastos da corrida/oficina).'; }
    $_SESSION['flash_rpg'] = $err ? ['', $err] : [$msg, ''];
    header('Location: reembolsos.php?' . ($_POST['qs'] ?? '')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'pagar') {
    $pessoa = (int)($_POST['usuario_id'] ?? 0); $ids = array_map('intval', $_POST['ids'] ?? []);
    $msg = ''; $err = '';
    if (!$pessoa || !$ids) $err = 'Nada para pagar.';
    else {
        [$arq, $e] = reembolsoSalvarArquivo($_FILES['comprovante_pagamento'] ?? [], 'pgto' . $pessoa);
        if ($e) $err = $e;
        else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT r.*, u.nome AS pessoa_nome FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id WHERE r.id IN ($ph) AND r.usuario_id=? AND r.status='aprovado' AND r.pago=0");
            $s->execute(array_merge($ids, [$pessoa])); $rs = $s->fetchAll();
            $total = 0;
            foreach ($rs as $r) {
                $pdo->prepare("UPDATE reembolsos SET pago=1, pago_em=NOW(), pago_por=?, comprovante_pagamento=? WHERE id=?")->execute([$u['id'], $arq, $r['id']]);
                $total += (float)$r['valor'];
            }
            if ($rs) {
                aprovLog($pdo, 'reembolso', (int)$rs[0]['id'], 'pago', 'Pagamento de ' . count($rs) . ' reembolso(s) a ' . $rs[0]['pessoa_nome'], $total, $pessoa, $u['id']);
                $msg = 'Pago: R$ ' . number_format($total, 2, ',', '.') . ' para ' . $rs[0]['pessoa_nome'] . '.';
            } else $err = 'Esses reembolsos já estavam pagos.';
        }
    }
    $_SESSION['flash_rpg'] = [$msg, $err];
    header('Location: reembolsos.php?' . ($_POST['qs'] ?? '')); exit;
}
[$sucesso, $erro] = $_SESSION['flash_rpg'] ?? ['', '']; unset($_SESSION['flash_rpg']);

$par = [];
if ($fSit === 'pendentes') $where = "r.status='pendente'";
elseif ($fSit === 'tudo') $where = "1=1";
else {
    $where = "r.status='aprovado'";
    if ($fSit === 'apagar') $where .= " AND r.pago=0"; elseif ($fSit === 'pagos') $where .= " AND r.pago=1";
}
if ($fOnde === 'oficina') $where .= " AND r.rally_id IS NULL";
elseif (ctype_digit((string)$fOnde)) { $where .= " AND r.rally_id=?"; $par[] = (int)$fOnde; }

$s = $pdo->prepare("SELECT r.*, u.nome AS pessoa_nome, u.tipo AS pessoa_tipo, u.pix_tipo, u.pix_chave, u.dados_bancarios, ra.nome AS rally_nome, c.nome AS cat_nome
    FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN rallys ra ON ra.id=r.rally_id LEFT JOIN rally_categorias c ON c.id=r.categoria_id
    WHERE $where ORDER BY u.nome, r.data_despesa, r.id");
$s->execute($par);
$pessoas = [];
foreach ($s->fetchAll() as $r) {
    $p = &$pessoas[$r['usuario_id']];
    $p['nome'] = $r['pessoa_nome']; $p['pix_tipo'] = $r['pix_tipo']; $p['pix_chave'] = $r['pix_chave']; $p['banco'] = $r['dados_bancarios'];
    $p['itens'][] = $r;
    $aprov = $r['status'] === 'aprovado';
    $p['apagar'] = ($p['apagar'] ?? 0) + ($aprov && !$r['pago'] ? (float)$r['valor'] : 0);
    $p['pago'] = ($p['pago'] ?? 0) + ($aprov && $r['pago'] ? (float)$r['valor'] : 0);
    unset($p);
}
$totApagar = array_sum(array_column($pessoas, 'apagar'));
$totPago = array_sum(array_column($pessoas, 'pago'));
$pendentes = (int)$pdo->query("SELECT COUNT(*) FROM reembolsos WHERE status='pendente'")->fetchColumn();
$rallys = $pdo->query("SELECT id,nome FROM rallys ORDER BY data_inicio DESC LIMIT 60")->fetchAll();
$nomeOnde = $fOnde === 'todas' ? 'Todas as corridas e oficina' : ($fOnde === 'oficina' ? 'Oficina' : ($pdo->query("SELECT nome FROM rallys WHERE id=" . (int)$fOnde)->fetchColumn() ?: 'Corrida'));
$R = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$tiposPix = ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'email' => 'E-mail', 'telefone' => 'Telefone', 'aleatoria' => 'Aleatória'];

require_once '../includes/header.php';
?>
<style>@media print{.navbar-varela,.no-print,footer{display:none!important}.card{box-shadow:none!important;border:1px solid #ccc!important;break-inside:avoid}body{background:#fff}}</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <a href="aprovacoes.php?aba=reembolsos" class="text-muted small text-decoration-none no-print"><i class="bi bi-arrow-left me-1"></i>Central de Aprovações</a>
    <h5 class="fw-bold mb-0 mt-1"><i class="bi bi-cash-stack me-2 text-danger"></i>Reembolsos — <?= htmlspecialchars($nomeOnde) ?></h5>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if ($pendentes): ?><a href="aprovacoes.php?aba=reembolsos" class="btn btn-warning btn-sm"><?= $pendentes ?> aguardando aprovação</a><?php endif; ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
  </div>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<form method="get" class="d-flex flex-wrap gap-2 mb-3 no-print">
  <select name="onde" class="form-select form-select-sm" style="max-width:280px" onchange="this.form.submit()">
    <option value="todas" <?= $fOnde === 'todas' ? 'selected' : '' ?>>Todas as corridas e oficina</option>
    <option value="oficina" <?= $fOnde === 'oficina' ? 'selected' : '' ?>>🔧 Oficina</option>
    <?php foreach ($rallys as $r): ?><option value="<?= $r['id'] ?>" <?= (string)$fOnde === (string)$r['id'] ? 'selected' : '' ?>>🏁 <?= htmlspecialchars($r['nome']) ?></option><?php endforeach; ?>
  </select>
  <select name="sit" class="form-select form-select-sm" style="max-width:320px" onchange="this.form.submit()">
    <?php foreach (['apagar' => 'A pagar', 'pagos' => 'Já pagos', 'todos' => 'Todos aprovados', 'pendentes' => 'Aguardando aprovação', 'tudo' => 'Todos os lançamentos (gerenciar / excluir)'] as $k => $l): ?><option value="<?= $k ?>" <?= $fSit === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
</form>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Total a pagar</div><div class="fs-4 fw-bold text-danger"><?= $R($totApagar) ?></div></div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Já pago</div><div class="fs-4 fw-bold text-success"><?= $R($totPago) ?></div></div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Pessoas</div><div class="fs-4 fw-bold"><?= count($pessoas) ?></div></div></div></div>
</div>

<?php if ($fSit !== 'tudo'): ?><div class="small text-muted mb-3 no-print"><i class="bi bi-info-circle me-1"></i>Para excluir um reembolso já aprovado ou pago, escolha <a href="?<?= htmlspecialchars($qs(['sit' => 'tudo'])) ?>">Todos os lançamentos</a>. Cada linha tem o botão Excluir.</div><?php endif; ?>
<?php if (!$pessoas): ?><div class="card border-0 shadow-sm text-center py-5 text-muted">Nenhum reembolso neste filtro.</div><?php endif; ?>

<?php foreach ($pessoas as $pid => $p): $abertos = array_filter($p['itens'], fn($r) => $r['status'] === 'aprovado' && !$r['pago']); ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><strong class="fs-6"><?= htmlspecialchars($p['nome']) ?></strong>
      <?php if ($p['pix_chave']): ?>
      <span class="ms-2 small">PIX (<?= $tiposPix[$p['pix_tipo']] ?? '' ?>): <code id="pix<?= $pid ?>"><?= htmlspecialchars(pixFmt($p['pix_tipo'], $p['pix_chave'])) ?></code>
        <button type="button" class="btn btn-sm btn-link py-0 no-print" onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($p['pix_chave']), ENT_QUOTES) ?>).then(()=>{this.innerHTML='<i class=\'bi bi-check2\'></i> copiado'})"><i class="bi bi-copy"></i> copiar</button></span>
      <?php else: ?><span class="badge bg-warning text-dark ms-2">Sem chave PIX cadastrada</span><?php endif; ?>
      <?php if ($p['banco']): ?><div class="small text-muted"><?= htmlspecialchars($p['banco']) ?></div><?php endif; ?>
    </div>
    <div class="text-end">
      <?php if ($p['apagar'] > 0): ?><div class="fw-bold text-danger fs-5"><?= $R($p['apagar']) ?> <span class="small fw-normal text-muted">a pagar</span></div><?php endif; ?>
      <?php if ($p['pago'] > 0): ?><div class="small text-success"><?= $R($p['pago']) ?> pago</div><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
    <thead><tr><th>Data</th><th>Despesa</th><th>Onde</th><th class="text-end">Valor</th><th>Comprovante</th><th>Situação</th><th class="text-end no-print">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($p['itens'] as $r): ?>
    <tr class="<?= $r['pago'] ? 'text-muted' : '' ?>">
      <td class="text-nowrap"><?= date('d/m/Y', strtotime($r['data_despesa'])) ?></td>
      <td><?= htmlspecialchars($r['descricao']) ?><?= $r['cat_nome'] ? ' <span class="text-muted">· ' . htmlspecialchars($r['cat_nome']) . '</span>' : '' ?></td>
      <td><?= $r['rally_nome'] ? htmlspecialchars($r['rally_nome']) : 'Oficina' ?></td>
      <td class="text-end fw-semibold"><?= $R($r['valor']) ?></td>
      <td><?php if ($r['comprovante']): ?><a href="<?= fotoUrl($r['comprovante']) ?>" target="_blank"><i class="bi bi-eye"></i> ver</a><?php else: ?><span class="text-warning">sem</span><?php endif; ?></td>
      <td><?= reembolsoStatusBadge($r) ?><?php if ($r['pago'] && $r['comprovante_pagamento']): ?> <a href="<?= fotoUrl($r['comprovante_pagamento']) ?>" target="_blank" class="small">PIX</a><?php endif; ?>
        <?php if ($r['status'] === 'recusado' && $r['motivo_recusa']): ?><div class="text-danger">Motivo: <?= htmlspecialchars($r['motivo_recusa']) ?></div><?php endif; ?></td>
      <td class="text-end text-nowrap no-print">
        <?php if ($r['status'] === 'pendente'): ?>
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="aprovar"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs()) ?>"><button class="btn btn-sm btn-success py-0"><i class="bi bi-check-lg"></i> Aprovar</button></form>
        <button type="button" class="btn btn-sm btn-outline-warning py-0" data-bs-toggle="collapse" data-bs-target="#rr<?= $r['id'] ?>"><i class="bi bi-x-lg"></i> Recusar</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-danger py-0" data-bs-toggle="collapse" data-bs-target="#rx<?= $r['id'] ?>"><i class="bi bi-trash3"></i> Excluir</button>
      </td>
    </tr>
    <?php if ($r['status'] === 'pendente'): ?>
    <tr class="collapse no-print" id="rr<?= $r['id'] ?>"><td colspan="7" class="bg-light">
      <form method="post" class="d-flex gap-2 align-items-center"><input type="hidden" name="acao" value="recusar"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs()) ?>">
        <input type="text" name="motivo" class="form-control form-control-sm" maxlength="255" placeholder="Motivo da recusa (aparece para a pessoa)">
        <button class="btn btn-sm btn-warning text-nowrap">Confirmar recusa</button></form></td></tr>
    <?php endif; ?>
    <tr class="collapse no-print" id="rx<?= $r['id'] ?>"><td colspan="7" class="bg-danger-subtle">
      <form method="post" class="d-flex gap-2 align-items-center flex-wrap"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs()) ?>">
        <span class="small fw-semibold text-danger"><?= $r['status'] === 'aprovado' ? ($r['pago'] ? 'Já foi PAGO.' : 'Já foi aprovado.') . ' Excluir também retira o valor dos gastos da corrida/oficina.' : 'Excluir de vez este lançamento?' ?></span>
        <input type="text" name="motivo" class="form-control form-control-sm" style="max-width:320px" maxlength="255" placeholder="Motivo (opcional, fica no histórico)">
        <button class="btn btn-sm btn-danger text-nowrap"><i class="bi bi-trash3 me-1"></i>Excluir definitivamente</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($abertos): ?>
  <div class="card-footer bg-light no-print">
    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="acao" value="pagar"><input type="hidden" name="usuario_id" value="<?= $pid ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs()) ?>">
      <?php foreach ($abertos as $r): ?><input type="hidden" name="ids[]" value="<?= $r['id'] ?>"><?php endforeach; ?>
      <label class="small mb-0">Comprovante do PIX (opcional):</label>
      <input type="file" name="comprovante_pagamento" accept="image/*,.pdf" class="form-control form-control-sm" style="max-width:260px" data-reduzir>
      <button class="btn btn-success btn-sm ms-auto" data-confirm="Marcar <?= $R($p['apagar']) ?> como PAGO para <?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>?"><i class="bi bi-check2-circle me-1"></i>Marcar <?= $R($p['apagar']) ?> como pago</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php require_once '../includes/footer.php'; ?>
