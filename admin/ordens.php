<?php
// admin/ordens.php – v9: três blocos — Em andamento · Aguardando lançamento · Finalizadas (por cliente, expansível)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/veiculos.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Ordens de Serviço';
$sucesso = $erro = '';

$statusAtivos = ['aberta','em_andamento','aguardando_peca'];
$statusLabel  = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
// OS já lançada = existe débito na conta corrente ligado a ela
$sqlLancada = "SELECT ordem_servico_id FROM conta_corrente WHERE ordem_servico_id IS NOT NULL AND tipo='debito'";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'nova') {
        $clienteId  = (int)($_POST['cliente_id'] ?? 0);
        $veiculoId  = (int)($_POST['veiculo_id'] ?? 0) ?: null;
        $mecanicoId = (int)($_POST['mecanico_id'] ?? 0) ?: null;
        [$local, $rallyId] = osLocalDoPost($_POST['local_rally'] ?? 'oficina');
        $tituloOS   = trim($_POST['titulo'] ?? '');
        $status     = in_array($_POST['status'] ?? '', $statusAtivos, true) ? $_POST['status'] : 'aberta';
        $km         = kmParse($_POST['km'] ?? '');

        if (!$clienteId || !$tituloOS) { $erro = 'Selecione o cliente e informe o título.'; }
        elseif (!$veiculoId) { $erro = 'Selecione o veículo. Se o cliente não tiver carro cadastrado, cadastre em Clientes → editar.'; }
        else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM veiculos WHERE id=? AND cliente_id=?"); $chk->execute([$veiculoId, $clienteId]);
            if (!$chk->fetchColumn()) { $erro = 'O veículo escolhido não é deste cliente.'; }
        }
        if (!$erro) {
            // Próximo número sequencial por cliente
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(numero_cliente),0)+1 FROM ordens_servico WHERE cliente_id=?");
            $stmt->execute([$clienteId]); $seq = (int)$stmt->fetchColumn();
            $numOS = $clienteId . str_pad($seq, 2, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO ordens_servico (cliente_id,veiculo_id,mecanico_id,rally_id,local,titulo,status,numero_cliente,numero_os,valor_total) VALUES (?,?,?,?,?,?,?,?,?,0)")
                ->execute([$clienteId, $veiculoId, $mecanicoId, $rallyId, $local, $tituloOS, $status, $seq, $numOS]);
            $novaId = (int)$pdo->lastInsertId();

            $ord = 0;
            foreach (array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['afazeres'] ?? ''))) as $af) { $ord += 10;
                $pdo->prepare("INSERT INTO os_afazeres (ordem_servico_id,descricao,ordem,criado_por) VALUES (?,?,?,?)")->execute([$novaId, mb_substr($af, 0, 255), $ord, $u['id']]); }
            $descs = $_POST['item_desc'] ?? []; $valores = $_POST['item_valor'] ?? [];
            foreach ($descs as $i => $desc) {
                $desc = trim($desc); $val = (float)str_replace(['.', ','], ['', '.'], $valores[$i] ?? '0');
                if ($desc) $pdo->prepare("INSERT INTO os_itens (ordem_servico_id,descricao,valor,origem) VALUES (?,?,?,'admin')")->execute([$novaId, $desc, $val > 0 ? $val : null]);
            }
            $sv = $pdo->prepare("SELECT COALESCE(SUM(valor*quantidade),0) FROM os_itens WHERE ordem_servico_id=? AND valor IS NOT NULL"); $sv->execute([$novaId]);
            $pdo->prepare("UPDATE ordens_servico SET valor_total=? WHERE id=?")->execute([$sv->fetchColumn(), $novaId]);

            $avisoKm = '';
            if ($km !== null) {
                $r = veiculoRegistrarKm($pdo, $veiculoId, $km, $novaId, $u['id'], 'os', !empty($_POST['confirmar_menor']));
                if (!$r['ok']) $avisoKm = ' <strong>Km não registrada:</strong> ' . htmlspecialchars($r['aviso']) . ' Corrija dentro da OS.';
            }
            $_SESSION['flash_ordens'] = ['ok', "OS $numOS criada! <a href='editar_os.php?id={$novaId}' class='alert-link'>Abrir para editar →</a>" . $avisoKm];
            header('Location: ordens.php'); exit;
        }
    }
    elseif ($acao === 'lancar_os') {
        $o = $pdo->prepare("SELECT * FROM ordens_servico WHERE id=? AND status='concluida' AND valor_total>0 AND id NOT IN ($sqlLancada)");
        $o->execute([(int)($_POST['os_id'] ?? 0)]); $os = $o->fetch();
        if ($os) {
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,ordem_servico_id,admin_id) VALUES (?,?,?,'debito',?,?)")
                ->execute([$os['cliente_id'], 'OS ' . ($os['numero_os'] ?? $os['numero_cliente']) . ' – ' . $os['titulo'], $os['valor_total'], $os['id'], $u['id']]);
            $_SESSION['flash_ordens'] = ['ok', 'OS ' . htmlspecialchars($os['numero_os']) . ' lançada na conta corrente e movida para Finalizadas.'];
        } else $_SESSION['flash_ordens'] = ['erro', 'Esta OS já foi lançada ou não está concluída.'];
        header('Location: ordens.php#aguardando'); exit;
    }
}
if (!empty($_SESSION['flash_ordens'])) { [$t, $m] = $_SESSION['flash_ordens']; unset($_SESSION['flash_ordens']); $t === 'ok' ? $sucesso = $m : $erro = $m; }

