<?php
// reembolsos.php – Fase 2: a equipe lança despesas pagas do próprio bolso (corrida ou oficina) e acompanha aprovação e pagamento
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/aprovacoes.php';
requireLogin();
$u = usuarioLogado();
if (!in_array($u['tipo'], REEMBOLSO_TIPOS, true)) { header('Location: /index.php'); exit; }
$area = $u['tipo']; $titulo = 'Meus reembolsos';
$sucesso = $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'lancar') {
        $desc = trim($_POST['descricao'] ?? ''); $valor = aprovNum($_POST['valor'] ?? '');
        $rallyId = (int)($_POST['rally_id'] ?? 0) ?: null; $catId = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $data = $_POST['data_despesa'] ?? ''; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');
        $semComp = !empty($_POST['sem_comprovante']);
        if (!$desc || !$valor) $erro = 'Informe o que foi pago e o valor.';
        else {
            [$arq, $e] = reembolsoSalvarArquivo($_FILES['comprovante'] ?? [], 'reemb' . $u['id']);
            if ($e) $erro = $e;
            elseif (!$arq && !$semComp) $erro = 'Tire a foto da nota/recibo, ou marque "Estou sem comprovante".';
            else {
                $pdo->prepare("INSERT INTO reembolsos (usuario_id,rally_id,categoria_id,descricao,valor,data_despesa,comprovante,sem_comprovante) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$u['id'], $rallyId, $catId, mb_substr($desc, 0, 255), $valor, $data, $arq, $arq ? 0 : 1]);
                $sucesso = 'Reembolso enviado para aprovação: R$ ' . number_format($valor, 2, ',', '.');
            }
        }
    } elseif ($acao === 'excluir') {
        $r = $pdo->prepare("SELECT * FROM reembolsos WHERE id=? AND usuario_id=? AND status='pendente'"); $r->execute([(int)($_POST['id'] ?? 0), $u['id']]); $r = $r->fetch();
        if ($r) { fotoApagar($r['comprovante']); $pdo->prepare("DELETE FROM reembolsos WHERE id=?")->execute([$r['id']]); $sucesso = 'Lançamento excluído.'; }
    }
    $_SESSION['flash_reemb'] = [$sucesso, $erro];
    if ($erro) $_SESSION['reemb_form'] = $_POST;
    header('Location: reembolsos.php'); exit;
}
if (!empty($_SESSION['flash_reemb'])) { [$sucesso, $erro] = $_SESSION['flash_reemb']; unset($_SESSION['flash_reemb']); }
$form = $_SESSION['reemb_form'] ?? []; unset($_SESSION['reemb_form']);

$eu = $pdo->prepare("SELECT pix_tipo, pix_chave FROM usuarios WHERE id=?"); $eu->execute([$u['id']]); $eu = $eu->fetch();
$rallys = $pdo->query("SELECT id,nome,data_inicio FROM rallys WHERE ativo=1 ORDER BY (data_inicio IS NULL), ABS(DATEDIFF(data_inicio, CURDATE())) LIMIT 30")->fetchAll();
$categorias = $pdo->query("SELECT id,nome FROM rally_categorias WHERE ativo=1 ORDER BY nome")->fetchAll();
$meus = $pdo->prepare("SELECT r.*, ra.nome AS rally_nome, c.nome AS cat_nome FROM reembolsos r LEFT JOIN rallys ra ON ra.id=r.rally_id LEFT JOIN rally_categorias c ON c.id=r.categoria_id WHERE r.usuario_id=? ORDER BY r.criado_em DESC LIMIT 200");
$meus->execute([$u['id']]); $meus = $meus->fetchAll();
$soma = fn($cond) => array_sum(array_map(fn($r) => (float)$r['valor'], array_filter($meus, $cond)));
$aReceber = $soma(fn($r) => $r['status'] === 'aprovado' && !$r['pago']);
$emAnalise = $soma(fn($r) => $r['status'] === 'pendente');
$R = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

