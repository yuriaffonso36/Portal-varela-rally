<?php
// admin/index.php – Dashboard Admin com lista de clientes e saldos
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/aprovacoes.php';
require_once '../includes/os_helpers.php';
require_once '../includes/corridas.php';
requireRole('admin');
$u = usuarioLogado();
$area   = 'admin';
$titulo = 'Dashboard';

// ── Ações rápidas da central de pendências ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $msg = '';
    if ($acao === 'aprovar_foto') {
        $pdo->prepare("UPDATE fotos SET aprovada=1 WHERE id=?")->execute([(int)$_POST['foto_id']]); $msg = 'Foto aprovada.';
    } elseif ($acao === 'rejeitar_foto') {
        $f = $pdo->prepare("SELECT ordem_servico_id FROM fotos WHERE id=? AND aprovada=0"); $f->execute([(int)$_POST['foto_id']]);
        if ($oid = $f->fetchColumn()) { osApagarFoto($pdo, (int)$_POST['foto_id'], (int)$oid); $msg = 'Foto recusada e apagada.'; }
    } elseif ($acao === 'aprovar_fotos_os') {
        $n = $pdo->prepare("UPDATE fotos SET aprovada=1 WHERE ordem_servico_id=? AND aprovada=0"); $n->execute([(int)$_POST['os_id']]); $msg = $n->rowCount().' foto(s) aprovada(s).';
    } elseif ($acao === 'confirmar_comprovante') {
        $c = $pdo->prepare("SELECT * FROM comprovantes WHERE id=? AND status='pendente'"); $c->execute([(int)$_POST['comp_id']]); $comp = $c->fetch();
        if ($comp) {
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_id,comprovante_arquivo) VALUES (?,?,?,'credito',?,?,?)")
                ->execute([$comp['cliente_id'], 'Pagamento via comprovante'.($comp['descricao'] ? ' – '.$comp['descricao'] : ''), $comp['valor_declarado'], $u['id'], $comp['id'], $comp['arquivo']]);
            $pdo->prepare("UPDATE comprovantes SET status='confirmado',admin_id=?,confirmado_em=NOW(),cc_id=? WHERE id=?")->execute([$u['id'], $pdo->lastInsertId(), $comp['id']]);
            $msg = 'Comprovante confirmado e creditado.';
        }
    } elseif ($acao === 'rejeitar_comprovante') {
        $c = $pdo->prepare("SELECT * FROM comprovantes WHERE id=? AND status='pendente'"); $c->execute([(int)$_POST['comp_id']]); $comp = $c->fetch();
        if ($comp) { fotoApagar('comprovantes/'.$comp['arquivo']); $pdo->prepare("DELETE FROM comprovantes WHERE id=?")->execute([$comp['id']]); $msg = 'Comprovante rejeitado.'; }
    } elseif ($acao === 'lancar_os') {
        $o = $pdo->prepare("SELECT * FROM ordens_servico WHERE id=? AND status='concluida' AND valor_total>0 AND id NOT IN (SELECT ordem_servico_id FROM conta_corrente WHERE ordem_servico_id IS NOT NULL AND tipo='debito')");
        $o->execute([(int)$_POST['os_id']]); $os = $o->fetch();
        if ($os) {
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,ordem_servico_id,admin_id) VALUES (?,?,?,'debito',?,?)")
                ->execute([$os['cliente_id'], 'OS '.($os['numero_os'] ?? $os['numero_cliente']).' – '.$os['titulo'], $os['valor_total'], $os['id'], $u['id']]);
            $msg = 'OS lançada na conta corrente.';
        }
    }
    $_SESSION['flash_dash'] = $msg; header('Location: index.php#pendencias'); exit;
}
$flash = $_SESSION['flash_dash'] ?? ''; unset($_SESSION['flash_dash']);

