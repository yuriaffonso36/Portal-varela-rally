<?php
// navegador/ver_os.php – Visualizar/editar OS com rally e categorias
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('navegador');
$u = usuarioLogado();
$area = 'navegador'; $titulo = 'OS';

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT n.*, p.nome AS piloto_nome, r.nome AS rally_nome
    FROM nav_os n
    JOIN usuarios p ON p.id=n.piloto_id
    LEFT JOIN rallys r ON r.id=n.rally_id
    WHERE n.id=? AND n.navegador_id=?");
$stmt->execute([$osId,$u['id']]); $os=$stmt->fetch();
if (!$os) { header('Location: index.php'); exit; }

$sucesso=$erro='';
$aberta=($os['status']==='aberta');

$rallys     = $pdo->query("SELECT id,nome FROM rallys WHERE ativo=1 ORDER BY data_inicio DESC")->fetchAll();
$categorias = $pdo->query("SELECT id,nome,cor,icone FROM rally_categorias WHERE ativo=1 ORDER BY nome")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST' && $aberta) {
    $acao=$_POST['acao']??'';

    if ($acao==='add_item') {
        $desc    = trim($_POST['descricao']??'');
        $valor   = (float)str_replace(['.',',' ],['','.'],$_POST['valor']??'0');
        $catId   = (int)($_POST['categoria_id']??0)?:null;
        $semComp = isset($_POST['sem_comprovante'])?1:0;
        $compArq = null;

        if (!$desc||$valor<=0) { $erro='Informe descrição e valor.'; }
        else {
            if (!$semComp&&!empty($_FILES['comprovante']['name'])&&$_FILES['comprovante']['error']===UPLOAD_ERR_OK) {
                $ext=strtolower(pathinfo($_FILES['comprovante']['name'],PATHINFO_EXTENSION));
                if (in_array($ext,['jpg','jpeg','png','webp','pdf','heic'])) {
                    $nomeArq='nav_'.$u['id'].'_'.uniqid().'.'.$ext;
                    if(!is_dir(UPLOAD_DIR.'nav_comprovantes/'))mkdir(UPLOAD_DIR.'nav_comprovantes/',0755,true);
                    move_uploaded_file($_FILES['comprovante']['tmp_name'],UPLOAD_DIR.'nav_comprovantes/'.$nomeArq);
                    $compArq=$nomeArq;
                }
            }
            $pdo->prepare("INSERT INTO nav_os_itens (nav_os_id,descricao,categoria_id,valor,sem_comprovante,comprovante) VALUES (?,?,?,?,?,?)")
                ->execute([$osId,$desc,$catId,$valor,$semComp,$compArq]);
            $tot=$pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM nav_os_itens WHERE nav_os_id=?"); $tot->execute([$osId]);
            $pdo->prepare("UPDATE nav_os SET valor_total=? WHERE id=?")->execute([$tot->fetchColumn(),$osId]);
            $sucesso='Item adicionado!';
        }
    }
    elseif ($acao==='del_item') {
        $itemId=(int)($_POST['item_id']??0);
        $comp=$pdo->prepare("SELECT comprovante FROM nav_os_itens WHERE id=? AND nav_os_id=?");
        $comp->execute([$itemId,$osId]); $comp=$comp->fetchColumn();
        if($comp){$arq=UPLOAD_DIR.'nav_comprovantes/'.$comp;if(file_exists($arq))unlink($arq);}
        $pdo->prepare("DELETE FROM nav_os_itens WHERE id=? AND nav_os_id=?")->execute([$itemId,$osId]);
        $tot=$pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM nav_os_itens WHERE nav_os_id=?"); $tot->execute([$osId]);
        $pdo->prepare("UPDATE nav_os SET valor_total=? WHERE id=?")->execute([$tot->fetchColumn(),$osId]);
        $sucesso='Item removido.';
    }
    elseif ($acao==='editar_os') {
        $tit   = trim($_POST['titulo']??'');
        $rallyId=(int)($_POST['rally_id']??0)?:null;
        $desc  = trim($_POST['descricao']??'');
        $cobrar = ($_POST['cobrar_de']??'')==='equipe'?'equipe':'piloto';
        if($tit) {
            $pdo->prepare("UPDATE nav_os SET titulo=?,rally_id=?,descricao=?,cobrar_de=? WHERE id=? AND navegador_id=? AND status='aberta'")
                ->execute([$tit,$rallyId,$desc,$cobrar,$osId,$u['id']]);
            $sucesso='OS atualizada!';
        }
    }
    elseif ($acao==='encerrar') {
        $cnt=$pdo->prepare("SELECT COUNT(*) FROM nav_os_itens WHERE nav_os_id=?"); $cnt->execute([$osId]);
        if($cnt->fetchColumn()==0) { $erro='Adicione pelo menos um item antes de encerrar.'; }
        else {
            $pdo->prepare("UPDATE nav_os SET status='encerrada' WHERE id=?")->execute([$osId]);
            $sucesso='OS encerrada e enviada para aprovação!';
            $stmt->execute([$osId,$u['id']]); $os=$stmt->fetch(); $aberta=false;
        }
    }

    if(!$erro) { $stmt->execute([$osId,$u['id']]); $os=$stmt->fetch(); }
}

