<?php
// admin/lancamento.php
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('admin');
$u = usuarioLogado();
$area   = 'admin';
$titulo = 'Conta Corrente';
$sucesso = $erro = '';
$filtroCliente = isset($_GET['cliente']) ? (int)$_GET['cliente'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $clienteId = (int)($_POST['cliente_id'] ?? 0);

    if ($acao === 'confirmar_comprovante') {
        $compId = (int)($_POST['comp_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM comprovantes WHERE id=? AND status='pendente'");
        $stmt->execute([$compId]); $comp = $stmt->fetch();
        if ($comp) {
            $desc = "Pagamento via comprovante" . ($comp['descricao'] ? ' – '.$comp['descricao'] : '');
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_id,comprovante_arquivo) VALUES (?,?,?,'credito',?,?,?)")
                ->execute([$comp['cliente_id'],$desc,$comp['valor_declarado'],$u['id'],$compId,$comp['arquivo']]);
            $ccId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE comprovantes SET status='confirmado',admin_id=?,confirmado_em=NOW(),cc_id=? WHERE id=?")->execute([$u['id'],$ccId,$compId]);
            $sucesso = 'Comprovante confirmado!'; $filtroCliente = $comp['cliente_id'];
        }
    } elseif ($acao === 'rejeitar_comprovante') {
        $compId = (int)($_POST['comp_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM comprovantes WHERE id=?"); $stmt->execute([$compId]); $comp = $stmt->fetch();
        if ($comp) { $arq=UPLOAD_DIR.'comprovantes/'.$comp['arquivo']; if(file_exists($arq))unlink($arq); $pdo->prepare("DELETE FROM comprovantes WHERE id=?")->execute([$compId]); $sucesso='Rejeitado e excluído.'; }
    } elseif ($acao === 'lancar_os') {
        $osId = (int)($_POST['os_id'] ?? 0);
        // v9: não lança a mesma OS duas vezes
        $stmt = $pdo->prepare("SELECT * FROM ordens_servico WHERE id=? AND cliente_id=? AND status='concluida' AND id NOT IN (SELECT ordem_servico_id FROM conta_corrente WHERE ordem_servico_id IS NOT NULL AND tipo='debito')"); $stmt->execute([$osId,$clienteId]); $os=$stmt->fetch();
        if ($os && $os['valor_total']>0) {
            $numOS = $os['numero_os'] ?? $os['numero_cliente'];
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,ordem_servico_id,admin_id) VALUES (?,?,?,'debito',?,?)")
                ->execute([$clienteId,"OS $numOS – ".$os['titulo'],$os['valor_total'],$osId,$u['id']]);
            $sucesso="OS $numOS lançada!"; $filtroCliente=$clienteId;
        } else { $erro='Esta OS já foi lançada ou não está concluída.'; $filtroCliente=$clienteId; }
    } elseif ($acao === 'lancar') {
        $desc  = trim($_POST['descricao'] ?? '');
        $valor = (float)str_replace(['.',',' ],['','.'], $_POST['valor'] ?? '0');
        $tipo  = $_POST['tipo'] ?? 'debito';
        if (!$clienteId || !$desc || $valor<=0) { $erro='Preencha todos os campos.'; }
        else {
            $compArquivo = null;
            if (!empty($_FILES['comprovante']['name']) && $_FILES['comprovante']['error']===UPLOAD_ERR_OK) {
                $ext=strtolower(pathinfo($_FILES['comprovante']['name'],PATHINFO_EXTENSION));
                if (in_array($ext,['jpg','jpeg','png','webp','pdf'])) {
                    $nomeArq='comp_adm_'.$clienteId.'_'.uniqid().'.'.$ext;
                    if(!is_dir(UPLOAD_DIR.'comprovantes/'))mkdir(UPLOAD_DIR.'comprovantes/',0755,true);
                    move_uploaded_file($_FILES['comprovante']['tmp_name'],UPLOAD_DIR.'comprovantes/'.$nomeArq);
                    $compArquivo=$nomeArq;
                }
            }
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_arquivo) VALUES (?,?,?,?,?,?)")
                ->execute([$clienteId,$desc,$valor,$tipo,$u['id'],$compArquivo]);
            $sucesso='Lançamento realizado!'; $filtroCliente=$clienteId;
        }
    } elseif ($acao === 'excluir') {
        $pdo->prepare("DELETE FROM conta_corrente WHERE id=?")->execute([(int)($_POST['exc_id']??0)]);
        $sucesso='Lançamento removido.';
    }
}

$clientes = $pdo->query("SELECT u.id,u.nome,COALESCE(SUM(CASE WHEN cc.tipo='credito' THEN cc.valor ELSE -cc.valor END),0) AS saldo,(SELECT COUNT(*) FROM comprovantes cp WHERE cp.cliente_id=u.id AND cp.status='pendente') AS comp_pendentes FROM usuarios u LEFT JOIN conta_corrente cc ON cc.cliente_id=u.id WHERE u.tipo='cliente' AND u.ativo=1 GROUP BY u.id ORDER BY u.nome")->fetchAll();
$whereComp = $filtroCliente ? "WHERE c.cliente_id=$filtroCliente AND c.status='pendente'" : "WHERE c.status='pendente'";
$compPendentes = $pdo->query("SELECT c.*,u.nome AS cliente_nome FROM comprovantes c JOIN usuarios u ON u.id=c.cliente_id $whereComp ORDER BY c.criado_em DESC")->fetchAll();
$extrato=$osFechadas=[];$saldo=0;
if ($filtroCliente) {
    $stmt=$pdo->prepare("SELECT cc.*,os.titulo AS os_titulo,os.numero_cliente AS os_num FROM conta_corrente cc LEFT JOIN ordens_servico os ON os.id=cc.ordem_servico_id WHERE cc.cliente_id=? ORDER BY cc.criado_em DESC");
    $stmt->execute([$filtroCliente]); $extrato=$stmt->fetchAll();
    foreach($extrato as $e) $saldo+=($e['tipo']==='credito')?$e['valor']:-$e['valor'];
    $stmt=$pdo->prepare("SELECT * FROM ordens_servico WHERE cliente_id=? AND status='concluida' AND valor_total>0 AND id NOT IN (SELECT ordem_servico_id FROM conta_corrente WHERE ordem_servico_id IS NOT NULL AND cliente_id=? AND tipo='debito') ORDER BY numero_cliente DESC");
    $stmt->execute([$filtroCliente,$filtroCliente]); $osFechadas=$stmt->fetchAll();
}
require_once '../includes/header.php';
?>
<h5 class="fw-bold mb-4"><i class="bi bi-cash-coin me-2 text-warning"></i>Conta Corrente</h5>

<?php if ($compPendentes && !$filtroCliente): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-receipt me-2 text-warning"></i>Comprovantes Pendentes <span class="badge bg-warning text-dark ms-1"><?= count($compPendentes) ?></span></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 small">
      <thead><tr><th>Cliente</th><th>Valor</th><th>Descrição</th><th>Data</th><th>Arquivo</th><th></th></tr></thead>
      <tbody>
      <?php foreach($compPendentes as $cp): ?>
      <tr class="table-warning">
        <td class="fw-semibold"><?= htmlspecialchars($cp['cliente_nome']) ?></td>
        <td class="fw-bold text-success">R$ <?= number_format($cp['valor_declarado'],2,',','.') ?></td>
        <td><?= htmlspecialchars($cp['descricao']??'—') ?></td>
        <td><?= date('d/m/Y H:i',strtotime($cp['criado_em'])) ?></td>
        <td><a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($cp['arquivo']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Ver</a></td>
        <td class="d-flex gap-1">
          <form method="post"><input type="hidden" name="acao" value="confirmar_comprovante"><input type="hidden" name="comp_id" value="<?= $cp['id'] ?>"><input type="hidden" name="cliente_id" value="<?= $cp['cliente_id'] ?>"><button class="btn btn-success btn-sm" data-confirm="Confirmar?"><i class="bi bi-check-lg"></i> Confirmar</button></form>
          <form method="post"><input type="hidden" name="acao" value="rejeitar_comprovante"><input type="hidden" name="comp_id" value="<?= $cp['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Rejeitar e excluir?"><i class="bi bi-x-lg"></i></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!$filtroCliente): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-people me-2"></i>Selecione um Cliente</div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Cliente</th><th class="text-end">Saldo</th><th class="text-center">Situação</th><th></th></tr></thead>
      <tbody>
      <?php foreach($clientes as $c): ?>
      <tr style="cursor:pointer" onclick="window.location='lancamento.php?cliente=<?= $c['id'] ?>'">
        <td class="fw-semibold"><?= htmlspecialchars($c['nome']) ?><?php if($c['comp_pendentes']>0): ?><span class="badge bg-warning text-dark ms-1"><i class="bi bi-receipt"></i> <?= $c['comp_pendentes'] ?></span><?php endif; ?></td>
        <td class="text-end fw-bold <?= $c['saldo']<0?'text-danger':($c['saldo']>0?'text-success':'text-muted') ?>">R$ <?= number_format(abs($c['saldo']),2,',','.') ?></td>
        <td class="text-center"><?php if($c['saldo']<0): ?><span class="badge bg-danger">Devedor</span><?php elseif($c['saldo']>0): ?><span class="badge bg-success">Credor</span><?php else: ?><span class="badge bg-secondary">Zerado</span><?php endif; ?></td>
        <td><i class="bi bi-chevron-right text-muted"></i></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro): ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if($compPendentes): ?>
