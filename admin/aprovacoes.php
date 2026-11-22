<?php
// admin/aprovacoes.php – Fase 2: Central de Aprovações
// Abas: Fotos · Comprovantes · Pedidos e itens · Navegador · Reembolsos · Histórico. Aprovar/recusar sem recarregar e em lote.
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/aprovacoes.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Aprovações';

$abas = [
    'fotos'        => ['Fotos', 'bi-images', '#0d6efd'],
    'comprovantes' => ['Comprovantes', 'bi-receipt', '#198754'],
    'pedidos'      => ['Pedidos e itens', 'bi-chat-square-text', '#6f42c1'],
    'navegador'    => ['Navegador', 'bi-compass', '#fd7e14'],
    'reembolsos'   => ['Reembolsos', 'bi-cash-coin', '#dc3545'],
    'historico'    => ['Histórico', 'bi-clock-history', '#6c757d'],
];
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

// ───────────── Ações ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $id = (int)($_POST['id'] ?? 0); $msg = ''; $err = null;

    $fotoAprovar = function (int $fid) use ($pdo, $u) {
        $f = $pdo->prepare("SELECT f.id, f.enviado_por, os.numero_os FROM fotos f JOIN ordens_servico os ON os.id=f.ordem_servico_id WHERE f.id=? AND f.aprovada=0"); $f->execute([$fid]); $f = $f->fetch();
        if (!$f) return false;
        $pdo->prepare("UPDATE fotos SET aprovada=1 WHERE id=?")->execute([$fid]);
        aprovLog($pdo, 'foto', $fid, 'aprovado', 'Foto da OS ' . $f['numero_os'], null, (int)$f['enviado_por'], $u['id']);
        return true;
    };
    $compConfirmar = function (int $cid) use ($pdo, $u) {
        $c = $pdo->prepare("SELECT c.*, u.nome AS cliente_nome FROM comprovantes c JOIN usuarios u ON u.id=c.cliente_id WHERE c.id=? AND c.status='pendente'"); $c->execute([$cid]); $c = $c->fetch();
        if (!$c) return false;
        $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_id,comprovante_arquivo) VALUES (?,?,?,'credito',?,?,?)")
            ->execute([$c['cliente_id'], 'Pagamento via comprovante' . ($c['descricao'] ? ' – ' . $c['descricao'] : ''), $c['valor_declarado'], $u['id'], $c['id'], $c['arquivo']]);
        $pdo->prepare("UPDATE comprovantes SET status='confirmado',admin_id=?,confirmado_em=NOW(),cc_id=? WHERE id=?")->execute([$u['id'], $pdo->lastInsertId(), $c['id']]);
        aprovLog($pdo, 'comprovante', $cid, 'aprovado', 'Pagamento de ' . $c['cliente_nome'], (float)$c['valor_declarado'], (int)$c['cliente_id'], $u['id']);
        return true;
    };

    switch ($acao) {
    case 'foto_aprovar':
        if ($fotoAprovar($id)) $msg = 'Foto aprovada.'; else $err = 'Foto já analisada.';
        break;
    case 'foto_recusar':
        $f = $pdo->prepare("SELECT f.ordem_servico_id, f.enviado_por, os.numero_os FROM fotos f JOIN ordens_servico os ON os.id=f.ordem_servico_id WHERE f.id=? AND f.aprovada=0"); $f->execute([$id]); $f = $f->fetch();
        if ($f) { osApagarFoto($pdo, $id, (int)$f['ordem_servico_id']); aprovLog($pdo, 'foto', $id, 'recusado', 'Foto da OS ' . $f['numero_os'] . ' (apagada)', null, (int)$f['enviado_por'], $u['id']); $msg = 'Foto recusada e apagada.'; }
        else $err = 'Foto já analisada.';
        break;
    case 'fotos_os_aprovar':
        $ids = $pdo->prepare("SELECT id FROM fotos WHERE ordem_servico_id=? AND aprovada=0"); $ids->execute([$id]); $n = 0;
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $fid) $n += $fotoAprovar((int)$fid) ? 1 : 0;
        $msg = "$n foto(s) aprovada(s).";
        break;
    case 'comp_confirmar':
        if ($compConfirmar($id)) $msg = 'Comprovante confirmado e creditado.'; else $err = 'Comprovante já analisado.';
        break;
    case 'comp_rejeitar':
        $c = $pdo->prepare("SELECT c.*, u.nome AS cliente_nome FROM comprovantes c JOIN usuarios u ON u.id=c.cliente_id WHERE c.id=? AND c.status='pendente'"); $c->execute([$id]); $c = $c->fetch();
        if ($c) {
            fotoApagar('comprovantes/' . $c['arquivo']); $pdo->prepare("DELETE FROM comprovantes WHERE id=?")->execute([$id]);
            aprovLog($pdo, 'comprovante', $id, 'recusado', 'Pagamento de ' . $c['cliente_nome'], (float)$c['valor_declarado'], (int)$c['cliente_id'], $u['id'], trim($_POST['motivo'] ?? '') ?: null);
            $msg = 'Comprovante rejeitado.';
        } else $err = 'Comprovante já analisado.';
        break;
    case 'item_aprovar': $err = aprovItemAprovar($pdo, $id, $u['id'], aprovNum($_POST['valor'] ?? ''), aprovNum($_POST['valor_custo'] ?? '')); $msg = 'Item aprovado.'; break;
    case 'item_recusar': $err = aprovItemRecusar($pdo, $id, $u['id'], $_POST['motivo'] ?? ''); $msg = 'Item recusado.'; break;
    case 'nav_ok': $msg = navOsOkAdmin($pdo, $id, $u['id']); break;
    case 'reemb_aprovar': $err = reembolsoAprovar($pdo, $id, $u['id']); $msg = 'Reembolso aprovado.'; break;
    case 'reemb_recusar': $err = reembolsoRecusar($pdo, $id, $u['id'], $_POST['motivo'] ?? ''); $msg = 'Reembolso recusado.'; break;
    case 'lote':
        $tipo = $_POST['tipo'] ?? ''; $ids = array_map('intval', $_POST['ids'] ?? []); $n = 0;
        foreach ($ids as $lid) {
            if ($tipo === 'fotos') $n += $fotoAprovar($lid) ? 1 : 0;
            elseif ($tipo === 'comprovantes') $n += $compConfirmar($lid) ? 1 : 0;
            elseif ($tipo === 'pedidos') $n += aprovItemAprovar($pdo, $lid, $u['id']) === null ? 1 : 0;
            elseif ($tipo === 'reembolsos') $n += reembolsoAprovar($pdo, $lid, $u['id']) === null ? 1 : 0;
        }
        if ($n) $msg = "$n aprovado(s) em lote."; else $err = 'Nada selecionado.';
        break;
    default: $err = 'Ação desconhecida.';
    }
    if ($err) $msg = '';
    if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => !$err, 'msg' => $err ?: $msg, 'cont' => aprovContagens($pdo)], JSON_UNESCAPED_UNICODE); exit; }
    $_SESSION['flash_aprov'] = [$msg, $err];
    header('Location: aprovacoes.php?aba=' . urlencode($_POST['aba'] ?? 'fotos')); exit;
}
[$sucesso, $erro] = $_SESSION['flash_aprov'] ?? ['', '']; unset($_SESSION['flash_aprov']);

