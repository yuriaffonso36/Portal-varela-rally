<?php
// admin/mecanicos.php – Sem exclusão, editar e desativar
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('admin');
$u = usuarioLogado(); $area='admin'; $titulo='Mecânicos';
$sucesso=$erro='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $acao=$_POST['acao']??''; $id=(int)($_POST['id']??0);

    if($acao==='novo'){
        $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??'');
        $senha=trim($_POST['senha']??''); $tel=trim($_POST['telefone']??'');
        $username=trim($_POST['username']??'');
        if(!$nome||!$senha||!$username){$erro='Nome, usuário e senha são obrigatórios.';}
        else{
            try{
                $pdo->prepare("INSERT INTO usuarios (nome,username,email,senha,tipo,telefone) VALUES (?,?,?,?,?,?)")
                    ->execute([$nome,$username,$email?:null,password_hash($senha,PASSWORD_DEFAULT),'mecanico',$tel]);
                $sucesso="Mecânico '$nome' cadastrado!";
            }catch(PDOException $e){$erro=str_contains($e->getMessage(),'Duplicate')?'Username ou e-mail já cadastrado.':$e->getMessage();}
        }
    }
    elseif($acao==='editar'&&$id){
        $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??'');
        $senha=trim($_POST['senha']??''); $tel=trim($_POST['telefone']??'');
        $username=trim($_POST['username']??'');
        try{
            $pdo->prepare("UPDATE usuarios SET nome=?,username=?,email=?,telefone=? WHERE id=? AND tipo='mecanico'")
                ->execute([$nome,$username,$email?:null,$tel,$id]);
            if($senha) $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([password_hash($senha,PASSWORD_DEFAULT),$id]);
            $sucesso='Mecânico atualizado!';
        }catch(PDOException $e){$erro=str_contains($e->getMessage(),'Duplicate')?'Username já em uso.':$e->getMessage();}
    }
    elseif(in_array($acao,['ativar','desativar'])&&$id){
        $pdo->prepare("UPDATE usuarios SET ativo=? WHERE id=? AND tipo='mecanico'")->execute([$acao==='ativar'?1:0,$id]);
        $sucesso=$acao==='ativar'?'Mecânico ativado.':'Mecânico desativado.';
    }
}

$editando=null;
if(isset($_GET['editar'])){
    $stmt=$pdo->prepare("SELECT * FROM usuarios WHERE id=? AND tipo='mecanico'");
    $stmt->execute([(int)$_GET['editar']]); $editando=$stmt->fetch();
}

$mecanicos=$pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM ordens_servico os WHERE os.mecanico_id=u.id AND os.status NOT IN ('concluida','cancelada')) AS os_ativas,
           (SELECT COUNT(*) FROM ordens_servico os WHERE os.mecanico_id=u.id) AS os_total
    FROM usuarios u WHERE u.tipo='mecanico' ORDER BY u.nome")->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-wrench-adjustable me-2 text-success"></i>Mecânicos</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovo">
    <i class="bi bi-plus me-1"></i>Novo Mecânico
  </button>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Novo -->
<div class="collapse mb-4 <?= $erro?'show':'' ?>" id="formNovo">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Cadastrar Mecânico</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="novo">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Senha *</label><input type="text" name="senha" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Cadastrar</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Lista -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr><th>Nome</th><th>Username</th><th>Telefone</th><th class="text-center">OS Ativas</th><th class="text-center">OS Total</th><th>Status</th><th>Ações</th></tr>
      </thead>
      <tbody>
      <?php foreach($mecanicos as $m): ?>
      <tr>
        <td><a href="?editar=<?= $m['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($m['nome']) ?></a></td>
        <td><code><?= htmlspecialchars($m['username']??'—') ?></code></td>
        <td class="small text-muted"><?= htmlspecialchars($m['telefone']??'—') ?></td>
        <td class="text-center"><span class="badge bg-primary"><?= $m['os_ativas'] ?></span></td>
        <td class="text-center"><span class="badge bg-secondary"><?= $m['os_total'] ?></span></td>
        <td><span class="badge <?= $m['ativo']?'bg-success':'bg-secondary' ?>"><?= $m['ativo']?'Ativo':'Inativo' ?></span></td>
        <td class="text-nowrap">
          <a href="?editar=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline">
            <input type="hidden" name="acao" value="<?= $m['ativo']?'desativar':'ativar' ?>">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn btn-sm <?= $m['ativo']?'btn-outline-secondary':'btn-outline-success' ?>"
                    data-confirm="<?= $m['ativo']?'Desativar?':'Ativar?' ?>">
              <?= $m['ativo']?'Desativar':'Ativar' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Editar -->
<?php if($editando): ?>
<div class="modal show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar — <?= htmlspecialchars($editando['nome']) ?></h5>
        <a href="mecanicos.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="acao" value="editar">
          <input type="hidden" name="id" value="<?= $editando['id'] ?>">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editando['nome']) ?>" required></div>
            <div class="col-md-4"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($editando['username']??'') ?>" required></div>
            <div class="col-md-4"><label class="form-label">Nova Senha</label><input type="text" name="senha" class="form-control" placeholder="Em branco = mantém"></div>
            <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($editando['telefone']??'') ?>"></div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <a href="mecanicos.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
