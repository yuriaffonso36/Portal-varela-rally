<?php
// admin/navegadores.php – Sem exclusão, editar e desativar
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('admin');
$u = usuarioLogado(); $area='admin'; $titulo='Navegadores';
$sucesso=$erro='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $acao=$_POST['acao']??''; $id=(int)($_POST['id']??0);
    $nome=trim($_POST['nome']??''); $username=trim($_POST['username']??'');
    $email=trim($_POST['email']??''); $senha=trim($_POST['senha']??'');
    $tel=trim($_POST['telefone']??''); $pilotoId=(int)($_POST['piloto_id']??0)?:null;

    if($acao==='novo'){
        if(!$nome||!$username||!$senha){$erro='Nome, usuário e senha são obrigatórios.';}
        elseif(!$pilotoId){$erro='Selecione o piloto vinculado.';}
        else{
            try{
                $pdo->prepare("INSERT INTO usuarios (nome,username,email,senha,tipo,telefone,piloto_id) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$nome,$username,$email?:null,password_hash($senha,PASSWORD_DEFAULT),'navegador',$tel,$pilotoId]);
                $sucesso="Navegador '$nome' cadastrado!";
            }catch(PDOException $e){$erro=str_contains($e->getMessage(),'Duplicate')?'Username ou e-mail já cadastrado.':$e->getMessage();}
        }
    }
    elseif($acao==='editar'&&$id){
        try{
            $pdo->prepare("UPDATE usuarios SET nome=?,username=?,email=?,telefone=?,piloto_id=? WHERE id=? AND tipo='navegador'")
                ->execute([$nome,$username,$email?:null,$tel,$pilotoId,$id]);
            if($senha) $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([password_hash($senha,PASSWORD_DEFAULT),$id]);
            $sucesso='Navegador atualizado!';
        }catch(PDOException $e){$erro=str_contains($e->getMessage(),'Duplicate')?'Username já em uso.':$e->getMessage();}
    }
    elseif(in_array($acao,['ativar','desativar'])&&$id){
        $pdo->prepare("UPDATE usuarios SET ativo=? WHERE id=? AND tipo='navegador'")->execute([$acao==='ativar'?1:0,$id]);
        $sucesso=$acao==='ativar'?'Navegador ativado.':'Navegador desativado.';
    }
}

$editando=null;
if(isset($_GET['editar'])){
    $stmt=$pdo->prepare("SELECT * FROM usuarios WHERE id=? AND tipo='navegador'");
    $stmt->execute([(int)$_GET['editar']]); $editando=$stmt->fetch();
}

$clientes=$pdo->query("SELECT id,nome FROM usuarios WHERE tipo='cliente' AND ativo=1 ORDER BY nome")->fetchAll();

