<?php
// admin/corridas.php – Gerenciar Corridas/Rallys e Categorias
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corridas.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Corridas';
$sucesso = $erro = '';
$aba = $_GET['aba'] ?? 'corridas'; // corridas | categorias

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Corridas
    if ($acao === 'nova_corrida') {
        $nome  = trim($_POST['nome'] ?? '');
        $local = trim($_POST['local'] ?? '');
        $ini   = $_POST['data_inicio'] ?? null;
        $fim   = $_POST['data_fim']    ?? null;
        $desc  = trim($_POST['descricao'] ?? '');
        if (!$nome) { $erro='Informe o nome da corrida.'; }
        else {
            $pdo->prepare("INSERT INTO rallys (nome,local,data_inicio,data_fim,descricao) VALUES (?,?,?,?,?)")
                ->execute([$nome,$local,$ini?:null,$fim?:null,$desc]);
            header('Location: ver_corrida.php?id='.$pdo->lastInsertId().'&aba=equipe'); exit;
        }
    } elseif ($acao === 'editar_corrida') {
        $id=$_POST['id']??0;
        $pdo->prepare("UPDATE rallys SET nome=?,local=?,data_inicio=?,data_fim=?,descricao=? WHERE id=?")
            ->execute([trim($_POST['nome']??''),trim($_POST['local']??''),$_POST['data_inicio']??null,$_POST['data_fim']??null,trim($_POST['descricao']??''),$id]);
        $sucesso='Corrida atualizada!';
    } elseif ($acao === 'toggle_corrida') {
        $id=$_POST['id']??0;
        $pdo->prepare("UPDATE rallys SET ativo=1-ativo WHERE id=?")->execute([$id]);
        $sucesso='Status atualizado.';
    }

    // Categorias
    elseif ($acao === 'nova_cat') {
        $nome=$_POST['cat_nome']??''; $cor=$_POST['cat_cor']??'#6c757d'; $icone=$_POST['cat_icone']??'bi-tag';
        if ($nome) { $pdo->prepare("INSERT INTO rally_categorias (nome,cor,icone) VALUES (?,?,?)")->execute([trim($nome),$cor,$icone]); $sucesso='Categoria criada!'; }
    } elseif ($acao === 'editar_cat') {
        $id=$_POST['id']??0;
        $pdo->prepare("UPDATE rally_categorias SET nome=?,cor=?,icone=? WHERE id=?")->execute([trim($_POST['cat_nome']??''),$_POST['cat_cor']??'#6c757d',$_POST['cat_icone']??'bi-tag',$id]);
        $sucesso='Categoria atualizada!';
    } elseif ($acao === 'toggle_cat') {
        $id=$_POST['id']??0;
        $pdo->prepare("UPDATE rally_categorias SET ativo=1-ativo WHERE id=?")->execute([$id]);
        $sucesso='Status atualizado.';
    }
}

$corridas   = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM ordens_servico os WHERE os.rally_id=r.id) AS os_clientes, (SELECT COUNT(*) FROM nav_os n WHERE n.rally_id=r.id) AS os_nav,
    (SELECT COUNT(*) FROM rally_participantes p WHERE p.rally_id=r.id) AS n_carros, (SELECT COUNT(*) FROM rally_equipe e WHERE e.rally_id=r.id) AS n_equipe,
    (SELECT COUNT(*) FROM rally_programacao g WHERE g.rally_id=r.id) AS n_eventos
    FROM rallys r ORDER BY (r.data_fim IS NOT NULL AND r.data_fim < CURDATE()), r.data_inicio")->fetchAll();
$categorias = $pdo->query("SELECT * FROM rally_categorias ORDER BY nome")->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-flag-fill me-2 text-danger"></i>Corridas & Rallys</h5>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Abas -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $aba==='corridas'?'active':'' ?>" href="?aba=corridas">
      <i class="bi bi-flag me-1"></i>Corridas
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $aba==='categorias'?'active':'' ?>" href="?aba=categorias">
      <i class="bi bi-tags me-1"></i>Categorias de Gasto
    </a>
  </li>
