<?php
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('cliente');
$u=usuarioLogado();
$area='cliente';$titulo='Conta Corrente';
$sucesso=$erro='';

// Apaga comprovantes rejeitados automaticamente
$rej=$pdo->prepare("SELECT * FROM comprovantes WHERE cliente_id=? AND status='rejeitado'");
$rej->execute([$u['id']]);
foreach($rej->fetchAll() as $r){$arq=UPLOAD_DIR.'comprovantes/'.$r['arquivo'];if(file_exists($arq))unlink($arq);$pdo->prepare("DELETE FROM comprovantes WHERE id=?")->execute([$r['id']]);}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['acao']??'')==='enviar_comprovante'){
    $valor=(float)str_replace(['.',',' ],['','.'],$_POST['valor_declarado']??'0');
    $desc=trim($_POST['descricao']??'');
    $arq=$_FILES['comprovante']??null;
    if($valor<=0){$erro='Informe o valor pago.';}
    elseif(!$arq||empty($arq['name'])){$erro='Selecione o arquivo.';}
    else{
        $ext=strtolower(pathinfo($arq['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['jpg','jpeg','png','webp','pdf'])){$erro='Use JPG, PNG ou PDF.';}
        elseif($arq['error']!==UPLOAD_ERR_OK){$erro='Erro no upload.';}
        else{
            $nomeArq='comp_'.$u['id'].'_'.uniqid().'.'.$ext;
            if(!is_dir(UPLOAD_DIR.'comprovantes/'))mkdir(UPLOAD_DIR.'comprovantes/',0755,true);
            if(move_uploaded_file($arq['tmp_name'],UPLOAD_DIR.'comprovantes/'.$nomeArq)){
                $pdo->prepare("INSERT INTO comprovantes (cliente_id,valor_declarado,arquivo,tipo_arquivo,descricao,status) VALUES (?,?,?,?,?,'pendente')")->execute([$u['id'],$valor,$nomeArq,$ext,$desc]);
                $sucesso='Comprovante enviado! Aguarde a confirmação.';
            }else{$erro='Falha ao salvar o arquivo.';}
        }
    }
}

$stmt=$pdo->prepare("SELECT cc.*,os.titulo AS os_titulo,os.numero_cliente AS os_num FROM conta_corrente cc LEFT JOIN ordens_servico os ON os.id=cc.ordem_servico_id WHERE cc.cliente_id=? ORDER BY cc.criado_em DESC");
$stmt->execute([$u['id']]);$movimentos=$stmt->fetchAll();
$saldo=0;foreach($movimentos as $m)$saldo+=($m['tipo']==='credito')?$m['valor']:-$m['valor'];

$comps=$pdo->prepare("SELECT * FROM comprovantes WHERE cliente_id=? AND status='pendente' ORDER BY criado_em DESC");
$comps->execute([$u['id']]);$comps=$comps->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-wallet2 me-2 text-primary"></i>Conta Corrente</h5>
  <div class="card px-4 py-2 border-0 shadow-sm">
    <span class="small text-muted">Saldo atual</span>
    <span class="fs-4 fw-bold <?= $saldo>=0?'saldo-positivo':'saldo-negativo' ?>">R$ <?= number_format(abs($saldo),2,',','.') ?><?= $saldo<0?' <small class="fs-6">(devedor)</small>':'' ?></span>
  </div>
</div>
<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro): ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-bold border-bottom pt-3">Extrato</div>
      <div class="card-body p-0">
        <?php if($movimentos): ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0 small">
          <thead><tr><th>Data</th><th>Descrição</th><th class="text-end">Valor</th></tr></thead>
          <tbody>
          <?php foreach($movimentos as $m): $isC=$m['tipo']==='credito'; ?>
          <tr>
            <td class="text-muted text-nowrap"><?= date('d/m/Y',strtotime($m['criado_em'])) ?></td>
            <td>
              <?= htmlspecialchars($m['descricao']) ?>
              <?php if(!empty($m['comprovante_arquivo'])): ?>
              <a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($m['comprovante_arquivo']) ?>" target="_blank" class="ms-2 text-secondary" title="Ver comprovante"><i class="bi bi-eye-fill"></i></a>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold <?= $isC?'valor-credito':'valor-debito' ?>"><?= $isC?'+':'-' ?> R$ <?= number_format($m['valor'],2,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light"><tr><td colspan="2" class="fw-bold text-end">Saldo:</td><td class="text-end fw-bold <?= $saldo>=0?'saldo-positivo':'saldo-negativo' ?>">R$ <?= number_format(abs($saldo),2,',','.') ?></td></tr></tfoot>
        </table>
        </div>
        <?php else: ?><p class="text-muted text-center py-4"><i class="bi bi-inbox d-block fs-2 mb-2"></i>Nenhuma movimentação.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-receipt me-2 text-success"></i>Enviar Comprovante</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="enviar_comprovante">
          <div class="mb-3"><label class="form-label fw-semibold">Valor Pago (R$) *</label><input type="text" name="valor_declarado" class="form-control" placeholder="0,00" required></div>
          <div class="mb-3"><label class="form-label fw-semibold">Descrição (opcional)</label><input type="text" name="descricao" class="form-control" placeholder="PIX, transferência..."></div>
          <div class="mb-3"><label class="form-label fw-semibold">Arquivo * <small class="text-muted">(JPG, PNG ou PDF)</small></label><input type="file" name="comprovante" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required></div>
          <button type="submit" class="btn btn-success w-100 fw-semibold"><i class="bi bi-cloud-upload me-2"></i>Enviar Comprovante</button>
        </form>
        <div class="form-text mt-2 text-muted"><i class="bi bi-info-circle me-1"></i>Aguarde confirmação do administrador.</div>
      </div>
    </div>
    <?php if($comps): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-hourglass-split me-2 text-warning"></i>Aguardando Confirmação</div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach($comps as $cp): ?>
          <li class="list-group-item small">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold">R$ <?= number_format($cp['valor_declarado'],2,',','.') ?></div>
                <div class="text-muted"><?= date('d/m/Y H:i',strtotime($cp['criado_em'])) ?></div>
                <?php if($cp['descricao']): ?><div class="text-muted"><?= htmlspecialchars($cp['descricao']) ?></div><?php endif; ?>
              </div>
              <div class="text-end">
                <span class="badge bg-warning text-dark">Aguardando</span>
                <?php if(in_array($cp['tipo_arquivo'],['jpg','jpeg','png','webp'])): ?>
                <div class="mt-1"><a href="<?= BASE_URL ?>/uploads/comprovantes/<?= htmlspecialchars($cp['arquivo']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></div>
                <?php endif; ?>
              </div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