$clientes  = $pdo->query("SELECT id,nome FROM usuarios WHERE tipo='cliente' AND ativo=1 ORDER BY nome")->fetchAll();
$mecanicos = $pdo->query("SELECT id,nome FROM usuarios WHERE tipo='mecanico' AND ativo=1 ORDER BY nome")->fetchAll();
$rallys    = $pdo->query("SELECT id,nome FROM rallys WHERE ativo=1 ORDER BY data_inicio DESC")->fetchAll();

// Pré-preenchimento vindo da página do veículo
$pre = null;
if (!empty($_GET['nova_veiculo'])) {
    $p = $pdo->prepare("SELECT id, cliente_id, km_atual FROM veiculos WHERE id=?"); $p->execute([(int)$_GET['nova_veiculo']]); $pre = $p->fetch() ?: null;
}
$abrirForm = $erro && ($_POST['acao'] ?? '') === 'nova' || $pre;
$postCliente = (int)($_POST['cliente_id'] ?? ($pre['cliente_id'] ?? 0));
$postVeiculo = (int)($_POST['veiculo_id'] ?? ($pre['id'] ?? 0));

$selectBase = "SELECT os.*, uc.nome AS cliente_nome, uc.id AS cid, um.nome AS mecanico_nome, v.placa, v.marca, v.modelo, r.nome AS rally_nome,
           (SELECT COUNT(*) FROM fotos f WHERE f.ordem_servico_id=os.id AND f.aprovada=0) AS fotos_pend,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
           (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos,
           (SELECT MAX(cc.criado_em) FROM conta_corrente cc WHERE cc.ordem_servico_id=os.id AND cc.tipo='debito') AS lancada_em
    FROM ordens_servico os
    JOIN usuarios uc ON uc.id=os.cliente_id
    LEFT JOIN usuarios um ON um.id=os.mecanico_id
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN rallys r ON r.id=os.rally_id";

// 1) Em andamento
$ph = implode(',', array_fill(0, count($statusAtivos), '?'));
$s = $pdo->prepare("$selectBase WHERE os.status IN ($ph) ORDER BY uc.nome ASC, os.numero_cliente DESC");
$s->execute($statusAtivos); $ativas = $s->fetchAll();
$porCliente = [];
foreach ($ativas as $os) { $porCliente[$os['cid']]['nome'] = $os['cliente_nome']; $porCliente[$os['cid']]['os'][] = $os; }

// 2) Aguardando lançamento: concluída, com valor e ainda sem débito na CC
$aguardando = $pdo->query("$selectBase WHERE os.status='concluida' AND os.valor_total>0 AND os.id NOT IN ($sqlLancada) ORDER BY os.atualizado_em ASC")->fetchAll();

// 3) Finalizadas: concluída já lançada (ou sem valor) + canceladas — com filtros
$fCliente = (int)($_GET['f_cliente'] ?? 0);
$fPeriodo = $_GET['f_periodo'] ?? '90';
$periodos = ['30' => 'Últimos 30 dias', '90' => 'Últimos 90 dias', '365' => 'Últimos 12 meses', 'tudo' => 'Todas'];
if (!isset($periodos[$fPeriodo])) $fPeriodo = '90';
$where = "((os.status='concluida' AND (os.valor_total<=0 OR os.id IN ($sqlLancada))) OR os.status='cancelada')";
$par = [];
if ($fCliente) { $where .= " AND os.cliente_id=?"; $par[] = $fCliente; }
if ($fPeriodo !== 'tudo') { $where .= " AND COALESCE(os.atualizado_em, os.criado_em) >= DATE_SUB(CURDATE(), INTERVAL " . (int)$fPeriodo . " DAY)"; }
$s = $pdo->prepare("$selectBase WHERE $where ORDER BY uc.nome ASC, os.numero_cliente DESC"); $s->execute($par);
$finalizadas = [];
foreach ($s->fetchAll() as $os) {
    $g = &$finalizadas[$os['cid']];
    $g['nome'] = $os['cliente_nome']; $g['os'][] = $os;
    $g['total'] = ($g['total'] ?? 0) + ($os['status'] === 'concluida' ? (float)$os['valor_total'] : 0);
    unset($g);
}
$qtdFinalizadas = array_sum(array_map(fn($g) => count($g['os']), $finalizadas));

$localHtml = function (array $os): string {
    $rally = ($os['local'] ?? 'oficina') === 'rally';
    return '<span class="small text-nowrap">' . ($rally ? '<i class="bi bi-flag text-danger me-1"></i>' : '<i class="bi bi-wrench text-secondary me-1"></i>') . htmlspecialchars(osLocalLabel($os)) . '</span>';
};
$veicHtml = fn(array $os) => htmlspecialchars(trim($os['marca'] . ' ' . $os['modelo'])) . ($os['placa'] ? ' <code>' . htmlspecialchars($os['placa']) . '</code>' : '');

require_once '../includes/header.php';
?>
<style>
  .secao-os{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6c757d;margin:28px 0 10px;display:flex;align-items:center;gap:8px}
  .secao-os .badge{font-size:.72rem}
  .grupo-fin{cursor:pointer;user-select:none}
  .grupo-fin .chev{transition:transform .2s}
  .grupo-fin[aria-expanded="true"] .chev{transform:rotate(90deg)}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-clipboard-list me-2 text-danger"></i>Ordens de Serviço</h5>
  <div class="d-flex gap-2">
    <a href="#em-andamento" class="btn btn-light btn-sm border">Em andamento <span class="badge bg-primary"><?= count($ativas) ?></span></a>
    <a href="#aguardando" class="btn btn-light btn-sm border">Aguard. lançamento <span class="badge bg-warning text-dark"><?= count($aguardando) ?></span></a>
    <a href="#finalizadas" class="btn btn-light btn-sm border">Finalizadas</a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNova"><i class="bi bi-clipboard-plus me-1"></i>Nova OS</button>
  </div>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= $sucesso ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2 mb-3"><?= $erro === strip_tags($erro) ? htmlspecialchars($erro) : $erro ?></div><?php endif; ?>

<!-- Form Nova OS -->
<div class="collapse mb-4 <?= $abrirForm ? 'show' : '' ?>" id="formNova">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Nova Ordem de Serviço</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="nova">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label">Cliente *</label>
            <select name="cliente_id" class="form-select" required onchange="carregarVeiculos(this.value,'veiculoNova')">
              <option value="">— Selecione —</option>
              <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>" <?= $postCliente == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label">Veículo *</label>
            <select name="veiculo_id" class="form-select" id="veiculoNova" required onchange="mostrarKm(this)"><option value="">— Selecione o cliente —</option></select>
            <div class="form-text" id="kmAtualInfo"></div></div>
          <div class="col-md-4"><label class="form-label">Km do painel na entrada</label>
            <div class="input-group"><input type="text" name="km" class="form-control" inputmode="numeric" placeholder="ex.: 1.400" value="<?= htmlspecialchars($_POST['km'] ?? '') ?>"><span class="input-group-text">km</span></div>
            <div class="form-text">Pode deixar em branco: o mecânico informa depois.</div></div>
          <div class="col-md-5"><label class="form-label">Título da OS *</label>
            <input type="text" name="titulo" class="form-control" required value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="form-label">Local/Rally</label><?= osSelectLocal($rallys) ?></div>
          <div class="col-md-2"><label class="form-label">Mecânico</label>
            <select name="mecanico_id" class="form-select"><option value="">— Sem —</option>
              <?php foreach ($mecanicos as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nome']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach ($statusAtivos as $k): ?><option value="<?= $k ?>"><?= $statusLabel[$k] ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <hr class="my-2">
        <div class="mb-3">
          <label class="fw-semibold small mb-1"><i class="bi bi-ui-checks me-1 text-success"></i>Afazeres do mecânico <span class="text-muted fw-normal">(um por linha · sem valor · aparece para mecânico e cliente)</span></label>
          <textarea name="afazeres" rows="3" class="form-control form-control-sm" placeholder="Revisar suspensão&#10;Trocar óleo e filtro&#10;Verificar folga dos rolamentos"></textarea>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <label class="fw-semibold mb-0 small"><i class="bi bi-receipt me-1 text-primary"></i>Itens com valor (peças / serviços)</label>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="addItem()"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
        </div>
        <div id="listaItens">
          <div class="row g-2 mb-2 item-linha">
            <div class="col-md-8"><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descrição..."></div>
            <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">R$</span><input type="text" name="item_valor[]" class="form-control" placeholder="0,00"></div></div>
            <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.item-linha').remove()"><i class="bi bi-trash3"></i></button></div>
          </div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Criar OS</button></div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 1. EM ANDAMENTO ═══ -->
<div class="secao-os" id="em-andamento"><i class="bi bi-gear-wide-connected text-primary"></i>Em andamento <span class="badge bg-primary"><?= count($ativas) ?></span></div>
<?php if ($porCliente): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Nº OS</th><th>Título</th><th>Veículo</th><th>Local/Rally</th><th>Km</th><th>Mecânico</th><th class="text-center">Alertas</th><th class="text-end">Valor</th><th>Status</th><th>Data</th></tr></thead>
      <tbody>
      <?php foreach ($porCliente as $cid => $grupo): ?>
        <tr class="table-secondary">
          <td colspan="10" class="py-2 px-3">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-person-fill text-primary"></i><strong><?= htmlspecialchars($grupo['nome']) ?></strong>
              <span class="badge bg-secondary"><?= count($grupo['os']) ?> OS</span>
              <a href="<?= BASE_URL ?>/admin/lancamento.php?cliente=<?= $cid ?>" class="btn btn-outline-warning btn-sm py-0 ms-auto"><i class="bi bi-cash me-1"></i>CC</a>
              <a href="<?= BASE_URL ?>/admin/clientes.php?editar=<?= $cid ?>" class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-pencil me-1"></i>Editar</a>
            </div>
          </td>
        </tr>
        <?php foreach ($grupo['os'] as $os): ?>
        <tr class="tr-link" onclick="location.href='<?= BASE_URL ?>/admin/editar_os.php?id=<?= $os['id'] ?>'">
          <td><span class="badge bg-dark"><?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span></td>
          <td class="fw-semibold"><?= htmlspecialchars($os['titulo']) ?>
            <?php if ($os['af_total']): $pc = round($os['af_feitos'] / $os['af_total'] * 100); ?><div class="d-flex align-items-center gap-2 mt-1" style="max-width:180px"><div class="progresso-os flex-fill" style="height:5px"><div style="width:<?= $pc ?>%"></div></div><span class="text-muted fw-normal" style="font-size:.7rem"><?= $os['af_feitos'] ?>/<?= $os['af_total'] ?></span></div><?php endif; ?></td>
          <td class="small text-muted"><?= $veicHtml($os) ?></td>
          <td><?= $localHtml($os) ?></td>
          <td class="small text-nowrap"><?= $os['km_entrada'] !== null ? kmFmt($os['km_entrada']) : '<span class="text-warning" title="Km de entrada não informada"><i class="bi bi-speedometer2"></i> —</span>' ?></td>
          <td class="small text-muted"><?= htmlspecialchars($os['mecanico_nome'] ?? '—') ?></td>
          <td class="text-center"><?= $os['fotos_pend'] > 0 ? '<span class="badge bg-warning text-dark"><i class="bi bi-camera me-1"></i>' . $os['fotos_pend'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end fw-semibold small">R$ <?= number_format($os['valor_total'], 2, ',', '.') ?></td>
          <td><span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?></span></td>
          <td class="small text-muted"><?= date('d/m/Y', strtotime($os['criado_em'])) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-4 text-muted"><i class="bi bi-clipboard2-check d-block fs-2 mb-1 text-success"></i>Nenhuma OS em andamento.</div>
<?php endif; ?>

<!-- ═══ 2. AGUARDANDO LANÇAMENTO ═══ -->
<div class="secao-os" id="aguardando"><i class="bi bi-hourglass-split text-warning"></i>Aguardando lançamento na conta corrente <span class="badge bg-warning text-dark"><?= count($aguardando) ?></span></div>
<?php if ($aguardando): ?>
<div class="card border-0 shadow-sm border-start border-warning border-3">
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Nº OS</th><th>Cliente</th><th>Título</th><th>Veículo</th><th>Local/Rally</th><th>Concluída em</th><th class="text-end">Valor</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($aguardando as $os): ?>
      <tr>
        <td><a href="editar_os.php?id=<?= $os['id'] ?>" class="badge bg-dark text-decoration-none"><?= htmlspecialchars($os['numero_os']) ?></a></td>
        <td class="fw-semibold"><?= htmlspecialchars($os['cliente_nome']) ?></td>
        <td><a href="editar_os.php?id=<?= $os['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($os['titulo']) ?></a></td>
        <td class="small text-muted"><?= $veicHtml($os) ?></td>
        <td><?= $localHtml($os) ?></td>
        <td class="small text-muted"><?= $os['atualizado_em'] ? date('d/m/Y', strtotime($os['atualizado_em'])) : '—' ?></td>
        <td class="text-end fw-bold">R$ <?= number_format($os['valor_total'], 2, ',', '.') ?></td>
        <td class="text-end">
          <form method="post" class="m-0"><input type="hidden" name="acao" value="lancar_os"><input type="hidden" name="os_id" value="<?= $os['id'] ?>">
            <button class="btn btn-sm btn-success text-nowrap" data-confirm="Lançar R$ <?= number_format($os['valor_total'], 2, ',', '.') ?> como débito na conta de <?= htmlspecialchars($os['cliente_nome'], ENT_QUOTES) ?>?"><i class="bi bi-arrow-down-circle me-1"></i>Lançar na CC</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-3 text-muted small">Nenhuma OS concluída esperando lançamento.</div>
<?php endif; ?>

<!-- ═══ 3. FINALIZADAS ═══ -->
<div class="secao-os" id="finalizadas"><i class="bi bi-archive text-success"></i>Finalizadas <span class="badge bg-success"><?= $qtdFinalizadas ?></span></div>
<form method="get" class="d-flex flex-wrap gap-2 mb-2" action="ordens.php#finalizadas">
  <select name="f_cliente" class="form-select form-select-sm" style="max-width:240px" onchange="this.form.submit()">
    <option value="0">Todos os clientes</option>
    <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>" <?= $fCliente == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
  </select>
  <select name="f_periodo" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
    <?php foreach ($periodos as $k => $l): ?><option value="<?= $k ?>" <?= $fPeriodo === (string)$k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <?php if ($finalizadas): ?>
  <button type="button" class="btn btn-sm btn-light border" onclick="document.querySelectorAll('.collapse-fin').forEach(e=>bootstrap.Collapse.getOrCreateInstance(e,{toggle:false}).show())">Expandir todos</button>
  <button type="button" class="btn btn-sm btn-light border" onclick="document.querySelectorAll('.collapse-fin').forEach(e=>bootstrap.Collapse.getOrCreateInstance(e,{toggle:false}).hide())">Recolher todos</button>
  <?php endif; ?>
</form>
<?php if ($finalizadas): ?>
<div class="card border-0 shadow-sm">
  <div class="list-group list-group-flush">
  <?php foreach ($finalizadas as $cid => $g): ?>
    <div class="list-group-item grupo-fin d-flex align-items-center gap-2 py-2" data-bs-toggle="collapse" data-bs-target="#fin<?= $cid ?>" aria-expanded="<?= $fCliente ? 'true' : 'false' ?>">
      <i class="bi bi-chevron-right chev text-muted"></i>
      <i class="bi bi-person-fill text-success"></i><strong><?= htmlspecialchars($g['nome']) ?></strong>
      <span class="badge bg-secondary"><?= count($g['os']) ?> OS</span>
      <span class="ms-auto small fw-semibold text-success">R$ <?= number_format($g['total'], 2, ',', '.') ?></span>
    </div>
    <div class="collapse collapse-fin <?= $fCliente ? 'show' : '' ?>" id="fin<?= $cid ?>">
      <div class="table-responsive bg-light">
        <table class="table table-sm table-hover mb-0 align-middle">
          <thead><tr><th class="ps-4">Nº OS</th><th>Título</th><th>Veículo</th><th>Local/Rally</th><th>Km</th><th>Situação</th><th class="text-end">Valor</th><th>Abertura</th></tr></thead>
          <tbody>
          <?php foreach ($g['os'] as $os): ?>
          <tr class="tr-link" onclick="location.href='<?= BASE_URL ?>/admin/editar_os.php?id=<?= $os['id'] ?>'">
            <td class="ps-4"><span class="badge bg-dark"><?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span></td>
            <td class="fw-semibold small"><?= htmlspecialchars($os['titulo']) ?></td>
            <td class="small text-muted"><?= $veicHtml($os) ?></td>
            <td><?= $localHtml($os) ?></td>
            <td class="small text-nowrap"><?= kmFmt($os['km_entrada']) ?></td>
            <td class="small">
              <?php if ($os['status'] === 'cancelada'): ?><span class="badge badge-cancelada">Cancelada</span>
              <?php elseif ($os['lancada_em']): ?><span class="badge bg-success">Lançada <?= date('d/m/Y', strtotime($os['lancada_em'])) ?></span>
              <?php else: ?><span class="badge bg-secondary">Concluída · sem valor</span><?php endif; ?>
            </td>
            <td class="text-end small fw-semibold">R$ <?= number_format($os['valor_total'], 2, ',', '.') ?></td>
            <td class="small text-muted"><?= date('d/m/Y', strtotime($os['criado_em'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-3 text-muted small">Nenhuma OS finalizada neste filtro.</div>
<?php endif; ?>

<script>
function addItem(){
    const l=document.getElementById('listaItens');
    const d=document.createElement('div');d.className='row g-2 mb-2 item-linha';
    d.innerHTML='<div class="col-md-8"><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descrição..."></div><div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">R$</span><input type="text" name="item_valor[]" class="form-control" placeholder="0,00"></div></div><div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest(\'.item-linha\').remove()"><i class="bi bi-trash3"></i></button></div>';
    l.appendChild(d);
}
function fmtKm(n){return n===null||n===''?null:Number(n).toLocaleString('pt-BR')+' km';}
function mostrarKm(sel){
    const o=sel.options[sel.selectedIndex], info=document.getElementById('kmAtualInfo');
    const km=o?o.dataset.km:null;
    info.innerHTML=o&&o.value?(km?'Km atual registrada: <strong>'+fmtKm(km)+'</strong>':'Carro sem km registrada ainda.'):'';
}
function carregarVeiculos(cid,sel,selecionar){
    const s=document.getElementById(sel);s.innerHTML='<option>Carregando...</option>';
    if(!cid){s.innerHTML='<option value="">— Selecione o cliente —</option>';mostrarKm(s);return;}
    fetch('<?= BASE_URL ?>/admin/ajax_veiculos.php?cliente_id='+cid).then(r=>r.json()).then(d=>{
        s.innerHTML=d.length?'<option value="">— Selecione —</option>':'<option value="">Cliente sem veículo cadastrado</option>';
        d.forEach(v=>{const o=document.createElement('option');o.value=v.id;o.textContent=[v.marca,v.modelo,v.placa].filter(Boolean).join(' ');o.dataset.km=v.km_atual??'';if(selecionar&&selecionar==v.id)o.selected=true;s.appendChild(o);});
        if(d.length===1&&!selecionar)s.selectedIndex=1;
        mostrarKm(s);
    });
}
<?php if ($postCliente): ?>carregarVeiculos(<?= $postCliente ?>,'veiculoNova',<?= $postVeiculo ?: 'null' ?>);<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
