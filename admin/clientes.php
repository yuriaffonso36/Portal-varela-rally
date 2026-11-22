<?php
// admin/clientes.php – Sem exclusão, nome clicável, veículos dentro do editar
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/fotos.php';
require_once '../includes/veiculos.php';
requireRole('admin');
$u = usuarioLogado(); $area='admin'; $titulo='Clientes';
$sucesso=$erro='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $acao=$_POST['acao']??'';
    $id=(int)($_POST['id']??0);

    if($acao==='novo'){
        $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??''); $senha=trim($_POST['senha']??'');
        $tel=trim($_POST['telefone']??''); $cpf=trim($_POST['cpf']??'');
        $username=strtolower(trim($_POST['username']??''));
        if(!$nome||!$senha||!$username){$erro='Preencha nome, usuário (login) e senha.';}
        elseif(!preg_match('/^[a-z0-9._-]{3,50}$/',$username)){$erro='Usuário inválido: use 3 a 50 caracteres, só letras minúsculas, números, ponto, hífen ou _ (sem espaço e sem acento).';}
        else{
            $dup=$pdo->prepare("SELECT nome FROM usuarios WHERE username=?");$dup->execute([$username]);
            if($outro=$dup->fetchColumn()){$erro="O usuário '$username' já está em uso por $outro.";}
            else try{
                $pdo->prepare("INSERT INTO usuarios (nome,username,email,senha,tipo,telefone,cpf) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$nome,$username,$email?:null,password_hash($senha,PASSWORD_DEFAULT),'cliente',$tel,$cpf]);
                $clienteId=$pdo->lastInsertId();
                $marca=trim($_POST['marca']??''); $modelo=trim($_POST['modelo']??'');
                $placa=strtoupper(trim($_POST['placa']??'')); $ano=(int)($_POST['ano']??0); $cor=trim($_POST['cor']??'');
                $chassi=strtoupper(trim($_POST['chassi']??''))?:null; $km=kmParse($_POST['km']??'');
                if($marca||$modelo||$placa){
                    $pdo->prepare("INSERT INTO veiculos (cliente_id,marca,modelo,placa,ano,cor,chassi) VALUES (?,?,?,?,?,?,?)")->execute([$clienteId,$marca,$modelo,$placa,$ano?:null,$cor,$chassi]);
                    if($km!==null) veiculoRegistrarKm($pdo,(int)$pdo->lastInsertId(),$km,null,$u['id'],'cadastro',true,'Km no cadastro do veículo');
                }
                $sucesso="Cliente '$nome' cadastrado! Login: $username";
            }catch(PDOException $e){$erro=str_contains($e->getMessage(),'Duplicate')?'E-mail ou usuário já cadastrado.':$e->getMessage();}
        }
    }
    elseif($acao==='editar'&&$id){
        $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??''); $senha=trim($_POST['senha']??'');
        $tel=trim($_POST['telefone']??''); $cpf=trim($_POST['cpf']??''); $ativo=(int)($_POST['ativo']??1);
        $username=strtolower(trim($_POST['username']??''));
        if(!$username||!preg_match('/^[a-z0-9._-]{3,50}$/',$username)){$erro='Usuário (login) obrigatório: 3 a 50 caracteres, só letras minúsculas, números, ponto, hífen ou _.';}
        else{
            $dup=$pdo->prepare("SELECT nome FROM usuarios WHERE username=? AND id<>?");$dup->execute([$username,$id]);
            if($outro=$dup->fetchColumn()){$erro="O usuário '$username' já está em uso por $outro.";}
            else{
                $pdo->prepare("UPDATE usuarios SET nome=?,username=?,email=?,telefone=?,cpf=?,ativo=? WHERE id=? AND tipo='cliente'")->execute([$nome,$username,$email?:null,$tel,$cpf,$ativo,$id]);
                if($senha) $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([password_hash($senha,PASSWORD_DEFAULT),$id]);
                $sucesso='Cliente atualizado!';
            }
        }
        if($erro) $_GET['editar']=$id;
    }
    elseif($acao==='add_veiculo'&&$id){
        $marca=trim($_POST['marca']??''); $modelo=trim($_POST['modelo']??''); $placa=strtoupper(trim($_POST['placa']??''));
        $ano=(int)($_POST['ano']??0); $cor=trim($_POST['cor']??'');
        $chassi=strtoupper(trim($_POST['chassi']??''))?:null; $km=kmParse($_POST['km']??'');
        if($marca||$modelo||$placa){
            $pdo->prepare("INSERT INTO veiculos (cliente_id,marca,modelo,placa,ano,cor,chassi) VALUES (?,?,?,?,?,?,?)")->execute([$id,$marca,$modelo,$placa,$ano?:null,$cor,$chassi]);
            if($km!==null) veiculoRegistrarKm($pdo,(int)$pdo->lastInsertId(),$km,null,$u['id'],'cadastro',true,'Km no cadastro do veículo');
            $sucesso='Veículo adicionado!';
        }
        $_GET['editar']=$id;
    }
    elseif($acao==='capa_veiculo'&&$id){
        $vid=(int)($_POST['veiculo_id']??0);
        $v=$pdo->prepare("SELECT * FROM veiculos WHERE id=? AND cliente_id=?");$v->execute([$vid,$id]);$v=$v->fetch();
        if($v&&!empty($_FILES['foto']['name'])){
            $r=fotoSalvarUpload($_FILES['foto'],'veiculos','carro'.$vid);
            if(isset($r['erro']))$erro=$r['erro'];
            else{fotoApagar($v['foto_capa'],$v['foto_capa_thumb']);$pdo->prepare("UPDATE veiculos SET foto_capa=?,foto_capa_thumb=? WHERE id=?")->execute([$r['caminho'],$r['thumb'],$vid]);$sucesso='Foto do carro atualizada!';}
        }
    }
    elseif($acao==='del_veiculo'){
        $vid=(int)($_POST['veiculo_id']??0);
        $n=$pdo->prepare("SELECT COUNT(*) FROM ordens_servico WHERE veiculo_id=?");$n->execute([$vid]);
        if($n->fetchColumn()){$erro='Este veículo tem ordens de serviço no histórico e não pode ser excluído.';}
        else{
            $pdo->prepare("DELETE FROM veiculo_km WHERE veiculo_id=?")->execute([$vid]);
            $pdo->prepare("DELETE FROM veiculos WHERE id=?")->execute([$vid]);
            $sucesso='Veículo removido.';
        }
        $_GET['editar']=$id;
    }
    elseif(in_array($acao,['desativar','ativar'])&&$id){
        $pdo->prepare("UPDATE usuarios SET ativo=? WHERE id=? AND tipo='cliente'")->execute([$acao==='ativar'?1:0,$id]);
        $sucesso=$acao==='ativar'?'Cliente ativado.':'Cliente desativado.';
    }
}

