<?php
// navegador/os.php – Listar e criar OS
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('navegador');
$u = usuarioLogado();
$area = 'navegador'; $titulo = 'Minhas OS';

// Piloto vinculado
$stmt = $pdo->prepare("SELECT piloto_id FROM usuarios WHERE id=?");
$stmt->execute([$u['id']]); $pilotoId = (int)$stmt->fetchColumn();

$sucesso = $erro = '';
$mostrarForm = isset($_GET['nova']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'nova') {
        $titulo_os = trim($_POST['titulo'] ?? '');
        $desc      = trim($_POST['descricao'] ?? '');
        if (!$titulo_os) { $erro = 'Informe o título da OS.'; }
        elseif (!$pilotoId) { $erro = 'Você não tem piloto vinculado. Contate o administrador.'; }
        else {
            $pdo->prepare("INSERT INTO nav_os (navegador_id,piloto_id,titulo,descricao,status) VALUES (?,?,?,?,'aberta')")
                ->execute([$u['id'],$pilotoId,$titulo_os,$desc]);
            $novaId = $pdo->lastInsertId();
            header("Location: ver_os.php?id=$novaId&criada=1"); exit;
        }
        $mostrarForm = true;
    }
}

// Lista todas as OS do navegador
$todas = $pdo->prepare("SELECT * FROM nav_os WHERE navegador_id=? ORDER BY criado_em DESC");
$todas->execute([$u['id']]); $todas=$todas->fetchAll();

$statusLabel=['aberta'=>'Aberta','encerrada'=>'Aguard. Aprovação','aprovada_nav'=>'Aprovada','aprovada_admin'=>'Aprovada','concluida'=>'Concluída'];
$statusBadge=['aberta'=>'bg-primary','encerrada'=>'bg-warning text-dark','aprovada_nav'=>'bg-info','aprovada_admin'=>'bg-info','concluida'=>'bg-success'];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="bi bi-clipboard-list me-2" style="color:#fd7e14"></i>Minhas Ordens de Serviço</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNova">
    <i class="bi bi-plus-lg me-1"></i>Nova OS
  </button>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Form Nova OS -->
<div class="collapse mb-4 <?= $mostrarForm?'show':'' ?>" id="formNova">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold border-bottom">Nova Ordem de Serviço</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="acao" value="nova">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Título da OS *</label>
            <input type="text" name="titulo" class="form-control"
                   placeholder="Ex: Rally dos Sertões 2026, Etapa 1 – Fortaleza..." required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Descrição (opcional)</label>
            <input type="text" name="descricao" class="form-control" placeholder="Observações...">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Criar OS</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lista -->
<?php if($todas): ?>
<div class="row g-3">
<?php foreach($todas as $os): ?>
<div class="col-12 col-md-6 col-xl-4">
  <div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
      <span class="fw-bold small"><?= htmlspecialchars($os['titulo']) ?></span>
      <span class="badge <?= $statusBadge[$os['status']] ?>"><?= $statusLabel[$os['status']] ?></span>
    </div>
    <div class="card-body">
      <?php if($os['descricao']): ?>
      <p class="text-muted small mb-2"><?= htmlspecialchars($os['descricao']) ?></p>
      <?php endif; ?>
      <div class="small text-muted">
        <div><i class="bi bi-calendar3 me-1"></i>Criada em <?= date('d/m/Y',strtotime($os['criado_em'])) ?></div>
        <div class="mt-1"><i class="bi bi-clock me-1"></i>Atualizada <?= date('d/m/Y H:i',strtotime($os['atualizado_em'])) ?></div>
      </div>
      <div class="fw-bold text-success fs-5 mt-2">R$ <?= number_format($os['valor_total'],2,',','.') ?></div>
    </div>
    <div class="card-footer bg-white border-0 pt-0">
      <a href="ver_os.php?id=<?= $os['id'] ?>"
         class="btn btn-sm w-100 <?= $os['status']==='aberta'?'btn-primary':'btn-outline-secondary' ?>">
        <?= $os['status']==='aberta' ? '<i class="bi bi-pencil me-1"></i>Editar OS' : '<i class="bi bi-eye me-1"></i>Visualizar' ?>
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm text-center py-5 text-muted">
  <i class="bi bi-clipboard2-x d-block fs-1 mb-2"></i>
  Nenhuma OS criada ainda.
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