$itens=$pdo->prepare("
    SELECT i.*, c.nome AS cat_nome, c.cor AS cat_cor, c.icone AS cat_icone
    FROM nav_os_itens i
    LEFT JOIN rally_categorias c ON c.id=i.categoria_id
    WHERE i.nav_os_id=? ORDER BY i.criado_em ASC");
$itens->execute([$osId]); $itens=$itens->fetchAll();

$catMap=[];
foreach($categorias as $c) $catMap[$c['id']]=$c;

$statusLabel=['aberta'=>'Aberta','encerrada'=>'Aguard. Aprovação','concluida'=>'Concluída'];
$statusBadge=['aberta'=>'bg-primary','encerrada'=>'bg-warning text-dark','concluida'=>'bg-success'];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="index.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Minhas OS</a>
    <h5 class="fw-bold mb-0 mt-1"><?= htmlspecialchars($os['titulo']) ?></h5>
    <div class="text-muted small mt-1">
      <i class="bi bi-person me-1"></i>Piloto: <strong><?= htmlspecialchars($os['piloto_nome']) ?></strong>
      &nbsp;·&nbsp;Cobrar de: <?= ($os['cobrar_de']??'piloto')==='equipe' ? '<span class="badge bg-dark">Equipe</span>' : '<span class="badge bg-info text-dark">Piloto</span>' ?>
      <?php if($os['rally_nome']): ?>
      &nbsp;·&nbsp;<i class="bi bi-flag me-1"></i><?= htmlspecialchars($os['rally_nome']) ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="badge <?= $statusBadge[$os['status']]??'bg-secondary' ?> fs-6">
      <?= $statusLabel[$os['status']]??$os['status'] ?>
    </span>
    <span class="fs-5 fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
  </div>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if(!$aberta): ?>
<div class="alert alert-info mb-3">
  <i class="bi bi-lock-fill me-2"></i>OS encerrada.
  <?php if($os['ok_admin']||$os['ok_cliente']): ?>
  <?= $os['ok_admin']?'✅ Admin aprovou. ':'' ?><?= $os['ok_cliente']?'✅ Piloto aprovou.':'' ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">

    <!-- Editar dados da OS (só aberta) -->
    <?php if($aberta): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#formEditOS">
        <i class="bi bi-pencil me-2 text-secondary"></i>Editar Dados da OS
        <i class="bi bi-chevron-down float-end"></i>
      </div>
      <div class="collapse" id="formEditOS">
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="acao" value="editar_os">
            <div class="row g-2">
              <div class="col-md-7">
                <label class="form-label form-label-sm">Título</label>
                <input type="text" name="titulo" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($os['titulo']) ?>" required>
              </div>
              <div class="col-md-5">
                <label class="form-label form-label-sm">Rally</label>
                <select name="rally_id" class="form-select form-select-sm">
                  <option value="">— Sem rally —</option>
                  <?php foreach($rallys as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= $os['rally_id']==$r['id']?'selected':'' ?>>
                    <?= htmlspecialchars($r['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-7">
                <label class="form-label form-label-sm">Descrição</label>
                <input type="text" name="descricao" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($os['descricao']??'') ?>">
              </div>
              <div class="col-md-5">
                <label class="form-label form-label-sm">Cobrar de</label>
                <select name="cobrar_de" class="form-select form-select-sm">
                  <option value="piloto" <?= ($os['cobrar_de']??'piloto')==='piloto'?'selected':'' ?>>Piloto (<?= htmlspecialchars($os['piloto_nome']) ?>)</option>
                  <option value="equipe" <?= ($os['cobrar_de']??'')==='equipe'?'selected':'' ?>>Equipe Varela</option>
                </select>
              </div>
            </div>
            <div class="mt-2">
              <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Salvar</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Itens -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-list-check me-2" style="color:#fd7e14"></i>Itens Cobrados</span>
        <span class="badge bg-secondary"><?= count($itens) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if($itens): ?>
        <table class="table table-hover mb-0 small align-middle">
          <thead>
            <tr>
              <th>Descrição</th>
              <th>Categoria</th>
              <th class="text-end">Valor</th>
              <th class="text-center">Comprovante</th>
              <?php if($aberta): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach($itens as $item): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($item['descricao']) ?></td>
            <td>
              <?php if($item['cat_nome']): ?>
              <span class="badge" style="background:<?= $item['cat_cor'] ?>">
                <i class="bi <?= $item['cat_icone'] ?> me-1"></i><?= htmlspecialchars($item['cat_nome']) ?>
              </span>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-bold text-success">R$ <?= number_format($item['valor'],2,',','.') ?></td>
            <td class="text-center">
              <?php if($item['comprovante']): ?>
              <a href="<?= BASE_URL ?>/uploads/nav_comprovantes/<?= htmlspecialchars($item['comprovante']) ?>"
                 target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                <i class="bi bi-eye me-1"></i>Ver
              </a>
              <?php elseif($item['sem_comprovante']): ?>
              <span class="badge bg-secondary">Sem comp.</span>
              <?php endif; ?>
            </td>
            <?php if($aberta): ?>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="acao" value="del_item">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1"
                        data-confirm="Remover este item?">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td colspan="2" class="fw-bold text-end">Total:</td>
              <td class="text-end fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></td>
              <td colspan="<?= $aberta?2:1 ?>"></td>
            </tr>
          </tfoot>
        </table>
        <?php else: ?>
        <p class="text-muted text-center py-4 mb-0">
          <i class="bi bi-inbox d-block fs-2 mb-2"></i>Nenhum item lançado.
        </p>
        <?php endif; ?>
      </div>

      <!-- Adicionar item -->
      <?php if($aberta): ?>
      <div class="card-footer bg-light border-top">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="add_item">
          <h6 class="fw-semibold mb-3">Adicionar Item</h6>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label form-label-sm">Categoria</label>
              <select name="categoria_id" class="form-select form-select-sm">
                <option value="">— Sem categoria —</option>
                <?php foreach($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Descrição *</label>
              <input type="text" name="descricao" class="form-control form-control-sm"
                     placeholder="Ex: Combustível, Hotel..." required>
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm">Valor (R$) *</label>
              <input type="text" name="valor" class="form-control form-control-sm"
                     placeholder="0,00" required>
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm">Comprovante</label>
              <input type="file" name="comprovante" id="compInput"
                     class="form-control form-control-sm"
                     accept="image/*,.pdf" capture="environment">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="sem_comprovante" id="semComp"
                       onchange="document.getElementById('compInput').disabled=this.checked">
                <label class="form-check-label small text-muted" for="semComp">Sem comprovante</label>
              </div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Adicionar
              </button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <!-- Resumo -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom">Resumo</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Itens:</span><span class="fw-semibold"><?= count($itens) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Com comprovante:</span>
          <span class="fw-semibold"><?= count(array_filter($itens,fn($i)=>$i['comprovante'])) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Sem comprovante:</span>
          <span class="fw-semibold"><?= count(array_filter($itens,fn($i)=>$i['sem_comprovante'])) ?></span>
        </div>
        <hr>
        <div class="d-flex justify-content-between">
          <span class="fw-bold">Total:</span>
          <span class="fw-bold fs-5 text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
        </div>
      </div>
    </div>

    <!-- Encerrar -->
    <?php if($aberta): ?>
    <div class="card border-0 shadow-sm border-start border-danger border-3">
      <div class="card-body">
        <p class="text-muted small mb-3">
          <?= ($os['cobrar_de']??'piloto')==='equipe' ? 'Ao encerrar, a OS vai para aprovação do administrador (custo da equipe).' : 'Ao encerrar, a OS vai para aprovação do piloto e do administrador.' ?> Não será mais possível editar.
        </p>
        <form method="post">
          <input type="hidden" name="acao" value="encerrar">
          <button class="btn btn-danger w-100 fw-semibold"
                  data-confirm="Encerrar e enviar para aprovação?">
            <i class="bi bi-send-check me-2"></i>Encerrar OS
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Status aprovação -->
    <?php if(!$aberta && $os['status']!=='concluida'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Aprovações</div>
      <div class="card-body">
        <?php if(($os['cobrar_de']??'piloto')!=='equipe'): ?>
        <div class="d-flex justify-content-between align-items-center mb-2 p-2 rounded <?= $os['ok_cliente']?'bg-success bg-opacity-10':'bg-light' ?>">
          <span class="fw-semibold small">Piloto</span>
          <?= $os['ok_cliente']?'<i class="bi bi-check-circle-fill text-success fs-5"></i>':'<i class="bi bi-clock text-muted fs-5"></i>' ?>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center p-2 rounded <?= $os['ok_admin']?'bg-success bg-opacity-10':'bg-light' ?>">
          <span class="fw-semibold small">Administrador</span>
          <?= $os['ok_admin']?'<i class="bi bi-check-circle-fill text-success fs-5"></i>':'<i class="bi bi-clock text-muted fs-5"></i>' ?>
        </div>
      </div>
    </div>
    <?php elseif($os['status']==='concluida'): ?>
    <div class="card border-0 shadow-sm border-success">
      <div class="card-body text-center py-3">
        <i class="bi bi-check-circle-fill text-success d-block fs-1 mb-1"></i>
        <div class="fw-bold text-success">Aprovada e lançada!</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