$navegadores=$pdo->query("
    SELECT u.*, p.nome AS piloto_nome,
           (SELECT COUNT(*) FROM nav_os n WHERE n.navegador_id=u.id AND n.status='aberta')    AS os_abertas,
           (SELECT COUNT(*) FROM nav_os n WHERE n.navegador_id=u.id AND n.status='encerrada') AS os_pendentes,
           (SELECT COALESCE(SUM(n.valor_total),0) FROM nav_os n WHERE n.navegador_id=u.id AND n.status='concluida') AS total_aprovado
    FROM usuarios u LEFT JOIN usuarios p ON p.id=u.piloto_id
    WHERE u.tipo='navegador' ORDER BY u.nome")->fetchAll();

$osPendentes=$pdo->query("
    SELECT n.*,u.nome AS navegador_nome,p.nome AS piloto_nome
    FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id JOIN usuarios p ON p.id=n.piloto_id
    WHERE n.status='encerrada' ORDER BY n.atualizado_em DESC")->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-compass me-2 text-danger"></i>Navegadores</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovo">
    <i class="bi bi-plus me-1"></i>Novo Navegador
  </button>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- OS pendentes de aprovação -->
<?php if($osPendentes): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
  <div class="card-header bg-white fw-semibold border-bottom">
    <i class="bi bi-hourglass-split me-2 text-warning"></i>OS Aguardando Aprovação
    <span class="badge bg-warning text-dark ms-1"><?= count($osPendentes) ?></span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 small align-middle">
      <thead><tr><th>OS</th><th>Navegador</th><th>Piloto</th><th class="text-end">Valor</th><th>Cliente OK</th><th>Admin OK</th><th></th></tr></thead>
      <tbody>
      <?php foreach($osPendentes as $os): ?>
      <tr>
        <td><span class="fw-semibold"><?= htmlspecialchars($os['titulo']) ?></span><div class="text-muted" style="font-size:.7rem"><?= date('d/m/Y',strtotime($os['atualizado_em'])) ?></div></td>
        <td><?= htmlspecialchars($os['navegador_nome']) ?></td>
        <td><?= htmlspecialchars($os['piloto_nome']) ?></td>
        <td class="text-end fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></td>
        <td class="text-center"><?= $os['ok_cliente']?'<i class="bi bi-check-circle-fill text-success fs-5"></i>':'<i class="bi bi-circle text-muted fs-5"></i>' ?></td>
        <td class="text-center"><?= $os['ok_admin']?'<i class="bi bi-check-circle-fill text-success fs-5"></i>':'<i class="bi bi-circle text-muted fs-5"></i>' ?></td>
        <td><a href="<?= BASE_URL ?>/admin/aprovar_nav_os.php?id=<?= $os['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-eye me-1"></i>Revisar</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Novo Navegador -->
<div class="collapse mb-4 <?= $erro?'show':'' ?>" id="formNovo">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Cadastrar Navegador</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="novo">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
          <div class="col-md-2"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
          <div class="col-md-2"><label class="form-label">Senha *</label><input type="text" name="senha" class="form-control" required></div>
          <div class="col-md-2"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div>
          <div class="col-12">
            <label class="form-label fw-semibold">Piloto Vinculado (cliente) *</label>
            <select name="piloto_id" class="form-select" required>
              <option value="">— Selecione o piloto —</option>
              <?php foreach($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
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
        <tr><th>Nome</th><th>Username</th><th>Piloto</th><th>Telefone</th><th class="text-center">Abertas</th><th class="text-center">Pendentes</th><th class="text-end">Aprovado</th><th>Status</th><th>Ações</th></tr>
      </thead>
      <tbody>
      <?php foreach($navegadores as $n): ?>
      <tr>
        <td><a href="?editar=<?= $n['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= htmlspecialchars($n['nome']) ?></a></td>
        <td><code><?= htmlspecialchars($n['username']??'—') ?></code></td>
        <td class="small"><?= htmlspecialchars($n['piloto_nome']??'—') ?></td>
        <td class="small text-muted"><?= htmlspecialchars($n['telefone']??'—') ?></td>
        <td class="text-center"><span class="badge bg-secondary"><?= $n['os_abertas'] ?></span></td>
        <td class="text-center">
          <?php if($n['os_pendentes']>0): ?><span class="badge bg-warning text-dark"><?= $n['os_pendentes'] ?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end fw-semibold text-success small">R$ <?= number_format($n['total_aprovado'],2,',','.') ?></td>
        <td><span class="badge <?= $n['ativo']?'bg-success':'bg-secondary' ?>"><?= $n['ativo']?'Ativo':'Inativo' ?></span></td>
        <td class="text-nowrap">
          <a href="?editar=<?= $n['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline">
            <input type="hidden" name="acao" value="<?= $n['ativo']?'desativar':'ativar' ?>">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm <?= $n['ativo']?'btn-outline-secondary':'btn-outline-success' ?>"
                    data-confirm="<?= $n['ativo']?'Desativar?':'Ativar?' ?>">
              <?= $n['ativo']?'Desativar':'Ativar' ?>
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
        <a href="navegadores.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="acao" value="editar">
          <input type="hidden" name="id" value="<?= $editando['id'] ?>">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editando['nome']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($editando['username']??'') ?>" required></div>
            <div class="col-md-3"><label class="form-label">Nova Senha</label><input type="text" name="senha" class="form-control" placeholder="Em branco = mantém"></div>
            <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($editando['telefone']??'') ?>"></div>
            <div class="col-md-5">
              <label class="form-label">Piloto Vinculado</label>
              <select name="piloto_id" class="form-select">
                <option value="">— Nenhum —</option>
                <?php foreach($clientes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $editando['piloto_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <a href="navegadores.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
