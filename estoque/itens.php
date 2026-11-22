<?php
// estoque/itens.php – Histórico de saídas do estoque
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('estoque','admin');
$u = usuarioLogado();
$area   = 'estoque';
$titulo = 'Histórico de Saídas';

$filtroItem = isset($_GET['item']) ? (int)$_GET['item'] : 0;
$busca      = trim($_GET['busca'] ?? '');

// Itens para filtro
$todosItens = $pdo->query("SELECT id,nome,codigo FROM estoque WHERE ativo=1 ORDER BY nome")->fetchAll();

// Saídas
$where  = '1=1';
$params = [];
if ($filtroItem) { $where .= ' AND es.estoque_id=?'; $params[] = $filtroItem; }
if ($busca)      { $where .= ' AND (e.nome LIKE ? OR e.codigo LIKE ?)'; $params[] = "%$busca%"; $params[] = "%$busca%"; }

$stmt = $pdo->prepare("
    SELECT es.*,
           e.nome AS item_nome, e.codigo AS item_codigo, e.foto AS item_foto,
           os.titulo AS os_titulo, os.numero_cliente AS os_num,
           uc.nome AS cliente_nome,
           uu.nome AS usuario_nome
    FROM estoque_saidas es
    JOIN estoque e ON e.id=es.estoque_id
    LEFT JOIN ordens_servico os ON os.id=es.ordem_servico_id
    LEFT JOIN usuarios uc ON uc.id=es.cliente_id
    JOIN usuarios uu ON uu.id=es.usuario_id
    WHERE $where
    ORDER BY es.criado_em DESC");
$stmt->execute($params);
$saidas = $stmt->fetchAll();

// Totais
$totalSaidas = array_sum(array_column($saidas,'quantidade'));

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">
    <i class="bi bi-clock-history me-2 text-danger"></i>Histórico de Saídas
  </h5>
  <a href="index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-box-seam me-1"></i>Ver Estoque
  </a>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
      <input type="text" name="busca" class="form-control form-control-sm" style="max-width:200px"
             placeholder="Buscar item..." value="<?= htmlspecialchars($busca) ?>">
      <select name="item" class="form-select form-select-sm" style="max-width:250px" onchange="this.form.submit()">
        <option value="">Todos os itens</option>
        <?php foreach ($todosItens as $it): ?>
        <option value="<?= $it['id'] ?>" <?= $filtroItem==$it['id']?'selected':'' ?>>
          <?= htmlspecialchars($it['nome']) ?><?= $it['codigo']?' ('.$it['codigo'].')':'' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
      <?php if ($busca||$filtroItem): ?>
      <a href="itens.php" class="btn btn-sm btn-outline-secondary">Limpar</a>
      <?php endif; ?>
      <span class="text-muted small ms-auto">
        <?= count($saidas) ?> registro(s) · <?= $totalSaidas ?> unidade(s) saída
      </span>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <?php if ($saidas): ?>
    <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead>
        <tr>
          <th></th>
          <th>Item</th>
          <th class="text-center">Qtd Saída</th>
          <th>OS</th>
          <th>Cliente</th>
          <th>Registrado por</th>
          <th>Data</th>
          <th>Obs.</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($saidas as $s): ?>
      <tr>
        <td style="width:44px">
          <?php if ($s['item_foto']): ?>
          <img src="<?= BASE_URL ?>/uploads/estoque/<?= htmlspecialchars($s['item_foto']) ?>"
               style="width:38px;height:38px;object-fit:cover;border-radius:6px">
          <?php else: ?>
          <div style="width:38px;height:38px;background:#eee;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <i class="bi bi-box text-muted"></i>
          </div>
          <?php endif; ?>
        </td>
        <td>
          <div class="fw-semibold"><?= htmlspecialchars($s['item_nome']) ?></div>
          <?php if ($s['item_codigo']): ?>
          <div class="text-muted" style="font-size:.72rem">Cód: <?= htmlspecialchars($s['item_codigo']) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-center">
          <span class="badge bg-danger fs-6"><?= $s['quantidade'] ?></span>
        </td>
        <td>
          <?php if ($s['os_titulo']): ?>
          <span class="badge bg-dark me-1">OS #<?= $s['os_num'] ?></span>
          <span class="text-muted"><?= htmlspecialchars($s['os_titulo']) ?></span>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $s['cliente_nome'] ? htmlspecialchars($s['cliente_nome']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted"><?= htmlspecialchars($s['usuario_nome']) ?></td>
        <td class="text-muted text-nowrap"><?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></td>
        <td class="text-muted"><?= $s['observacao'] ? htmlspecialchars($s['observacao']) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox d-block fs-1 mb-2"></i>
      Nenhuma saída registrada<?= ($busca||$filtroItem) ? ' para este filtro' : '' ?>.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