$editando=null;$veicsEdit=[];
if(isset($_GET['editar'])){
    $stmt=$pdo->prepare("SELECT * FROM usuarios WHERE id=? AND tipo='cliente'");
    $stmt->execute([(int)$_GET['editar']]);$editando=$stmt->fetch();
    if($editando){
        $stmt2=$pdo->prepare("SELECT * FROM veiculos WHERE cliente_id=? ORDER BY id ASC");
        $stmt2->execute([$editando['id']]);$veicsEdit=$stmt2->fetchAll();
    }
}

$clientes=$pdo->query("SELECT u.*,(SELECT COUNT(*) FROM ordens_servico os WHERE os.cliente_id=u.id) AS total_os,(SELECT COALESCE(SUM(CASE WHEN tipo='credito' THEN valor ELSE -valor END),0) FROM conta_corrente cc WHERE cc.cliente_id=u.id) AS saldo FROM usuarios u WHERE u.tipo='cliente' ORDER BY u.nome")->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-danger"></i>Clientes</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovo">
    <i class="bi bi-person-plus me-1"></i>Novo Cliente
  </button>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Form Novo -->
<div class="collapse mb-4 <?= $erro?'show':'' ?>" id="formNovo">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Cadastrar Novo Cliente</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="novo">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label">Usuário (login) *</label><input type="text" name="username" class="form-control" required pattern="[a-zA-Z0-9._\-]{3,50}" placeholder="ex.: bruno.silva" autocomplete="off" style="text-transform:lowercase"></div>
          <div class="col-md-4"><label class="form-label">Senha *</label><input type="text" name="senha" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">CPF</label><input type="text" name="cpf" class="form-control"></div>
        </div>
        <hr><h6 class="text-muted">Veículo (opcional)</h6>
        <div class="row g-3">
          <div class="col-md-2"><label class="form-label">Marca</label><input type="text" name="marca" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">Modelo</label><input type="text" name="modelo" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">Placa</label><input type="text" name="placa" class="form-control" style="text-transform:uppercase"></div>
          <div class="col-md-2"><label class="form-label">Ano</label><input type="number" name="ano" class="form-control" min="1970" max="<?= date('Y')+1 ?>"></div>
          <div class="col-md-3"><label class="form-label">Cor</label><input type="text" name="cor" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Chassi</label><input type="text" name="chassi" class="form-control" style="text-transform:uppercase"></div>
          <div class="col-md-3"><label class="form-label">Km atual (painel)</label><input type="text" name="km" class="form-control" inputmode="numeric" placeholder="ex.: 1.000"></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Cadastrar</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Lista -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Nome</th><th>Usuário</th><th>E-mail</th><th>Telefone</th><th>OS</th><th>Saldo</th><th>Status</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach($clientes as $c): ?>
      <tr>
        <td>
          <a href="?editar=<?= $c['id'] ?>" class="fw-semibold text-decoration-none text-dark"
             style="cursor:pointer"><?= htmlspecialchars($c['nome']) ?></a>
          <span class="text-muted small ms-1">#<?= $c['id'] ?></span>
        </td>
        <td><?php if(!empty($c['username'])): ?><code><?= htmlspecialchars($c['username']) ?></code><?php else: ?><a href="?editar=<?= $c['id'] ?>" class="badge bg-warning text-dark text-decoration-none" title="Sem usuário: o cliente não consegue entrar. Clique para definir."><i class="bi bi-exclamation-triangle me-1"></i>sem usuário</a><?php endif; ?></td>
        <td class="small text-muted"><?= htmlspecialchars($c['email']??'—') ?></td>
        <td class="small text-muted"><?= htmlspecialchars($c['telefone']??'—') ?></td>
        <td><a href="<?= BASE_URL ?>/admin/ordens.php" class="badge bg-secondary text-decoration-none"><?= $c['total_os'] ?></a></td>
        <td class="small fw-semibold <?= $c['saldo']<0?'saldo-negativo':'saldo-positivo' ?>">
          R$ <?= number_format(abs($c['saldo']),2,',','.') ?><?= $c['saldo']<0?' D':'' ?>
        </td>
        <td><span class="badge <?= $c['ativo']?'bg-success':'bg-secondary' ?>"><?= $c['ativo']?'Ativo':'Inativo' ?></span></td>
        <td class="text-nowrap">
          <a href="?editar=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
          <a href="<?= BASE_URL ?>/admin/lancamento.php?cliente=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning me-1"><i class="bi bi-cash"></i></a>
          <form method="post" class="d-inline">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <input type="hidden" name="acao" value="<?= $c['ativo']?'desativar':'ativar' ?>">
            <button class="btn btn-sm <?= $c['ativo']?'btn-outline-secondary':'btn-outline-success' ?>"
                    data-confirm="<?= $c['ativo']?'Desativar este cliente?':'Ativar este cliente?' ?>">
              <i class="bi bi-<?= $c['ativo']?'person-dash':'person-check' ?>"></i>
              <?= $c['ativo']?'Desativar':'Ativar' ?>
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

