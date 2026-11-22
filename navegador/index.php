<?php
// navegador/index.php – Lista completa de OS com lançamento rápido e exclusão
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('navegador');
$u = usuarioLogado();
$area = 'navegador'; $titulo = 'Minhas OS';

// Piloto vinculado
$stmtNav = $pdo->prepare("SELECT n.piloto_id, p.nome AS piloto_nome FROM usuarios n LEFT JOIN usuarios p ON p.id=n.piloto_id WHERE n.id=?");
$stmtNav->execute([$u['id']]); $navInfo = $stmtNav->fetch() ?: []; $pilotoId=(int)($navInfo['piloto_id'] ?? 0); $pilotoNome = $navInfo['piloto_nome'] ?? 'piloto';

$sucesso = $erro = '';

// Rallys disponíveis
$rallys = $pdo->query("SELECT id,nome FROM rallys WHERE ativo=1 ORDER BY data_inicio DESC")->fetchAll();

// Categorias
$categorias = $pdo->query("SELECT id,nome,cor,icone FROM rally_categorias WHERE ativo=1 ORDER BY nome")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Criar nova OS
    if ($acao === 'nova') {
        $titulo_os = trim($_POST['titulo'] ?? '');
        $desc      = trim($_POST['descricao'] ?? '');
        $rallyId   = (int)($_POST['rally_id'] ?? 0) ?: null;
        $cobrarDe  = ($_POST['cobrar_de'] ?? '') === 'equipe' ? 'equipe' : 'piloto';
        if (!$titulo_os) { $erro = 'Informe o título da OS.'; }
        elseif (!$pilotoId) { $erro = 'Você não tem piloto vinculado. Contate o administrador.'; }
        else {
            $pdo->prepare("INSERT INTO nav_os (navegador_id,piloto_id,rally_id,titulo,descricao,status,cobrar_de) VALUES (?,?,?,?,?,'aberta',?)")
                ->execute([$u['id'],$pilotoId,$rallyId,$titulo_os,$desc,$cobrarDe]);
            $sucesso = 'OS criada!';
        }
    }

    // Excluir OS (apenas abertas)
    elseif ($acao === 'excluir') {
        $osId = (int)($_POST['os_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM nav_os WHERE id=? AND navegador_id=? AND status='aberta'");
        $stmt->execute([$osId,$u['id']]);
        if ($stmt->fetch()) {
            // Apaga comprovantes dos itens
            $itens = $pdo->prepare("SELECT comprovante FROM nav_os_itens WHERE nav_os_id=? AND comprovante IS NOT NULL");
            $itens->execute([$osId]);
            foreach ($itens->fetchAll() as $it) {
                $arq = UPLOAD_DIR.'nav_comprovantes/'.$it['comprovante'];
                if (file_exists($arq)) unlink($arq);
            }
            $pdo->prepare("DELETE FROM nav_os WHERE id=? AND navegador_id=?")->execute([$osId,$u['id']]);
            $sucesso = 'OS excluída.';
        }
    }

    // Lançamento rápido (câmera)
    elseif ($acao === 'lancamento_rapido') {
        $osId      = (int)($_POST['os_id'] ?? 0);
        $desc      = trim($_POST['descricao'] ?? '');
        $valor     = (float)str_replace(['.',',' ],['','.'], $_POST['valor'] ?? '0');
        $catId     = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $semComp   = isset($_POST['sem_comprovante']) ? 1 : 0;
        $compArq   = null;

        // Valida que a OS é do navegador e está aberta
        $stmtOS = $pdo->prepare("SELECT id,valor_total FROM nav_os WHERE id=? AND navegador_id=? AND status='aberta'");
        $stmtOS->execute([$osId,$u['id']]); $osCheck=$stmtOS->fetch();

        if (!$osCheck) { $erro='OS não encontrada ou já encerrada.'; }
        elseif (!$desc || $valor <= 0) { $erro='Informe descrição e valor.'; }
        else {
            // Upload comprovante
            if (!$semComp && !empty($_FILES['comprovante']['name']) && $_FILES['comprovante']['error']===UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprovante']['name'],PATHINFO_EXTENSION));
                if (in_array($ext,['jpg','jpeg','png','webp','pdf','heic'])) {
                    $nomeArq = 'nav_'.$u['id'].'_'.uniqid().'.'.$ext;
                    if (!is_dir(UPLOAD_DIR.'nav_comprovantes/')) mkdir(UPLOAD_DIR.'nav_comprovantes/',0755,true);
                    move_uploaded_file($_FILES['comprovante']['tmp_name'],UPLOAD_DIR.'nav_comprovantes/'.$nomeArq);
                    $compArq = $nomeArq;
                }
            }
            $pdo->prepare("INSERT INTO nav_os_itens (nav_os_id,descricao,categoria_id,valor,sem_comprovante,comprovante) VALUES (?,?,?,?,?,?)")
                ->execute([$osId,$desc,$catId,$valor,$semComp,$compArq]);
            // Recalcula total
            $tot=$pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM nav_os_itens WHERE nav_os_id=?");
            $tot->execute([$osId]);
            $pdo->prepare("UPDATE nav_os SET valor_total=? WHERE id=?")->execute([$tot->fetchColumn(),$osId]);
            $sucesso='Item lançado na OS!';
        }
    }
}

// Lista todas as OS
$todas = $pdo->prepare("
    SELECT n.*, r.nome AS rally_nome,
           (SELECT COUNT(*) FROM nav_os_itens i WHERE i.nav_os_id=n.id) AS total_itens
    FROM nav_os n
    LEFT JOIN rallys r ON r.id=n.rally_id
    WHERE n.navegador_id=?
    ORDER BY n.criado_em DESC");
$todas->execute([$u['id']]); $todas=$todas->fetchAll();

$statusLabel=['aberta'=>'Aberta','encerrada'=>'Aguard. Aprovação','aprovada_nav'=>'Aprovada','aprovada_admin'=>'Aprovada','concluida'=>'Concluída'];
$statusBadge=['aberta'=>'bg-primary','encerrada'=>'bg-warning text-dark','aprovada_nav'=>'bg-success','aprovada_admin'=>'bg-success','concluida'=>'bg-success'];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-compass me-2" style="color:#fd7e14"></i>Minhas Ordens de Serviço</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNova">
    <i class="bi bi-plus-lg me-1"></i>Nova OS
  </button>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Form Nova OS -->
<div class="collapse mb-4" id="formNova">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold border-bottom">Nova Ordem de Serviço</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="nova">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label">Título *</label>
            <input type="text" name="titulo" class="form-control" placeholder="Ex: Rally dos Sertões 2026..." required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Rally</label>
            <select name="rally_id" class="form-select">
              <option value="">— Sem rally —</option>
              <?php foreach($rallys as $r): ?>
              <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Cobrar de</label>
            <select name="cobrar_de" class="form-select">
              <option value="piloto">Piloto (<?= htmlspecialchars($pilotoNome) ?>)</option>
              <option value="equipe">Equipe Varela</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Descrição</label>
            <input type="text" name="descricao" class="form-control" placeholder="Obs...">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Criar OS</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lista de OS -->
<?php if($todas): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Título</th>
          <th>Rally</th>
          <th class="text-center">Itens</th>
          <th class="text-end">Valor</th>
          <th>Status</th>
          <th>Data</th>
          <th class="text-center">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($todas as $os): ?>
      <tr>
        <td>
          <a href="ver_os.php?id=<?= $os['id'] ?>" class="fw-semibold text-decoration-none text-dark">
            <?= htmlspecialchars($os['titulo']) ?>
          </a>
        </td>
        <td class="small text-muted">
          <?= $os['rally_nome'] ? '<i class="bi bi-flag me-1"></i>'.htmlspecialchars($os['rally_nome']) : '—' ?>
          <div><?= ($os['cobrar_de'] ?? 'piloto') === 'equipe' ? '<span class="badge bg-dark">Cobrar da equipe</span>' : '<span class="badge bg-info text-dark">Cobrar do piloto</span>' ?></div>
        </td>
        <td class="text-center">
          <span class="badge bg-secondary"><?= $os['total_itens'] ?></span>
        </td>
        <td class="text-end fw-bold text-success">
          R$ <?= number_format($os['valor_total'],2,',','.') ?>
        </td>
        <td>
          <span class="badge <?= $statusBadge[$os['status']] ?>">
            <?= $statusLabel[$os['status']] ?>
          </span>
        </td>
        <td class="small text-muted"><?= date('d/m/Y',strtotime($os['criado_em'])) ?></td>
        <td class="text-center text-nowrap">
          <!-- Ver/Editar OS -->
          <a href="ver_os.php?id=<?= $os['id'] ?>"
             class="btn btn-sm <?= $os['status']==='aberta'?'btn-outline-primary':'btn-outline-secondary' ?> me-1"
             title="<?= $os['status']==='aberta'?'Editar':'Visualizar' ?>">
            <i class="bi bi-<?= $os['status']==='aberta'?'pencil':'eye' ?>"></i>
          </a>

          <!-- Câmera – lançamento rápido (só OS abertas) -->
          <?php if($os['status']==='aberta'): ?>
          <button class="btn btn-sm btn-outline-success me-1"
                  title="Lançamento rápido"
                  onclick="abrirLancamento(<?= $os['id'] ?>, '<?= htmlspecialchars($os['titulo'],ENT_QUOTES) ?>')">
            <i class="bi bi-camera-fill"></i>
          </button>

          <!-- Excluir (só OS abertas) -->
          <form method="post" class="d-inline">
            <input type="hidden" name="acao" value="excluir">
            <input type="hidden" name="os_id" value="<?= $os['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"
                    title="Excluir OS"
                    data-confirm="Excluir a OS '<?= htmlspecialchars($os['titulo'],ENT_QUOTES) ?>'? Todos os itens serão removidos.">
              <i class="bi bi-trash3"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-clipboard2-x d-block fs-1 mb-2"></i>
  Nenhuma OS criada ainda.
  <div class="mt-3">
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#formNova">
      <i class="bi bi-plus-lg me-1"></i>Criar primeira OS
    </button>
  </div>
</div>
<?php endif; ?>

<!-- Modal Lançamento Rápido -->
<div class="modal fade" id="modalLancamento" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#fd7e14">
        <h5 class="modal-title text-white">
          <i class="bi bi-camera-fill me-2"></i>Lançamento Rápido
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data" id="formLancamento">
        <div class="modal-body">
          <input type="hidden" name="acao" value="lancamento_rapido">
          <input type="hidden" name="os_id" id="lanceOsId">

          <div class="alert alert-light border small py-2 mb-3">
            <i class="bi bi-clipboard me-1"></i>OS: <strong id="lanceOsTitulo"></strong>
          </div>

          <!-- Comprovante primeiro (câmera) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">
              <i class="bi bi-camera-fill me-1 text-success"></i>Foto / Comprovante
            </label>
            <input type="file" name="comprovante" id="lanceComp" class="form-control"
                   accept="image/*,.pdf" capture="environment"
                   onchange="document.getElementById('semCompCheck').checked=false">
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="sem_comprovante"
                     id="semCompCheck" onchange="document.getElementById('lanceComp').disabled=this.checked">
              <label class="form-check-label small text-muted" for="semCompCheck">
                Sem comprovante
              </label>
            </div>
          </div>

          <!-- Categoria -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Categoria</label>
            <select name="categoria_id" class="form-select">
              <option value="">— Selecione —</option>
              <?php foreach($categorias as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Descrição -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Descrição *</label>
            <input type="text" name="descricao" class="form-control"
                   placeholder="Ex: Abastecimento posto km 250..." required>
          </div>

          <!-- Valor -->
          <div class="mb-1">
            <label class="form-label fw-semibold">Valor (R$) *</label>
            <input type="text" name="valor" class="form-control form-control-lg fw-bold"
                   placeholder="0,00" required inputmode="decimal">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success fw-semibold px-4">
            <i class="bi bi-check-lg me-1"></i>Lançar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function abrirLancamento(osId, osTitulo) {
    document.getElementById('lanceOsId').value = osId;
    document.getElementById('lanceOsTitulo').textContent = osTitulo;
    // Reset form
    document.getElementById('formLancamento').reset();
    document.getElementById('lanceComp').disabled = false;
    new bootstrap.Modal(document.getElementById('modalLancamento')).show();
    // Foca no input de arquivo para abrir câmera/galeria imediatamente no mobile
    setTimeout(() => document.getElementById('lanceComp').click(), 400);
}
</script>

<?php require_once '../includes/footer.php'; ?>
