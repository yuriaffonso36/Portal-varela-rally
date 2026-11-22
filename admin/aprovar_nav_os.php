<?php
// admin/aprovar_nav_os.php – Revisar e aprovar OS do navegador
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/aprovacoes.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Revisar OS Navegador';

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: navegadores.php'); exit; }

$stmt = $pdo->prepare("
    SELECT n.*, u.nome AS navegador_nome, p.nome AS piloto_nome, p.id AS pid
    FROM nav_os n
    JOIN usuarios u ON u.id=n.navegador_id
    JOIN usuarios p ON p.id=n.piloto_id
    WHERE n.id=?");
$stmt->execute([$osId]); $os=$stmt->fetch();
if (!$os) { header('Location: navegadores.php'); exit; }

$sucesso = $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Admin aprova pelo lado dele (Fase 2: regra única em includes/aprovacoes.php)
    if ($acao === 'ok_admin') {
        $sucesso = navOsOkAdmin($pdo, $osId, $u['id']);
    }
    // Fase 2: a quem cobrar (pode mudar até a OS ser concluída)
    elseif ($acao === 'cobrar_de' && $os['status'] !== 'concluida') {
        $cd = ($_POST['cobrar_de'] ?? '') === 'equipe' ? 'equipe' : 'piloto';
        $pdo->prepare("UPDATE nav_os SET cobrar_de=? WHERE id=?")->execute([$cd, $osId]);
        $sucesso = $cd === 'equipe' ? 'Agora cobra da EQUIPE (custo da corrida).' : 'Agora cobra do PILOTO (conta corrente dele).';
        $r = navOsTentarConcluir($pdo, $osId, $u['id']);
        if ($r === 'equipe') $sucesso .= ' A OS já tinha o OK do admin e foi concluída como custo da equipe.';
        elseif ($r === 'piloto') $sucesso .= ' A OS já tinha os dois OKs e foi lançada na conta do piloto.';
    }
    // Admin aprova pelo lado do cliente também
    elseif ($acao === 'ok_cliente') {
        $pdo->prepare("UPDATE nav_os SET ok_cliente=1 WHERE id=?")->execute([$osId]);
        $sucesso = 'Aprovação do cliente registrada pelo admin!';
    }
    // Reabrir para o navegador corrigir
    elseif ($acao === 'reabrir') {
        $pdo->prepare("UPDATE nav_os SET status='aberta',ok_admin=0,ok_cliente=0 WHERE id=?")->execute([$osId]);
        $sucesso = 'OS reaberta para o navegador.';
    }

    // Recarrega
    $stmt->execute([$osId]); $os=$stmt->fetch();

    // Verifica se já pode concluir (piloto: os dois OKs → CC; equipe: só admin)
    if ($acao === 'ok_cliente' && navOsTentarConcluir($pdo, $osId, $u['id']) === 'piloto') {
        $sucesso = '✅ Ambos aprovaram! OS lançada na conta corrente do piloto.';
    }
    $stmt->execute([$osId]); $os=$stmt->fetch();
}

$itens = $pdo->prepare("SELECT * FROM nav_os_itens WHERE nav_os_id=? ORDER BY criado_em ASC");
$itens->execute([$osId]); $itens=$itens->fetchAll();

$statusLabel = ['aberta'=>'Aberta','encerrada'=>'Encerrada – Aguard. Aprovação','aprovada_nav'=>'Aprovada','aprovada_admin'=>'Aprovada Admin','concluida'=>'Concluída'];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="navegadores.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    <h5 class="fw-bold mb-0 mt-1"><?= htmlspecialchars($os['titulo']) ?></h5>
    <div class="text-muted small mt-1">
      <i class="bi bi-compass me-1"></i><?= htmlspecialchars($os['navegador_nome']) ?>
      &nbsp;·&nbsp;<i class="bi bi-person me-1"></i>Piloto: <strong><?= htmlspecialchars($os['piloto_nome']) ?></strong>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-warning text-dark fs-6"><?= $statusLabel[$os['status']]??$os['status'] ?></span>
    <span class="fs-5 fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
  </div>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<?php $equipe = ($os['cobrar_de'] ?? 'piloto') === 'equipe'; ?>