<!-- Modal Editar -->
<?php if($editando): ?>
<div class="modal show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar Cliente #<?= $editando['id'] ?> — <?= htmlspecialchars($editando['nome']) ?></h5>
        <a href="clientes.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="acao" value="editar">
          <input type="hidden" name="id" value="<?= $editando['id'] ?>">
          <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editando['nome']) ?>" required></div>
            <div class="col-md-4"><label class="form-label">Usuário (login) *</label><input type="text" name="username" class="form-control <?= empty($editando['username'])?'is-invalid':'' ?>" value="<?= htmlspecialchars($editando['username']??'') ?>" required pattern="[a-zA-Z0-9._\-]{3,50}" autocomplete="off" style="text-transform:lowercase">
              <?php if(empty($editando['username'])): ?><div class="invalid-feedback d-block">Sem usuário: este cliente não consegue entrar.</div><?php endif; ?></div>
            <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($editando['telefone']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">CPF</label><input type="text" name="cpf" class="form-control" value="<?= htmlspecialchars($editando['cpf']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Nova Senha</label><input type="text" name="senha" class="form-control" placeholder="Deixe em branco para manter"></div>
            <div class="col-md-4"><label class="form-label">Status</label>
              <select name="ativo" class="form-select">
                <option value="1" <?= $editando['ativo']?'selected':'' ?>>Ativo</option>
                <option value="0" <?= !$editando['ativo']?'selected':'' ?>>Inativo</option>
              </select></div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Salvar Dados</button>
        </form>

        <hr class="my-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-car-front me-2 text-secondary"></i>Veículos</h6>
        <?php if($veicsEdit): ?>
        <div class="table-responsive mb-3">
        <table class="table table-sm table-hover">
          <thead><tr><th>Foto 1/4</th><th>Marca</th><th>Modelo</th><th>Placa</th><th>Ano</th><th>Cor</th><th>Km atual</th><th></th></tr></thead>
          <tbody>
          <?php foreach($veicsEdit as $v): ?>
          <tr>
            <td style="min-width:150px">
              <form method="post" enctype="multipart/form-data" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="acao" value="capa_veiculo"><input type="hidden" name="id" value="<?= $editando['id'] ?>"><input type="hidden" name="veiculo_id" value="<?= $v['id'] ?>">
                <?php if(!empty($v['foto_capa'])): ?><img src="<?= fotoThumbUrl($v['foto_capa_thumb'],$v['foto_capa']) ?>" class="carro-mini"><?php else: ?><span class="carro-mini d-inline-flex align-items-center justify-content-center bg-light text-muted"><i class="bi bi-car-front"></i></span><?php endif; ?>
                <label class="btn btn-sm btn-outline-secondary py-0 mb-0" title="Enviar foto 1/4 frontal"><i class="bi bi-camera"></i><input type="file" name="foto" accept="image/*" hidden data-reduzir data-autoenviar></label>
              </form>
            </td>
            <td><?= htmlspecialchars($v['marca']) ?></td><td><?= htmlspecialchars($v['modelo']) ?></td>
            <td><code><?= htmlspecialchars($v['placa']) ?></code></td><td><?= $v['ano']?:'—' ?></td>
            <td><?= htmlspecialchars($v['cor']??'—') ?></td>
            <td class="fw-semibold"><?= kmFmt($v['km_atual']??null) ?></td>
            <td class="text-nowrap"><a href="veiculo.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 me-1" title="Histórico, km e dados do carro"><i class="bi bi-clock-history"></i> Histórico</a><form method="post" class="d-inline"><input type="hidden" name="acao" value="del_veiculo"><input type="hidden" name="id" value="<?= $editando['id'] ?>"><input type="hidden" name="veiculo_id" value="<?= $v['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-2" data-confirm="Excluir este veículo?"><i class="bi bi-trash3"></i></button></form></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php else: ?><p class="text-muted small">Nenhum veículo cadastrado.</p><?php endif; ?>

        <div class="card bg-light border-0 p-3">
          <h6 class="fw-semibold mb-3">Adicionar Veículo</h6>
          <form method="post">
            <input type="hidden" name="acao" value="add_veiculo">
            <input type="hidden" name="id" value="<?= $editando['id'] ?>">
            <div class="row g-2">
              <div class="col-md-2"><input type="text" name="marca" class="form-control form-control-sm" placeholder="Marca"></div>
              <div class="col-md-3"><input type="text" name="modelo" class="form-control form-control-sm" placeholder="Modelo"></div>
              <div class="col-md-2"><input type="text" name="placa" class="form-control form-control-sm" placeholder="Placa" style="text-transform:uppercase"></div>
              <div class="col-md-2"><input type="number" name="ano" class="form-control form-control-sm" placeholder="Ano" min="1970" max="<?= date('Y')+1 ?>"></div>
              <div class="col-md-2"><input type="text" name="cor" class="form-control form-control-sm" placeholder="Cor"></div>
              <div class="col-md-1 d-none d-md-block"></div>
              <div class="col-md-4"><input type="text" name="chassi" class="form-control form-control-sm" placeholder="Chassi" style="text-transform:uppercase"></div>
              <div class="col-md-3"><input type="text" name="km" class="form-control form-control-sm" placeholder="Km atual do painel" inputmode="numeric"></div>
              <div class="col-md-2"><button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div>
            </div>
          </form>
        </div>
      </div>
      <div class="modal-footer"><a href="clientes.php" class="btn btn-secondary">Fechar</a></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