require_once 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-xl-9">
<h5 class="fw-bold mb-3"><i class="bi bi-cash-coin me-2 text-danger"></i>Meus reembolsos</h5>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if (empty($eu['pix_chave'])): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-2"></i>Você ainda não cadastrou sua chave PIX. <a href="<?= BASE_URL ?>/perfil.php" class="alert-link">Cadastrar no Meu perfil →</a></div><?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">A receber (aprovado)</div><div class="fs-4 fw-bold text-success"><?= $R($aReceber) ?></div></div></div></div>
  <div class="col-6"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Em análise</div><div class="fs-4 fw-bold text-warning"><?= $R($emAnalise) ?></div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-plus-circle me-2 text-success"></i>Lançar despesa que paguei</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="lancar">
      <label class="btn btn-outline-danger w-100 py-3 mb-3"><i class="bi bi-camera fs-4 d-block"></i>Foto da nota / recibo
        <input type="file" name="comprovante" accept="image/*,.pdf" capture="environment" hidden data-reduzir data-preview="prevReemb"></label>
      <div id="prevReemb" class="d-flex gap-1 flex-wrap mb-2"></div>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small mb-0">Onde</label>
          <select name="rally_id" class="form-select"><option value="">🔧 Oficina (fora de corrida)</option>
            <?php foreach ($rallys as $r): ?><option value="<?= $r['id'] ?>" <?= ($form['rally_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>🏁 <?= htmlspecialchars($r['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Categoria</label>
          <select name="categoria_id" class="form-select"><option value="">—</option>
            <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= ($form['categoria_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">O que foi pago *</label><input type="text" name="descricao" class="form-control" maxlength="255" required placeholder="Ex.: combustível do caminhão de apoio" value="<?= htmlspecialchars($form['descricao'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small mb-0">Valor *</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor" class="form-control" inputmode="decimal" required placeholder="0,00" value="<?= htmlspecialchars($form['valor'] ?? '') ?>"></div></div>
        <div class="col-6"><label class="form-label small mb-0">Data</label><input type="date" name="data_despesa" class="form-control" value="<?= htmlspecialchars($form['data_despesa'] ?? date('Y-m-d')) ?>"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="sem_comprovante" value="1" id="semComp"><label class="form-check-label small" for="semComp">Estou sem comprovante (o administrador decide)</label></div></div>
      </div>
      <button class="btn btn-success w-100 mt-3"><i class="bi bi-send me-1"></i>Enviar para aprovação</button>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-list-check me-2"></i>Meus lançamentos</div>
  <?php if ($meus): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th>Data</th><th>Despesa</th><th>Onde</th><th class="text-end">Valor</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($meus as $r): ?>
    <tr>
      <td class="text-nowrap"><?= date('d/m/Y', strtotime($r['data_despesa'])) ?></td>
      <td><?= htmlspecialchars($r['descricao']) ?><?= $r['cat_nome'] ? '<div class="text-muted">' . htmlspecialchars($r['cat_nome']) . '</div>' : '' ?>
        <?php if ($r['status'] === 'recusado' && $r['motivo_recusa']): ?><div class="text-danger">Motivo: <?= htmlspecialchars($r['motivo_recusa']) ?></div><?php endif; ?></td>
      <td><?= $r['rally_nome'] ? htmlspecialchars($r['rally_nome']) : 'Oficina' ?></td>
      <td class="text-end fw-semibold"><?= $R($r['valor']) ?></td>
      <td><?= reembolsoStatusBadge($r) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($r['comprovante']): ?><a href="<?= fotoUrl($r['comprovante']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i></a><?php endif; ?>
        <?php if ($r['status'] === 'pendente'): ?><form method="post" class="d-inline"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Excluir este lançamento?"><i class="bi bi-trash3"></i></button></form><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum lançamento ainda.</p><?php endif; ?>
</div>
</div></div>
<?php require_once 'includes/footer.php'; ?>