<?php if($os['status']==='concluida'): ?>
<div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= $equipe ? 'OS aprovada e lançada como custo da equipe na corrida.' : 'OS aprovada e lançada na conta corrente do piloto.' ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <!-- Itens -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-list-check me-2 text-primary"></i>Itens Cobrados</span>
        <span class="badge bg-primary"><?= count($itens) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0 small">
          <thead><tr><th>Descrição</th><th class="text-end">Valor</th><th class="text-center">Comprovante</th></tr></thead>
          <tbody>
          <?php foreach($itens as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td class="text-end fw-semibold text-success">R$ <?= number_format($item['valor'],2,',','.') ?></td>
            <td class="text-center">
              <?php if($item['comprovante']): ?>
              <a href="<?= BASE_URL ?>/uploads/nav_comprovantes/<?= htmlspecialchars($item['comprovante']) ?>"
                 target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                <i class="bi bi-eye me-1"></i>Ver
              </a>
              <?php elseif($item['sem_comprovante']): ?>
              <span class="badge bg-secondary">Sem comprovante</span>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td class="fw-bold text-end">Total:</td>
              <td class="text-end fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <!-- Cobrar de -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom">Cobrar de</div>
      <div class="card-body">
        <?php if($os['status']==='concluida'): ?>
        <span class="badge fs-6 <?= $equipe?'bg-dark':'bg-info text-dark' ?>"><?= $equipe?'Equipe Varela':'Piloto · '.htmlspecialchars($os['piloto_nome']) ?></span>
        <?php else: ?>
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="acao" value="cobrar_de">
          <select name="cobrar_de" class="form-select form-select-sm">
            <option value="piloto" <?= !$equipe?'selected':'' ?>>Piloto · <?= htmlspecialchars($os['piloto_nome']) ?> (conta corrente)</option>
            <option value="equipe" <?= $equipe?'selected':'' ?>>Equipe Varela (custo da corrida)</option>
          </select>
          <button class="btn btn-sm btn-outline-primary">Salvar</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Status de aprovação -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold border-bottom">Status de Aprovação</div>
      <div class="card-body">
        <?php if(!$equipe): ?>
        <div class="d-flex justify-content-between align-items-center mb-3 p-3 rounded <?= $os['ok_cliente']?'bg-success bg-opacity-10':'bg-light' ?>">
          <div>
            <div class="fw-semibold">Piloto (Cliente)</div>
            <div class="small text-muted"><?= htmlspecialchars($os['piloto_nome']) ?></div>
          </div>
          <?php if($os['ok_cliente']): ?>
          <i class="bi bi-check-circle-fill text-success fs-3"></i>
          <?php else: ?>
          <i class="bi bi-circle text-muted fs-3"></i>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center p-3 rounded <?= $os['ok_admin']?'bg-success bg-opacity-10':'bg-light' ?>">
          <div>
            <div class="fw-semibold">Administrador</div>
          </div>
          <?php if($os['ok_admin']): ?>
          <i class="bi bi-check-circle-fill text-success fs-3"></i>
          <?php else: ?>
          <i class="bi bi-circle text-muted fs-3"></i>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Ações -->
    <?php if($os['status']==='encerrada'): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Ações</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if(!$os['ok_admin']): ?>
        <form method="post">
          <input type="hidden" name="acao" value="ok_admin">
          <button class="btn btn-success w-100"><i class="bi bi-check-lg me-2"></i>Aprovar (Admin)</button>
        </form>
        <?php endif; ?>
        <?php if(!$os['ok_cliente'] && !$equipe): ?>
        <form method="post">
          <input type="hidden" name="acao" value="ok_cliente">
          <button class="btn btn-outline-success w-100"
                  data-confirm="Aprovar pelo cliente também?">
            <i class="bi bi-person-check me-2"></i>Aprovar pelo Cliente
          </button>
        </form>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="acao" value="reabrir">
          <button class="btn btn-outline-warning w-100"
                  data-confirm="Reabrir esta OS para o navegador?">
            <i class="bi bi-arrow-counterclockwise me-2"></i>Reabrir para Correção
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