// ── Pendências ──
$fotosPend = $pdo->query("SELECT f.*, os.numero_os, os.titulo AS os_titulo, uc.nome AS cliente_nome, ue.nome AS enviado_nome, a.descricao AS afazer_desc,
        (SELECT GROUP_CONCAT(oi.descricao SEPARATOR ', ') FROM os_item_fotos l JOIN os_itens oi ON oi.id=l.item_id WHERE l.foto_id=f.id) AS itens_desc
    FROM fotos f JOIN ordens_servico os ON os.id=f.ordem_servico_id JOIN usuarios uc ON uc.id=os.cliente_id JOIN usuarios ue ON ue.id=f.enviado_por
    LEFT JOIN os_afazeres a ON a.id=f.afazer_id WHERE f.aprovada=0 ORDER BY f.criado_em")->fetchAll();
$fotosPorOS = []; foreach ($fotosPend as $f) { $fotosPorOS[$f['ordem_servico_id']]['os'] = $f; $fotosPorOS[$f['ordem_servico_id']]['fotos'][] = $f; }
$compPend = $pdo->query("SELECT c.*, u.nome AS cliente_nome FROM comprovantes c JOIN usuarios u ON u.id=c.cliente_id WHERE c.status='pendente' ORDER BY c.criado_em")->fetchAll();
$navPend = $pdo->query("SELECT n.*, u.nome AS navegador_nome, p.nome AS piloto_nome, r.nome AS rally_nome FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id JOIN usuarios p ON p.id=n.piloto_id LEFT JOIN rallys r ON r.id=n.rally_id WHERE n.status='encerrada' AND n.ok_admin=0 ORDER BY n.atualizado_em")->fetchAll();
$itensSemValor = $pdo->query("SELECT oi.ordem_servico_id, os.numero_os, os.titulo, uc.nome AS cliente_nome, COUNT(*) AS qtd, GROUP_CONCAT(oi.descricao SEPARATOR ' · ') AS descs
    FROM os_itens oi JOIN ordens_servico os ON os.id=oi.ordem_servico_id JOIN usuarios uc ON uc.id=os.cliente_id
    WHERE oi.origem='mecanico' AND oi.valor IS NULL AND os.status NOT IN ('concluida','cancelada') GROUP BY oi.ordem_servico_id, os.numero_os, os.titulo, uc.nome")->fetchAll();
$osCobrar = $pdo->query("SELECT os.*, uc.nome AS cliente_nome FROM ordens_servico os JOIN usuarios uc ON uc.id=os.cliente_id
    WHERE os.status='concluida' AND os.valor_total>0 AND os.id NOT IN (SELECT ordem_servico_id FROM conta_corrente WHERE ordem_servico_id IS NOT NULL AND tipo='debito') ORDER BY os.atualizado_em")->fetchAll();
$estoqueBaixo = $pdo->query("SELECT id,codigo,nome,quantidade,estoque_minimo FROM estoque WHERE ativo=1 AND quantidade<=estoque_minimo AND estoque_minimo>0 ORDER BY quantidade")->fetchAll();
$corridasProx = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM rally_participantes p WHERE p.rally_id=r.id) AS n_carros FROM rallys r WHERE r.ativo=1 AND r.data_inicio BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 21 DAY) ORDER BY r.data_inicio")->fetchAll();
// Alerta de armazenamento (usa o tamanho registrado das fotos; detalhado em Armazenamento)
$cotaMb = (int)configGet($pdo, 'cota_armazenamento_mb', 20480);
$usoFotos = (float)$pdo->query("SELECT COALESCE(SUM(tamanho),0) FROM fotos")->fetchColumn();
$pctDisco = $cotaMb ? $usoFotos / ($cotaMb * 1048576) * 100 : 0;

$apCont = aprovContagens($pdo);
$tiles = [
    ['aprovacoes.php?aba=fotos',        'Fotos para aprovar',        $apCont['fotos'],        'bi-images',        '#0d6efd'],
    ['aprovacoes.php?aba=comprovantes', 'Comprovantes de pagamento', $apCont['comprovantes'], 'bi-receipt',       '#198754'],
    ['aprovacoes.php?aba=pedidos',      'Pedidos e itens',           $apCont['pedidos'],      'bi-chat-square-text','#6f42c1'],
    ['aprovacoes.php?aba=navegador',    'OS de navegador',           $apCont['navegador'],    'bi-compass',       '#fd7e14'],
    ['aprovacoes.php?aba=reembolsos',   'Reembolsos',                $apCont['reembolsos'],   'bi-cash-coin',     '#dc3545'],
    ['#p-cobrar',                       'OS concluídas a cobrar',    count($osCobrar),        'bi-cash-stack',    '#212529'],
    ['#p-estoque',                      'Estoque abaixo do mínimo',  count($estoqueBaixo),    'bi-box-seam',      '#6c757d'],
];
$totalPend = array_sum(array_column($tiles, 2)) - count($estoqueBaixo);

// Clientes com saldo
$clientes = $pdo->query("
    SELECT u.id, u.nome, u.telefone,
           COALESCE(SUM(CASE WHEN cc.tipo='credito' THEN cc.valor ELSE -cc.valor END),0) AS saldo,
           (SELECT COUNT(*) FROM ordens_servico os WHERE os.cliente_id=u.id AND os.status NOT IN ('concluida','cancelada')) AS os_abertas,
           (SELECT COUNT(*) FROM comprovantes cp WHERE cp.cliente_id=u.id AND cp.status='pendente') AS comprovantes_pendentes
    FROM usuarios u
    LEFT JOIN conta_corrente cc ON cc.cliente_id=u.id
    WHERE u.tipo='cliente' AND u.ativo=1
    GROUP BY u.id ORDER BY u.nome ASC")->fetchAll();

// Totalizadores
$totalDevedores = 0;
$totalCredores  = 0;
foreach ($clientes as $c) {
    if ($c['saldo'] < 0) $totalDevedores += abs($c['saldo']);
    else                  $totalCredores  += $c['saldo'];
}
$saldoLiquido = $totalDevedores - $totalCredores;

// Comprovantes pendentes total
$totalPendentes = $pdo->query("SELECT COUNT(*) FROM comprovantes WHERE status='pendente'")->fetchColumn();
// OS abertas total
$totalOSAbertas = $pdo->query("SELECT COUNT(*) FROM ordens_servico WHERE status NOT IN ('concluida','cancelada')")->fetchColumn();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-speedometer2 me-2 text-danger"></i>Dashboard</h5>
</div>

<?php if ($flash): ?><div class="alert alert-success py-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<!-- ═══ CENTRAL DE PENDÊNCIAS ═══ -->
<div id="pendencias" class="mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="fw-bold mb-0"><i class="bi bi-inbox me-2 text-danger"></i>Aguardando você</h6>
    <span class="badge <?= $totalPend ? 'bg-danger' : 'bg-success' ?>"><?= $totalPend ? $totalPend.' pendência(s)' : 'Tudo em dia' ?></span>
  </div>
  <div class="row g-3 mb-3">
    <?php foreach ($tiles as [$id, $l, $n, $ic, $c]): ?>
    <div class="col-6 col-md-4 col-xl">
      <a href="<?= $id ?>" class="pend-tile h-100 <?= $n ? '' : 'zero' ?>">
        <div class="ic" style="background:<?= $c ?>1a;color:<?= $c ?>"><i class="bi <?= $ic ?>"></i></div>
        <div><div class="n" style="<?= $n ? 'color:'.$c : '' ?>"><?= $n ?></div><div class="l"><?= $l ?></div></div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($pctDisco > 85): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-hdd me-2"></i>Fotos usando <?= number_format($pctDisco,0) ?>% do limite de armazenamento. <a href="armazenamento.php" class="alert-link">Ver armazenamento →</a></div>
  <?php endif; ?>

  <?php if (!$totalPend && !$estoqueBaixo): ?>
  <div class="card border-0 shadow-sm text-center py-4 text-success"><i class="bi bi-check2-circle fs-1 d-block mb-1"></i>Nenhuma pendência. Tudo em dia!</div>
  <?php endif; ?>

  <?php if ($apCont['total']): ?>
  <a href="aprovacoes.php" class="btn btn-danger mb-3"><i class="bi bi-inbox me-1"></i>Abrir a Central de Aprovações (<?= $apCont['total'] ?>)</a>
  <?php endif; ?>

  <div class="row g-3">
    <?php if ($osCobrar): ?>
    <div class="col-lg-6" id="p-cobrar"><div class="card border-0 shadow-sm h-100 pend-bloco">
      <div class="card-header bg-white"><span class="fw-semibold"><i class="bi bi-cash-stack me-2 text-danger"></i>OS concluídas ainda não lançadas na conta</span><span class="badge bg-danger"><?= count($osCobrar) ?></span></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($osCobrar as $o): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
          <a href="editar_os.php?id=<?= $o['id'] ?>" class="text-decoration-none text-dark"><span class="badge bg-dark me-1"><?= htmlspecialchars($o['numero_os'] ?? '') ?></span><?= htmlspecialchars($o['titulo']) ?> <span class="text-muted">· <?= htmlspecialchars($o['cliente_nome']) ?></span></a>
          <form method="post" class="m-0 text-nowrap"><input type="hidden" name="acao" value="lancar_os"><input type="hidden" name="os_id" value="<?= $o['id'] ?>">
            <span class="fw-bold me-2">R$ <?= number_format($o['valor_total'],2,',','.') ?></span><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Lançar R$ <?= number_format($o['valor_total'],2,',','.') ?> como débito para <?= htmlspecialchars($o['cliente_nome'],ENT_QUOTES) ?>?">Lançar</button></form>
        </li>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>

    <?php if ($estoqueBaixo): ?>
    <div class="col-lg-6" id="p-estoque"><div class="card border-0 shadow-sm h-100 pend-bloco">
      <div class="card-header bg-white"><span class="fw-semibold"><i class="bi bi-box-seam me-2 text-secondary"></i>Estoque abaixo do mínimo</span><a href="<?= BASE_URL ?>/estoque/index.php" class="small">Abrir estoque</a></div>
      <ul class="list-group list-group-flush small">
        <?php foreach (array_slice($estoqueBaixo, 0, 8) as $e): ?>
        <li class="list-group-item d-flex justify-content-between"><span><code class="me-1"><?= htmlspecialchars($e['codigo']) ?></code><?= htmlspecialchars($e['nome']) ?></span><span class="<?= $e['quantidade'] <= 0 ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>"><?= $e['quantidade'] ?> / mín. <?= $e['estoque_minimo'] ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>

    <?php if ($corridasProx): ?>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100 pend-bloco">
      <div class="card-header bg-white"><span class="fw-semibold"><i class="bi bi-flag-fill me-2 text-danger"></i>Corridas nos próximos 21 dias</span><a href="corridas.php" class="small">Ver todas</a></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($corridasProx as $r): $d = rallyDiasPara($r); ?>
        <a href="ver_corrida.php?id=<?= $r['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <span><strong><?= htmlspecialchars($r['nome']) ?></strong> <span class="text-muted">· <?= rallyPeriodo($r) ?> · <?= $r['n_carros'] ?> carro(s)</span></span>
          <span class="badge bg-danger"><?= $d === 0 ? 'hoje' : $d.' dia(s)' ?></span>
        </a>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>
  </div>
</div>

<h6 class="fw-bold mb-2 mt-2"><i class="bi bi-wallet2 me-2 text-danger"></i>Financeiro</h6>
<!-- Totalizadores -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card card-stat p-3 text-center border-0 shadow-sm">
      <div class="fs-3 fw-bold text-danger">R$ <?= number_format($totalDevedores,2,',','.') ?></div>
      <div class="text-muted small">Total a Receber</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-stat p-3 text-center border-0 shadow-sm">
      <div class="fs-3 fw-bold text-success">R$ <?= number_format($totalCredores,2,',','.') ?></div>
      <div class="text-muted small">Total em Crédito</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-stat p-3 text-center border-0 shadow-sm">
      <div class="fs-3 fw-bold <?= $saldoLiquido >= 0 ? 'text-success' : 'text-danger' ?>">
        R$ <?= number_format(abs($saldoLiquido),2,',','.') ?>
      </div>
      <div class="text-muted small">Saldo Líquido Oficina</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card card-stat p-3 text-center border-0 shadow-sm">
      <div class="fs-3 fw-bold text-warning"><?= $totalOSAbertas ?></div>
      <div class="text-muted small">OS em Aberto</div>
    </div>
  </div>
</div>

<!-- Lista de Clientes com Saldo -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center pt-3 pb-2">
    <h6 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-danger"></i>Clientes — Posição de Conta</h6>
    <a href="<?= BASE_URL ?>/admin/clientes.php" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-person-plus me-1"></i>Novo Cliente
    </a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Telefone</th>
          <th class="text-center">OS Abertas</th>
          <th class="text-end">Saldo</th>
          <th class="text-center">Situação</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clientes as $c): ?>
      <tr>
        <td class="fw-semibold">
          <?= htmlspecialchars($c['nome']) ?>
          <?php if ($c['comprovantes_pendentes'] > 0): ?>
          <span class="badge bg-warning text-dark ms-1" title="Comprovante pendente">
            <i class="bi bi-receipt"></i>
          </span>
          <?php endif; ?>
        </td>
        <td class="small text-muted"><?= htmlspecialchars($c['telefone'] ?? '—') ?></td>
        <td class="text-center">
          <?php if ($c['os_abertas'] > 0): ?>
          <a href="<?= BASE_URL ?>/admin/ordens.php?cliente=<?= $c['id'] ?>" class="badge bg-warning text-dark text-decoration-none">
            <?= $c['os_abertas'] ?> aberta(s)
          </a>
          <?php else: ?>
          <span class="text-muted small">—</span>
          <?php endif; ?>
        </td>
        <td class="text-end fw-bold <?= $c['saldo'] < 0 ? 'text-danger' : ($c['saldo'] > 0 ? 'text-success' : 'text-muted') ?>">
          <?php if ($c['saldo'] != 0): ?>
          <?= $c['saldo'] < 0 ? '-' : '+' ?> R$ <?= number_format(abs($c['saldo']),2,',','.') ?>
          <?php else: ?>
          R$ 0,00
          <?php endif; ?>
        </td>
        <td class="text-center">
          <?php if ($c['saldo'] < 0): ?>
          <span class="badge bg-danger">Devedor</span>
          <?php elseif ($c['saldo'] > 0): ?>
          <span class="badge bg-success">Credor</span>
          <?php else: ?>
          <span class="badge bg-secondary">Zerado</span>
          <?php endif; ?>
        </td>
        <td class="text-nowrap">
          <a href="<?= BASE_URL ?>/admin/lancamento.php?cliente=<?= $c['id'] ?>"
             class="btn btn-sm btn-outline-warning me-1" title="Conta Corrente">
            <i class="bi bi-cash"></i>
          </a>
          <a href="<?= BASE_URL ?>/admin/clientes.php?editar=<?= $c['id'] ?>"
             class="btn btn-sm btn-outline-primary" title="Editar">
            <i class="bi bi-pencil"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="3" class="text-end">Totais:</td>
          <td class="text-end">
            <span class="text-danger">-R$ <?= number_format($totalDevedores,2,',','.') ?></span>
            /
            <span class="text-success">+R$ <?= number_format($totalCredores,2,',','.') ?></span>
          </td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>

<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