$cont = aprovContagens($pdo);
$aba = $_GET['aba'] ?? null;
if (!isset($abas[$aba])) { $aba = 'historico'; foreach (array_keys($abas) as $k) if (($cont[$k] ?? 0) > 0) { $aba = $k; break; } }
$R = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$quando = fn($d) => date('d/m/Y H:i', strtotime($d));

// ───────────── Dados da aba ─────────────
$lista = [];
switch ($aba) {
case 'fotos':
    $rows = $pdo->query("SELECT f.*, os.numero_os, os.titulo AS os_titulo, uc.nome AS cliente_nome, ue.nome AS enviado_nome, a.descricao AS afazer_desc,
            (SELECT GROUP_CONCAT(oi.descricao SEPARATOR ', ') FROM os_item_fotos l JOIN os_itens oi ON oi.id=l.item_id WHERE l.foto_id=f.id) AS itens_desc
        FROM fotos f JOIN ordens_servico os ON os.id=f.ordem_servico_id JOIN usuarios uc ON uc.id=os.cliente_id JOIN usuarios ue ON ue.id=f.enviado_por
        LEFT JOIN os_afazeres a ON a.id=f.afazer_id WHERE f.aprovada=0 ORDER BY f.criado_em")->fetchAll();
    foreach ($rows as $f) { $lista[$f['ordem_servico_id']]['os'] = $f; $lista[$f['ordem_servico_id']]['fotos'][] = $f; }
    break;
case 'comprovantes':
    $lista = $pdo->query("SELECT c.*, u.nome AS cliente_nome FROM comprovantes c JOIN usuarios u ON u.id=c.cliente_id WHERE c.status='pendente' ORDER BY c.criado_em")->fetchAll();
    break;
case 'pedidos':
    $lista = $pdo->query("SELECT oi.*, os.numero_os, os.titulo AS os_titulo, os.status AS os_status, uc.nome AS cliente_nome, us.nome AS solicitante_nome, um.nome AS mecanico_nome,
            (SELECT COUNT(*) FROM os_item_fotos l WHERE l.item_id=oi.id) AS n_fotos
        FROM os_itens oi JOIN ordens_servico os ON os.id=oi.ordem_servico_id JOIN usuarios uc ON uc.id=os.cliente_id
        LEFT JOIN usuarios us ON us.id=oi.solicitado_por LEFT JOIN usuarios um ON um.id=os.mecanico_id
        WHERE oi.status_aprovacao='pendente' AND os.status<>'cancelada' ORDER BY oi.criado_em")->fetchAll();
    break;
case 'navegador':
    $lista = $pdo->query("SELECT n.*, u.nome AS navegador_nome, p.nome AS piloto_nome, r.nome AS rally_nome, (SELECT COUNT(*) FROM nav_os_itens i WHERE i.nav_os_id=n.id) AS n_itens
        FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id JOIN usuarios p ON p.id=n.piloto_id LEFT JOIN rallys r ON r.id=n.rally_id
        WHERE n.status='encerrada' AND n.ok_admin=0 ORDER BY n.atualizado_em")->fetchAll();
    break;
case 'reembolsos':
    $lista = $pdo->query("SELECT r.*, u.nome AS pessoa_nome, u.tipo AS pessoa_tipo, ra.nome AS rally_nome, c.nome AS cat_nome, c.cor AS cat_cor
        FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN rallys ra ON ra.id=r.rally_id LEFT JOIN rally_categorias c ON c.id=r.categoria_id
        WHERE r.status='pendente' ORDER BY r.criado_em")->fetchAll();
    break;
case 'historico':
    $tiposHist = ['foto' => 'Foto', 'comprovante' => 'Comprovante', 'pedido_cliente' => 'Pedido do cliente', 'item_mecanico' => 'Item do mecânico', 'navegador' => 'OS navegador', 'reembolso' => 'Reembolso'];
    $fTipo = $_GET['f_tipo'] ?? ''; $fAcao = $_GET['f_acao'] ?? '';
    $where = '1=1'; $par = [];
    if (isset($tiposHist[$fTipo])) { $where .= ' AND l.tipo=?'; $par[] = $fTipo; }
    if (in_array($fAcao, ['aprovado', 'recusado', 'pago'], true)) { $where .= ' AND l.acao=?'; $par[] = $fAcao; }
    $pag = max(1, (int)($_GET['p'] ?? 1)); $porPag = 50;
    $tot = $pdo->prepare("SELECT COUNT(*) FROM aprovacoes_log l WHERE $where"); $tot->execute($par); $totHist = (int)$tot->fetchColumn();
    $s = $pdo->prepare("SELECT l.*, s.nome AS solicitante_nome, a.nome AS admin_nome FROM aprovacoes_log l LEFT JOIN usuarios s ON s.id=l.solicitante_id LEFT JOIN usuarios a ON a.id=l.admin_id
        WHERE $where ORDER BY l.criado_em DESC, l.id DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
    $s->execute($par); $lista = $s->fetchAll();
    break;
}

require_once '../includes/header.php';
?>
<style>
  .ap-tabs .nav-link{color:#495057;font-weight:500;display:flex;align-items:center;gap:6px}
  .ap-tabs .nav-link.active{font-weight:700}
  .ap-tabs .cnt{font-size:.7rem;border-radius:10px;padding:1px 7px;color:#fff}
  .ap-tabs .cnt.zero{background:#ced4da!important}
  [data-linha].saindo{opacity:0;transform:translateX(30px);transition:all .3s}
  .ap-toast{position:fixed;bottom:20px;right:20px;z-index:2000;min-width:240px}
  .ap-acoes{white-space:nowrap}
  .ap-valor{max-width:96px}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-inbox me-2 text-danger"></i>Central de Aprovações</h5>
  <span class="badge fs-6 <?= $cont['total'] ? 'bg-danger' : 'bg-success' ?>" data-cont="total"><?= $cont['total'] ?: 'Tudo em dia' ?></span>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<ul class="nav nav-tabs ap-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($abas as $k => [$l, $ic, $cor]): $n = $cont[$k] ?? null; ?>
  <li class="nav-item"><a class="nav-link text-nowrap <?= $aba === $k ? 'active' : '' ?>" href="?aba=<?= $k ?>"><i class="bi <?= $ic ?>" style="color:<?= $cor ?>"></i><?= $l ?>
    <?php if ($n !== null): ?><span class="cnt <?= $n ? '' : 'zero' ?>" style="background:<?= $cor ?>" data-cont="<?= $k ?>"><?= $n ?></span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul>

<?php $vazio = fn($txt) => '<div class="card border-0 shadow-sm text-center py-5 text-success"><i class="bi bi-check2-circle fs-1 d-block mb-1"></i>' . $txt . '</div>'; ?>

<?php if ($aba === 'fotos'): ?>
  <?php if (!$lista): echo $vazio('Nenhuma foto aguardando aprovação.'); else: ?>
  <div class="d-flex justify-content-end mb-2"><form method="post" class="m-0"><input type="hidden" name="acao" value="lote"><input type="hidden" name="tipo" value="fotos"><input type="hidden" name="aba" value="fotos">
    <?php foreach ($lista as $g) foreach ($g['fotos'] as $f): ?><input type="hidden" name="ids[]" value="<?= $f['id'] ?>"><?php endforeach; ?>
    <button class="btn btn-success btn-sm" data-confirm="Aprovar TODAS as fotos pendentes?"><i class="bi bi-check2-all me-1"></i>Aprovar todas (<?= $cont['fotos'] ?>)</button></form></div>
  <?php foreach ($lista as $oid => $g): $o = $g['os']; ?>
  <div class="card border-0 shadow-sm mb-3" data-grupo>
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="small"><span class="badge bg-dark me-1"><?= htmlspecialchars($o['numero_os'] ?? '') ?></span><strong><?= htmlspecialchars($o['os_titulo']) ?></strong> <span class="text-muted">· <?= htmlspecialchars($o['cliente_nome']) ?> · enviadas por <?= htmlspecialchars($o['enviado_nome']) ?></span></div>
      <div class="d-flex gap-1">
        <a href="editar_os.php?id=<?= $oid ?>#fotos" class="btn btn-sm btn-outline-secondary py-0">Abrir OS</a>
        <form method="post" class="m-0 js-ap" data-remove-grupo><input type="hidden" name="acao" value="fotos_os_aprovar"><input type="hidden" name="id" value="<?= $oid ?>"><input type="hidden" name="aba" value="fotos"><button class="btn btn-sm btn-success py-0"><i class="bi bi-check2-all me-1"></i>Aprovar <?= count($g['fotos']) ?> desta OS</button></form>
      </div>
    </div>
    <div class="card-body"><div class="pend-os-fotos">
      <?php foreach ($g['fotos'] as $f): $leg = $f['afazer_desc'] ?: ($f['itens_desc'] ?: 'Geral'); ?>
      <div class="pf" data-linha>
        <img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="ap<?= $oid ?>" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($leg . ($f['descricao'] ? ' · ' . $f['descricao'] : '')) ?>" title="<?= htmlspecialchars($leg) ?>">
        <div class="text-truncate text-muted" style="font-size:.62rem"><?= htmlspecialchars($leg) ?> · <?= date('d/m H:i', strtotime($f['criado_em'])) ?></div>
        <div class="d-flex gap-1">
          <form method="post" class="flex-fill m-0 js-ap"><input type="hidden" name="acao" value="foto_aprovar"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="aba" value="fotos"><button class="btn btn-success btn-sm w-100 py-0" title="Aprovar"><i class="bi bi-check-lg"></i></button></form>
          <form method="post" class="flex-fill m-0 js-ap"><input type="hidden" name="acao" value="foto_recusar"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="aba" value="fotos"><button class="btn btn-outline-danger btn-sm w-100 py-0" title="Recusar e apagar" data-confirm="Recusar e apagar esta foto?"><i class="bi bi-x-lg"></i></button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?php endforeach; endif; ?>

<?php elseif ($aba === 'comprovantes'): ?>
  <?php if (!$lista): echo $vazio('Nenhum comprovante aguardando.'); else: ?>
  <form method="post" id="loteComp" class="m-0"><input type="hidden" name="acao" value="lote"><input type="hidden" name="tipo" value="comprovantes"><input type="hidden" name="aba" value="comprovantes"></form>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th style="width:32px"><input type="checkbox" class="form-check-input" data-marcar="loteComp"></th><th>Cliente</th><th>Valor declarado</th><th>Descrição</th><th>Enviado em</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $c): ?>
    <tr data-linha>
      <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $c['id'] ?>" form="loteComp"></td>
      <td class="fw-semibold"><?= htmlspecialchars($c['cliente_nome']) ?></td>
      <td class="fw-bold text-success"><?= $R($c['valor_declarado']) ?></td>
      <td class="small text-muted"><?= htmlspecialchars($c['descricao'] ?? '—') ?></td>
      <td class="small text-muted"><?= $quando($c['criado_em']) ?></td>
      <td class="text-end ap-acoes">
        <a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($c['arquivo']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i> Ver</a>
        <form method="post" class="d-inline m-0 js-ap"><input type="hidden" name="acao" value="comp_confirmar"><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="aba" value="comprovantes"><button class="btn btn-sm btn-success py-0" data-confirm="Confirmar e creditar <?= $R($c['valor_declarado']) ?> para <?= htmlspecialchars($c['cliente_nome'], ENT_QUOTES) ?>?"><i class="bi bi-check-lg"></i> Confirmar</button></form>
        <form method="post" class="d-inline m-0 js-ap" data-motivo="Motivo da rejeição (o comprovante será apagado):"><input type="hidden" name="acao" value="comp_rejeitar"><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="aba" value="comprovantes"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i> Rejeitar</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer bg-white"><button class="btn btn-sm btn-success" form="loteComp" data-confirm="Confirmar e creditar os comprovantes selecionados?"><i class="bi bi-check2-all me-1"></i>Confirmar selecionados</button></div>
  </div>
  <?php endif; ?>

<?php elseif ($aba === 'pedidos'): ?>
  <?php if (!$lista): echo $vazio('Nenhum pedido de cliente ou item do mecânico aguardando.'); else: ?>
  <p class="small text-muted mb-2"><i class="bi bi-info-circle me-1"></i><strong>Pedido do cliente</strong> aprovado vira um afazer do mecânico. <strong>Item do mecânico</strong> aprovado aparece para o cliente. Valores são opcionais: sem valor, aparece como R$ 0,00.</p>
  <form method="post" id="lotePed" class="m-0"><input type="hidden" name="acao" value="lote"><input type="hidden" name="tipo" value="pedidos"><input type="hidden" name="aba" value="pedidos"></form>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th style="width:32px"><input type="checkbox" class="form-check-input" data-marcar="lotePed"></th><th>Origem</th><th>OS</th><th>Pedido / item</th><th>Quem · quando</th><th>Custo</th><th>Venda</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $it): $fid = 'ap' . $it['id']; ?>
    <tr data-linha>
      <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $it['id'] ?>" form="lotePed"></td>
      <td><?= $it['origem'] === 'cliente' ? '<span class="badge bg-primary">Cliente</span>' : '<span class="badge bg-success">Mecânico</span>' ?></td>
      <td class="small"><a href="editar_os.php?id=<?= $it['ordem_servico_id'] ?>" class="badge bg-dark text-decoration-none"><?= htmlspecialchars($it['numero_os']) ?></a><div class="text-muted"><?= htmlspecialchars($it['cliente_nome']) ?></div></td>
      <td class="fw-semibold"><?= htmlspecialchars($it['descricao']) ?><?php if ($it['n_fotos']): ?> <a href="editar_os.php?id=<?= $it['ordem_servico_id'] ?>#encontrados" class="small"><i class="bi bi-camera-fill"></i> <?= $it['n_fotos'] ?></a><?php endif; ?></td>
      <td class="small text-muted"><?= htmlspecialchars($it['solicitante_nome'] ?? ($it['origem'] === 'mecanico' ? ($it['mecanico_nome'] ?? 'Mecânico') : '—')) ?><div><?= $quando($it['criado_em']) ?></div></td>
      <td><input type="text" name="valor_custo" form="<?= $fid ?>" class="form-control form-control-sm ap-valor" placeholder="custo" inputmode="decimal"></td>
      <td><input type="text" name="valor" form="<?= $fid ?>" class="form-control form-control-sm ap-valor" placeholder="venda" inputmode="decimal"></td>
      <td class="text-end ap-acoes">
        <form method="post" id="<?= $fid ?>" class="d-inline m-0 js-ap"><input type="hidden" name="acao" value="item_aprovar"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="aba" value="pedidos"><button class="btn btn-sm btn-success py-0"><i class="bi bi-check-lg"></i> Aprovar</button></form>
        <form method="post" class="d-inline m-0 js-ap" data-motivo="Motivo da recusa (aparece para quem pediu):"><input type="hidden" name="acao" value="item_recusar"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="aba" value="pedidos"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i> Recusar</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer bg-white"><button class="btn btn-sm btn-success" form="lotePed" data-confirm="Aprovar os selecionados sem definir valor?"><i class="bi bi-check2-all me-1"></i>Aprovar selecionados (sem valor)</button></div>
  </div>
  <?php endif; ?>

<?php elseif ($aba === 'navegador'): ?>
  <?php if (!$lista): echo $vazio('Nenhuma OS de navegador aguardando.'); else: ?>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>OS</th><th>Navegador → piloto</th><th>Corrida</th><th>Cobrar de</th><th class="text-center">Itens</th><th class="text-end">Valor</th><th>Piloto</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $n): $equipe = ($n['cobrar_de'] ?? 'piloto') === 'equipe'; ?>
    <tr data-linha>
      <td class="fw-semibold"><?= htmlspecialchars($n['titulo']) ?><div class="small text-muted">encerrada <?= $quando($n['atualizado_em'] ?? $n['criado_em']) ?></div></td>
      <td class="small"><?= htmlspecialchars($n['navegador_nome']) ?> → <?= htmlspecialchars($n['piloto_nome']) ?></td>
      <td class="small"><?= htmlspecialchars($n['rally_nome'] ?? '—') ?></td>
      <td><?= $equipe ? '<span class="badge bg-dark">Equipe</span>' : '<span class="badge bg-info text-dark">Piloto</span>' ?></td>
      <td class="text-center"><span class="badge bg-secondary"><?= $n['n_itens'] ?></span></td>
      <td class="text-end fw-bold"><?= $R($n['valor_total']) ?></td>
      <td class="small"><?= $equipe ? '<span class="text-muted">não precisa</span>' : ($n['ok_cliente'] ? '✅ aprovou' : '⏳ aguardando') ?></td>
      <td class="text-end ap-acoes">
        <a href="aprovar_nav_os.php?id=<?= $n['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i> Revisar</a>
        <form method="post" class="d-inline m-0 js-ap"><input type="hidden" name="acao" value="nav_ok"><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="aba" value="navegador"><button class="btn btn-sm btn-success py-0" data-confirm="Aprovar a OS '<?= htmlspecialchars($n['titulo'], ENT_QUOTES) ?>' (<?= $R($n['valor_total']) ?>)?"><i class="bi bi-check-lg"></i> Aprovar</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>

<?php elseif ($aba === 'reembolsos'): ?>
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <span class="small text-muted">Aprovados de uma corrida entram em "Gastos" daquela corrida.</span>
    <a href="reembolsos.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-cash-stack me-1"></i>Pagamentos de reembolso →</a>
  </div>
  <?php if (!$lista): echo $vazio('Nenhum reembolso aguardando.'); else: ?>
  <form method="post" id="loteReemb" class="m-0"><input type="hidden" name="acao" value="lote"><input type="hidden" name="tipo" value="reembolsos"><input type="hidden" name="aba" value="reembolsos"></form>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th style="width:32px"><input type="checkbox" class="form-check-input" data-marcar="loteReemb"></th><th>Pessoa</th><th>Onde</th><th>Despesa</th><th>Data</th><th class="text-end">Valor</th><th>Comprovante</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $r): ?>
    <tr data-linha>
      <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $r['id'] ?>" form="loteReemb"></td>
      <td class="fw-semibold"><?= htmlspecialchars($r['pessoa_nome']) ?><div class="small text-muted fw-normal"><?= $quando($r['criado_em']) ?></div></td>
      <td class="small"><?= $r['rally_nome'] ? '<i class="bi bi-flag me-1"></i>' . htmlspecialchars($r['rally_nome']) : '<i class="bi bi-wrench me-1"></i>Oficina' ?></td>
      <td><?= htmlspecialchars($r['descricao']) ?><?php if ($r['cat_nome']): ?><div><span class="badge" style="background:<?= htmlspecialchars($r['cat_cor'] ?: '#6c757d') ?>"><?= htmlspecialchars($r['cat_nome']) ?></span></div><?php endif; ?></td>
      <td class="small"><?= date('d/m/Y', strtotime($r['data_despesa'])) ?></td>
      <td class="text-end fw-bold"><?= $R($r['valor']) ?></td>
      <td><?php if ($r['comprovante']): ?><a href="<?= fotoUrl($r['comprovante']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i> Ver</a><?php else: ?><span class="badge bg-warning text-dark">Sem comprovante</span><?php endif; ?></td>
      <td class="text-end ap-acoes">
        <form method="post" class="d-inline m-0 js-ap"><input type="hidden" name="acao" value="reemb_aprovar"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="aba" value="reembolsos"><button class="btn btn-sm btn-success py-0"><i class="bi bi-check-lg"></i> Aprovar</button></form>
        <form method="post" class="d-inline m-0 js-ap" data-motivo="Motivo da recusa (aparece para a pessoa):"><input type="hidden" name="acao" value="reemb_recusar"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="aba" value="reembolsos"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i> Recusar</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer bg-white"><button class="btn btn-sm btn-success" form="loteReemb" data-confirm="Aprovar os reembolsos selecionados?"><i class="bi bi-check2-all me-1"></i>Aprovar selecionados</button></div>
  </div>
  <?php endif; ?>

<?php else: /* histórico */ ?>
  <form method="get" class="d-flex flex-wrap gap-2 mb-2"><input type="hidden" name="aba" value="historico">
    <select name="f_tipo" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()"><option value="">Todos os tipos</option>
      <?php foreach ($tiposHist as $k => $l): ?><option value="<?= $k ?>" <?= $fTipo === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <select name="f_acao" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()"><option value="">Aprovados, recusados e pagos</option>
      <?php foreach (['aprovado' => 'Aprovados', 'recusado' => 'Recusados', 'pago' => 'Pagos'] as $k => $l): ?><option value="<?= $k ?>" <?= $fAcao === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <span class="small text-muted align-self-center"><?= $totHist ?> registro(s)</span>
  </form>
  <?php if (!$lista): ?><div class="card border-0 shadow-sm text-center py-4 text-muted">Nada no histórico ainda.</div><?php else: ?>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Quando</th><th>Tipo</th><th>Resumo</th><th class="text-end">Valor</th><th>Resultado</th><th>Pedido por</th><th>Analisado por</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $l): ?>
    <tr>
      <td class="small text-nowrap"><?= $quando($l['criado_em']) ?></td>
      <td class="small"><?= $tiposHist[$l['tipo']] ?? htmlspecialchars($l['tipo']) ?></td>
      <td class="small"><?= htmlspecialchars($l['resumo']) ?><?php if ($l['motivo']): ?><div class="text-danger">Motivo: <?= htmlspecialchars($l['motivo']) ?></div><?php endif; ?></td>
      <td class="text-end small"><?= $l['valor'] !== null ? $R($l['valor']) : '—' ?></td>
      <td><?= ['aprovado' => '<span class="badge bg-success">Aprovado</span>', 'recusado' => '<span class="badge bg-danger">Recusado</span>', 'pago' => '<span class="badge bg-primary">Pago</span>'][$l['acao']] ?? htmlspecialchars($l['acao']) ?></td>
      <td class="small text-muted"><?= htmlspecialchars($l['solicitante_nome'] ?? '—') ?></td>
      <td class="small text-muted"><?= htmlspecialchars($l['admin_nome'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php $paginas = (int)ceil($totHist / $porPag); if ($paginas > 1): ?>
    <div class="card-footer bg-white d-flex gap-1 flex-wrap">
      <?php for ($i = 1; $i <= $paginas; $i++): ?><a href="?<?= htmlspecialchars(http_build_query(['aba' => 'historico', 'f_tipo' => $fTipo, 'f_acao' => $fAcao, 'p' => $i])) ?>" class="btn btn-sm <?= $i === $pag ? 'btn-dark' : 'btn-light border' ?>"><?= $i ?></a><?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="ap-toast"></div>

<!-- Caixa do motivo da recusa (substitui a janela prompt() do navegador, que alguns navegadores bloqueiam) -->
<div class="modal fade" id="modalMotivo" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-x-circle text-danger me-2"></i>Recusar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label small" id="motivoLabel">Motivo</label>
        <textarea id="motivoTxt" class="form-control" rows="3" maxlength="255" placeholder="Opcional"></textarea>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-danger" id="motivoOk"><i class="bi bi-x-lg me-1"></i>Confirmar recusa</button></div>
    </div>
  </div>
</div>

<script>
// Aprovar/recusar sem recarregar a página
let formMotivo = null;
document.getElementById('motivoOk').addEventListener('click', () => {
  const f = formMotivo; formMotivo = null;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMotivo')).hide();
  if (f) { const fd = new FormData(f); fd.set('motivo', document.getElementById('motivoTxt').value); enviarAp(f, fd); }
});
document.querySelectorAll('form.js-ap').forEach(form => {
  form.addEventListener('submit', ev => {
    ev.preventDefault();
    if (form.dataset.motivo !== undefined) {
      formMotivo = form;
      document.getElementById('motivoLabel').textContent = form.dataset.motivo;
      document.getElementById('motivoTxt').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMotivo')).show();
      return;
    }
    enviarAp(form, new FormData(form));
  });
});
async function enviarAp(form, fd) {
    form.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
      const r = await fetch(location.pathname, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
      const j = await r.json();
      aviso(j.msg, j.ok);
      if (j.ok) {
        const alvo = form.hasAttribute('data-remove-grupo') ? form.closest('[data-grupo]') : form.closest('[data-linha]');
        if (alvo) { alvo.classList.add('saindo'); setTimeout(() => { const g = alvo.parentElement ? alvo.parentElement.closest('[data-grupo]') : null; alvo.remove(); if (g && !g.querySelector('[data-linha]')) g.remove(); }, 300); }
        Object.entries(j.cont || {}).forEach(([k, v]) => document.querySelectorAll('[data-cont="' + k + '"]').forEach(el => {
          el.textContent = (k === 'total' && !v) ? 'Tudo em dia' : v; el.classList.toggle('zero', !v);
          if (k === 'total') { el.classList.toggle('bg-danger', !!v); el.classList.toggle('bg-success', !v); }
        }));
      } else form.querySelectorAll('button').forEach(b => b.disabled = false);
    } catch (e) { aviso('Erro de conexão. Tente de novo.', false); form.querySelectorAll('button').forEach(b => b.disabled = false); }
}
function aviso(txt, ok) {
  const d = document.createElement('div');
  d.className = 'alert py-2 shadow ' + (ok ? 'alert-success' : 'alert-danger');
  d.textContent = txt; document.querySelector('.ap-toast').appendChild(d);
  setTimeout(() => d.remove(), 3500);
}
// Marcar/desmarcar todos de um lote
document.querySelectorAll('[data-marcar]').forEach(cb => cb.addEventListener('change', () => {
  document.querySelectorAll('input[name="ids[]"][form="' + cb.dataset.marcar + '"]').forEach(x => x.checked = cb.checked);
}));
</script>
<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