</ul>

<?php if($aba==='corridas'): ?>

<!-- Nova corrida -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
    <span>Criar Nova Corrida</span>
    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovaCorrida">
      <i class="bi bi-plus-lg me-1"></i>Nova
    </button>
  </div>
  <div class="collapse" id="formNovaCorrida">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="nova_corrida">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Nome da Corrida *</label>
            <input type="text" name="nome" class="form-control" placeholder="Ex: Rally dos Sertões 2026" required></div>
          <div class="col-md-4"><label class="form-label">Local</label>
            <input type="text" name="local" class="form-control" placeholder="Ex: Fortaleza - CE"></div>
          <div class="col-md-2"><label class="form-label">Data Início</label>
            <input type="date" name="data_inicio" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">Data Fim</label>
            <input type="date" name="data_fim" class="form-control"></div>
          <div class="col-12"><label class="form-label">Descrição</label>
            <input type="text" name="descricao" class="form-control"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Criar Corrida</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Lista de corridas -->
<div class="row g-3">
<?php foreach($corridas as $r): $dias=rallyDiasPara($r); $passada=$r['data_fim']&&$r['data_fim']<date('Y-m-d'); ?>
<div class="col-12 col-lg-6">
  <div class="card border-0 shadow-sm h-100 card-link <?= !$r['ativo']?'opacity-50':'' ?>" onclick="if(!event.target.closest('form,button'))location.href='ver_corrida.php?id=<?= $r['id'] ?>'">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="mb-1"><?= rallyBadge($r['status']??'planejada') ?><?php if(!$r['ativo']): ?> <span class="badge bg-light text-dark border">Inativa</span><?php endif; ?></div>
          <h6 class="fw-bold mb-1"><i class="bi bi-flag-fill me-2 text-danger"></i><?= htmlspecialchars($r['nome']) ?></h6>
          <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($r['local']?:'Local a definir') ?> · <i class="bi bi-calendar3 ms-1 me-1"></i><?= rallyPeriodo($r) ?></div>
        </div>
        <?php if($dias!==null&&$dias>0&&!$passada): ?><div class="text-end"><div class="fs-3 fw-bold text-danger lh-1"><?= $dias ?></div><div class="small text-muted">dias</div></div><?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3 small">
        <span class="badge bg-light text-dark border"><i class="bi bi-car-front me-1"></i><?= $r['n_carros'] ?> carro(s)</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-people me-1"></i><?= $r['n_equipe'] ?> na equipe</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-calendar-week me-1"></i><?= $r['n_eventos'] ?> evento(s)</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-clipboard-check me-1"></i><?= $r['os_clientes'] ?> OS · <?= $r['os_nav'] ?> nav</span>
      </div>
    </div>
    <div class="card-footer bg-white border-0 pt-0 d-flex justify-content-between align-items-center">
      <a href="ver_corrida.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Abrir organização</a>
      <div class="d-flex gap-1">
        <a href="ver_corrida.php?id=<?= $r['id'] ?>&aba=relatorio" class="btn btn-sm btn-outline-secondary" title="Relatório"><i class="bi bi-bar-chart"></i></a>
        <form method="post" class="d-inline">
          <input type="hidden" name="acao" value="toggle_corrida"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-sm <?= $r['ativo']?'btn-outline-secondary':'btn-outline-success' ?>" data-confirm="<?= $r['ativo']?'Desativar esta corrida?':'Ativar esta corrida?' ?>"><?= $r['ativo']?'Desativar':'Ativar' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($corridas)): ?>
<div class="col-12"><div class="card border-0 shadow-sm text-center py-5 text-muted"><i class="bi bi-flag d-block fs-1 mb-2"></i>Nenhuma corrida criada ainda.</div></div>
<?php endif; ?>
</div>