<div class="alert alert-warning mb-3">
  <strong><i class="bi bi-receipt me-2"></i><?= count($compPendentes) ?> comprovante(s) pendente(s):</strong>
  <?php foreach($compPendentes as $cp): ?>
  <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
    <span>R$ <?= number_format($cp['valor_declarado'],2,',','.') ?><?= $cp['descricao']?' – '.htmlspecialchars($cp['descricao']):'' ?></span>
    <a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($cp['arquivo']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
    <form method="post" class="d-inline"><input type="hidden" name="acao" value="confirmar_comprovante"><input type="hidden" name="comp_id" value="<?= $cp['id'] ?>"><input type="hidden" name="cliente_id" value="<?= $filtroCliente ?>"><button class="btn btn-success btn-sm" data-confirm="Confirmar?"><i class="bi bi-check-lg"></i> Confirmar</button></form>
    <form method="post" class="d-inline"><input type="hidden" name="acao" value="rejeitar_comprovante"><input type="hidden" name="comp_id" value="<?= $cp['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Rejeitar e excluir?"><i class="bi bi-x-lg"></i></button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <a href="lancamento.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Todos os clientes</a>
    <h6 class="fw-bold mb-0 mt-1"><?php foreach($clientes as $c){if($c['id']==$filtroCliente){echo htmlspecialchars($c['nome']);break;}} ?></h6>
  </div>
  <div class="fs-5 fw-bold <?= $saldo>=0?'saldo-positivo':'saldo-negativo' ?>">Saldo: R$ <?= number_format(abs($saldo),2,',','.') ?><?= $saldo<0?' <small class="fs-6">(devedor)</small>':'' ?></div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <?php if($osFechadas): ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-success border-3">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-check2-circle me-1 text-success"></i>OS Prontas para Cobrar</div>
      <div class="card-body p-0">
        <?php foreach($osFechadas as $os): ?>
        <div class="p-3 border-bottom">
          <div class="d-flex justify-content-between mb-2"><div><span class="badge bg-dark">OS #<?= $os['numero_cliente'] ?></span><div class="small fw-semibold mt-1"><?= htmlspecialchars($os['titulo']) ?></div></div><span class="fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span></div>
          <form method="post"><input type="hidden" name="acao" value="lancar_os"><input type="hidden" name="cliente_id" value="<?= $filtroCliente ?>"><input type="hidden" name="os_id" value="<?= $os['id'] ?>"><button class="btn btn-success btn-sm w-100" data-confirm="Lançar OS #<?= $os['numero_cliente'] ?> como débito?"><i class="bi bi-arrow-down-circle me-1"></i>Lançar Débito</button></form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Lançamento Manual</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="lancar">
          <input type="hidden" name="cliente_id" value="<?= $filtroCliente ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Tipo *</label>
            <div class="d-flex gap-3">
              <div class="form-check"><input class="form-check-input" type="radio" name="tipo" value="debito" id="tipoD" checked><label class="form-check-label text-danger fw-semibold" for="tipoD">Débito</label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="tipo" value="credito" id="tipoC"><label class="form-check-label text-success fw-semibold" for="tipoC">Crédito</label></div>
            </div>
          </div>
          <div class="mb-3"><label class="form-label">Valor (R$) *</label><input type="text" name="valor" class="form-control" placeholder="0,00" required></div>
          <div class="mb-3"><label class="form-label">Descrição *</label><input type="text" name="descricao" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-paperclip me-1"></i>Comprovante <small class="text-muted">(opcional – JPG, PNG ou PDF)</small></label>
            <input type="file" name="comprovante" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-semibold"><i class="bi bi-plus-circle me-1"></i>Lançar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 pt-3 fw-bold">Extrato</div>
      <div class="card-body p-0">
        <?php if($extrato): ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0 small">
          <thead><tr><th>Data</th><th>Descrição</th><th class="text-end">Valor</th><th></th></tr></thead>
          <tbody>
          <?php foreach($extrato as $e): $isC=$e['tipo']==='credito'; ?>
          <tr>
            <td class="text-muted text-nowrap"><?= date('d/m/Y',strtotime($e['criado_em'])) ?></td>
            <td>
              <?= htmlspecialchars($e['descricao']) ?>
              <?php if($e['comprovante_arquivo']): ?>
              <a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($e['comprovante_arquivo']) ?>" target="_blank" class="ms-2 text-secondary" title="Ver comprovante"><i class="bi bi-eye-fill"></i></a>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold <?= $isC?'valor-credito':'valor-debito' ?>"><?= $isC?'+':'-' ?> R$ <?= number_format($e['valor'],2,',','.') ?></td>
            <td><form method="post" class="d-inline"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="exc_id" value="<?= $e['id'] ?>"><input type="hidden" name="cliente_id" value="<?= $filtroCliente ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover?"><i class="bi bi-trash3" style="font-size:.8rem"></i></button></form></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php else: ?><p class="text-muted text-center py-4">Nenhum lançamento ainda.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