<?php elseif($aba==='categorias'): ?>

<div class="row g-4">
  <!-- Nova categoria -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Nova Categoria</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="acao" value="nova_cat">
          <div class="mb-3">
            <label class="form-label">Nome *</label>
            <input type="text" name="cat_nome" class="form-control" placeholder="Ex: Inscrição..." required>
          </div>
          <div class="mb-3">
            <label class="form-label">Cor</label>
            <div class="d-flex gap-2 align-items-center">
              <input type="color" name="cat_cor" class="form-control form-control-color" value="#6c757d" style="width:60px">
              <span class="small text-muted">Cor do badge</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Ícone Bootstrap <a href="https://icons.getbootstrap.com" target="_blank" class="small">(ver ícones)</a></label>
            <input type="text" name="cat_icone" class="form-control" value="bi-tag" placeholder="bi-tag">
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Criar</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Lista categorias -->
  <div class="col-md-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Categorias Cadastradas</div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
          <thead><tr><th>Categoria</th><th>Ícone</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach($categorias as $cat): ?>
          <tr>
            <td>
              <span class="badge" style="background:<?= $cat['cor'] ?>;font-size:.85rem">
                <i class="bi <?= $cat['icone'] ?> me-1"></i><?= htmlspecialchars($cat['nome']) ?>
              </span>
            </td>
            <td class="small text-muted"><code><?= htmlspecialchars($cat['icone']) ?></code></td>
            <td><span class="badge <?= $cat['ativo']?'bg-success':'bg-secondary' ?>"><?= $cat['ativo']?'Ativa':'Inativa' ?></span></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary me-1"
                      onclick="editarCat(<?= htmlspecialchars(json_encode($cat)) ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="acao" value="toggle_cat">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <button class="btn btn-sm <?= $cat['ativo']?'btn-outline-danger':'btn-outline-success' ?>">
                  <?= $cat['ativo']?'Desativar':'Ativar' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal Editar Corrida -->
<div class="modal fade" id="modalEditCorrida" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Editar Corrida</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="acao" value="editar_corrida">
        <input type="hidden" name="id" id="editCorridaId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome *</label><input type="text" name="nome" id="editCorridaNome" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Local</label><input type="text" name="local" id="editCorridaLocal" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Data Início</label><input type="date" name="data_inicio" id="editCorridaIni" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Data Fim</label><input type="date" name="data_fim" id="editCorridaFim" class="form-control"></div>
            <div class="col-12"><label class="form-label">Descrição</label><input type="text" name="descricao" id="editCorridaDesc" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar Categoria -->
<div class="modal fade" id="modalEditCat" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Editar Categoria</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="acao" value="editar_cat">
        <input type="hidden" name="id" id="editCatId">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Nome</label><input type="text" name="cat_nome" id="editCatNome" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Cor</label><input type="color" name="cat_cor" id="editCatCor" class="form-control form-control-color" style="width:60px"></div>
          <div class="mb-3"><label class="form-label">Ícone</label><input type="text" name="cat_icone" id="editCatIcone" class="form-control"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editarCorrida(r) {
    document.getElementById('editCorridaId').value   = r.id;
    document.getElementById('editCorridaNome').value = r.nome;
    document.getElementById('editCorridaLocal').value= r.local||'';
    document.getElementById('editCorridaIni').value  = r.data_inicio||'';
    document.getElementById('editCorridaFim').value  = r.data_fim||'';
    document.getElementById('editCorridaDesc').value = r.descricao||'';
    new bootstrap.Modal(document.getElementById('modalEditCorrida')).show();
}
function editarCat(c) {
    document.getElementById('editCatId').value    = c.id;
    document.getElementById('editCatNome').value  = c.nome;
    document.getElementById('editCatCor').value   = c.cor;
    document.getElementById('editCatIcone').value = c.icone;
    new bootstrap.Modal(document.getElementById('modalEditCat')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
