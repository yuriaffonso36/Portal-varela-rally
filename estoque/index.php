<?php
// estoque/index.php – Página principal do estoque (listagem + adicionar)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/fotos.php';
require_once '../includes/estoque_mapa.php';
requireRole('estoque','admin');
$u = usuarioLogado();
$area   = 'estoque';
$titulo = 'Estoque';

$sucesso = $erro = '';
$busca   = trim($_GET['busca'] ?? '');
$filtro  = $_GET['filtro'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    // Novo item
    if ($acao === 'novo') {
        $codigo   = trim($_POST['codigo']    ?? '');
        $nome     = trim($_POST['nome']      ?? '');
        $desc     = trim($_POST['descricao'] ?? '');
        $condicao = $_POST['condicao']       ?? 'novo';
        $vcusto   = (float)str_replace(['.',',' ],['','.'], $_POST['valor_custo']  ?? '0');
        $vvenda   = (float)str_replace(['.',',' ],['','.'], $_POST['valor_venda']  ?? '0');
        $qtd      = (int)($_POST['quantidade']    ?? 0);
        $minimo   = max(1,(int)($_POST['estoque_minimo'] ?? 1));
        // Fase 4: localização + referência BRP
        $estId = (int)($_POST['estante_id'] ?? 0) ?: null; $prat = $estId ? ((int)($_POST['prateleira'] ?? 0) ?: null) : null;
        $posicao = trim($_POST['posicao'] ?? '') ?: null; $pn = strtoupper(trim($_POST['part_number'] ?? '')) ?: null;
        [$imgRef, $eRef] = estSalvarImagemRef($_FILES['imagem_ref'] ?? []); if ($eRef) $erro = $eRef;

        if (!$nome) { $erro = 'Informe o nome do item.'; }
        else {
            $foto = null;
            if (!empty($_FILES['foto']['name'])) {
                // v8: foto reduzida + miniatura (uploads/estoque/ e uploads/estoque/thumbs/)
                $r = fotoSalvarUpload($_FILES['foto'], 'estoque', 'est');
                if (!isset($r['erro'])) $foto = basename($r['caminho']); else $erro = $r['erro'];
            }
            $pdo->prepare("INSERT INTO estoque (codigo,nome,descricao,condicao,valor_custo,valor_venda,quantidade,estoque_minimo,foto,estante_id,prateleira,posicao,part_number,imagem_ref) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$codigo,$nome,$desc,$condicao,$vcusto,$vvenda,$qtd,$minimo,$foto,$estId,$prat,$posicao,$pn,$imgRef]);
            $sucesso = "Item '$nome' cadastrado!";
        }
    }

    // Editar item
    elseif ($acao === 'editar' && $id) {
        $codigo   = trim($_POST['codigo']    ?? '');
        $nome     = trim($_POST['nome']      ?? '');
        $desc     = trim($_POST['descricao'] ?? '');
        $condicao = $_POST['condicao']       ?? 'novo';
        $vcusto   = (float)str_replace(['.',',' ],['','.'], $_POST['valor_custo']  ?? '0');
        $vvenda   = (float)str_replace(['.',',' ],['','.'], $_POST['valor_venda']  ?? '0');
        $qtd      = (int)($_POST['quantidade']    ?? 0);
        $minimo   = max(1,(int)($_POST['estoque_minimo'] ?? 1));
        $fotoAtual= $_POST['foto_atual'] ?? null;
        $estId = (int)($_POST['estante_id'] ?? 0) ?: null; $prat = $estId ? ((int)($_POST['prateleira'] ?? 0) ?: null) : null;
        $posicao = trim($_POST['posicao'] ?? '') ?: null; $pn = strtoupper(trim($_POST['part_number'] ?? '')) ?: null;
        $refAtual = $pdo->prepare("SELECT imagem_ref FROM estoque WHERE id=?"); $refAtual->execute([$id]); $imgRef = $refAtual->fetchColumn() ?: null;
        [$novaRef, $eRef] = estSalvarImagemRef($_FILES['imagem_ref'] ?? []);
        if ($eRef) $erro = $eRef;
        elseif ($novaRef) { fotoApagar($imgRef, $imgRef ? str_replace('estoque_ref/', 'estoque_ref/thumbs/', $imgRef) : null); $imgRef = $novaRef; }
        if (!empty($_POST['remover_ref']) && !$novaRef && $imgRef) { fotoApagar($imgRef, str_replace('estoque_ref/', 'estoque_ref/thumbs/', $imgRef)); $imgRef = null; }

        $foto = $fotoAtual;
        if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error']===UPLOAD_ERR_OK) {
            $r = fotoSalvarUpload($_FILES['foto'], 'estoque', 'est');
            if (!isset($r['erro'])) {
                $foto = basename($r['caminho']);
                // só apaga a foto antiga se nenhum item de OS ainda apontar para ela
                $uso = $pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE foto_item=?"); $uso->execute([(string)$fotoAtual]);
                if ($fotoAtual && !$uso->fetchColumn()) fotoApagar('estoque/'.$fotoAtual, 'estoque/thumbs/'.$fotoAtual);
            } else $erro = $r['erro'];
        }
        $pdo->prepare("UPDATE estoque SET codigo=?,nome=?,descricao=?,condicao=?,valor_custo=?,valor_venda=?,quantidade=?,estoque_minimo=?,foto=?,estante_id=?,prateleira=?,posicao=?,part_number=?,imagem_ref=? WHERE id=?")
            ->execute([$codigo,$nome,$desc,$condicao,$vcusto,$vvenda,$qtd,$minimo,$foto,$estId,$prat,$posicao,$pn,$imgRef,$id]);
        $sucesso = 'Item atualizado!';
    }

    // Ajuste rápido de quantidade
    elseif ($acao === 'ajuste_qtd' && $id) {
        $ajuste = (int)($_POST['ajuste'] ?? 0);
        $pdo->prepare("UPDATE estoque SET quantidade=GREATEST(0,quantidade+?) WHERE id=?")->execute([$ajuste,$id]);
        $sucesso = 'Quantidade ajustada!';
    }

    // Excluir individual
    elseif ($acao === 'inativar' && $id) {
        // v8: a foto é mantida — itens já lançados em OS continuam mostrando a foto do estoque
        $pdo->prepare("UPDATE estoque SET ativo=0 WHERE id=?")->execute([$id]);
        $sucesso = 'Item removido.';
    }

    // Excluir em lote
    elseif ($acao === 'excluir_lote') {
        $ids = $_POST['selecionados'] ?? [];
        $n   = 0;
        foreach ($ids as $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            $pdo->prepare("UPDATE estoque SET ativo=0 WHERE id=?")->execute([$sid]);
            $n++;
        }
        $sucesso = "$n item(ns) removido(s).";
    }
}

// Editar
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM estoque WHERE id=? AND ativo=1");
    $stmt->execute([(int)$_GET['editar']]);
    $editando = $stmt->fetch();
}

// Listar
$where  = "WHERE ativo=1";
$params = [];
if ($busca)             { $where .= " AND (nome LIKE ? OR codigo LIKE ? OR part_number LIKE ?)"; $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%"; }
if ($filtro==='semlugar') { $where .= " AND estante_id IS NULL"; }
if ($filtro==='baixo')  { $where .= " AND quantidade>0 AND quantidade<=estoque_minimo"; }
if ($filtro==='zerado') { $where .= " AND quantidade<=0"; }
if ($filtro==='usado')  { $where .= " AND condicao='usado'"; }

$stmt = $pdo->prepare("SELECT * FROM estoque $where ORDER BY nome ASC");
$stmt->execute($params);
$itens = $stmt->fetchAll();

$estantes = estEstantes($pdo); $estantesPorId = array_column($estantes, null, 'id');
$totalItens  = $pdo->query("SELECT COUNT(*) FROM estoque WHERE ativo=1")->fetchColumn();
$totalQtd    = $pdo->query("SELECT COALESCE(SUM(quantidade),0) FROM estoque WHERE ativo=1")->fetchColumn();
$semEstoque  = $pdo->query("SELECT COUNT(*) FROM estoque WHERE ativo=1 AND quantidade<=0")->fetchColumn();
$estoqueBaixo= $pdo->query("SELECT COUNT(*) FROM estoque WHERE ativo=1 AND quantidade>0 AND quantidade<=estoque_minimo")->fetchColumn();

require_once '../includes/header.php';
?>

<!-- Mini stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm p-3 text-center">
      <div class="fs-3 fw-bold" style="color:#6f42c1"><?= $totalItens ?></div>
      <div class="text-muted small">Itens</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm p-3 text-center">
      <div class="fs-3 fw-bold text-primary"><?= $totalQtd ?></div>
      <div class="text-muted small">Unidades</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm p-3 text-center">
      <div class="fs-3 fw-bold text-warning"><?= $estoqueBaixo ?></div>
      <div class="text-muted small">Estoque Baixo</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm p-3 text-center">
      <div class="fs-3 fw-bold text-danger"><?= $semEstoque ?></div>
      <div class="text-muted small">Zerados</div>
    </div>
  </div>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<!-- Barra de ações -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-box-seam me-2" style="color:#6f42c1"></i>Itens em Estoque</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovo">
    <i class="bi bi-plus-lg me-1"></i>Adicionar Item
  </button>
</div>

<!-- Form Novo Item -->
<div class="collapse mb-4 <?= $erro?'show':'' ?>" id="formNovo">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold border-bottom">Novo Item</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="acao" value="novo">
        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label">Código</label>
            <input type="text" name="codigo" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Nome *</label>
            <input type="text" name="nome" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Descrição</label>
            <input type="text" name="descricao" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Condição</label>
            <select name="condicao" class="form-select">
              <option value="novo">Novo</option>
              <option value="usado">Usado</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Valor Custo (R$)</label>
            <input type="text" name="valor_custo" class="form-control" placeholder="0,00">
          </div>
          <div class="col-md-2">
            <label class="form-label">Valor Venda (R$)</label>
            <input type="text" name="valor_venda" class="form-control" placeholder="0,00">
          </div>
          <div class="col-md-2">
            <label class="form-label">Quantidade</label>
            <input type="number" name="quantidade" class="form-control" value="0" min="0">
          </div>
          <div class="col-md-2">
            <label class="form-label">Estoque Mínimo</label>
            <input type="number" name="estoque_minimo" class="form-control" value="1" min="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Foto</label>
            <input type="file" name="foto" class="form-control" accept="image/*" data-reduzir>
          </div>
          <?= estCamposLocal($estantes) ?>
          <div class="col-md-6"><label class="form-label">Imagem de referência BRP <span class="text-muted small">(desenho do catálogo, opc.)</span></label>
            <input type="file" name="imagem_ref" class="form-control" accept="image/*" data-reduzir></div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Cadastrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Busca e filtros -->
<form method="get" id="formFiltro">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <input type="text" name="busca" class="form-control form-control-sm" style="max-width:220px"
               placeholder="Buscar..." value="<?= htmlspecialchars($busca) ?>">
        <select name="filtro" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
          <option value="" <?= !$filtro?'selected':'' ?>>Todos</option>
          <option value="baixo"  <?= $filtro==='baixo'?'selected':'' ?>>Estoque baixo</option>
          <option value="zerado" <?= $filtro==='zerado'?'selected':'' ?>>Zerados</option>
          <option value="usado"  <?= $filtro==='usado'?'selected':'' ?>>Usados</option>
          <option value="semlugar" <?= $filtro==='semlugar'?'selected':'' ?>>Sem lugar na estante</option>
        </select>
        <a href="mapa.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-grid-3x3-gap me-1"></i>Mapa do estoque</a>
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        <?php if ($busca||$filtro): ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Limpar</a>
        <?php endif; ?>
        <span class="text-muted small ms-auto"><?= count($itens) ?> item(ns)</span>
      </div>
    </div>
  </div>
</form>

<!-- Ações em lote -->
<form method="post" id="formLote">
  <input type="hidden" name="acao" value="excluir_lote">

  <div class="card border-0 shadow-sm mb-2 d-none" id="barreLote">
    <div class="card-body py-2 d-flex align-items-center gap-3">
      <span class="fw-semibold small" id="totalSelecionados">0 selecionado(s)</span>
      <button type="submit" class="btn btn-danger btn-sm"
              data-confirm="Remover todos os itens selecionados?">
        <i class="bi bi-trash3 me-1"></i>Excluir Selecionados
      </button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="desmarcarTodos()">Cancelar</button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="card border-0 shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle small">
        <thead>
          <tr>
            <th><input type="checkbox" id="checkTodos" onchange="toggleTodos(this)"></th>
            <th></th>
            <th>Código</th>
            <th>Nome</th>
            <th>Condição</th>
            <th class="text-center">Qtd / Mín</th>
            <th class="text-end">Custo</th>
            <th class="text-end">Venda</th>
            <th class="text-end">Margem</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($itens as $item):
            $margem    = $item['valor_custo']>0 ? (($item['valor_venda']-$item['valor_custo'])/$item['valor_custo'])*100 : 0;
            $alertaQtd = $item['quantidade'] <= 0 ? 'bg-danger' : ($item['quantidade'] <= $item['estoque_minimo'] ? 'bg-warning text-dark' : 'bg-success');
        ?>
        <tr>
          <td><input type="checkbox" name="selecionados[]" value="<?= $item['id'] ?>" class="check-item" onchange="atualizarLote()"></td>
          <td style="width:48px">
            <?php if ($item['foto']): ?>
            <img src="<?= BASE_URL ?>/uploads/estoque/<?= is_file(UPLOAD_DIR.'estoque/thumbs/'.$item['foto'])?'thumbs/':'' ?><?= htmlspecialchars($item['foto']) ?>"
                 style="width:42px;height:42px;object-fit:cover;border-radius:7px;cursor:pointer"
                 onclick="verFoto('<?= BASE_URL ?>/uploads/estoque/<?= htmlspecialchars($item['foto']) ?>','<?= htmlspecialchars($item['nome'],ENT_QUOTES) ?>')">
            <?php else: ?>
            <div style="width:42px;height:42px;background:#eee;border-radius:7px;display:flex;align-items:center;justify-content:center">
              <i class="bi bi-image text-muted"></i>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= htmlspecialchars($item['codigo']??'—') ?></td>
          <td>
            <div class="fw-semibold"><?= htmlspecialchars($item['nome']) ?></div>
            <div class="d-flex gap-1 flex-wrap align-items-center mt-1"><?= estLocalBadge($item, $estantesPorId) ?>
              <?php if (!empty($item['part_number'])): ?><span class="badge bg-dark-subtle text-dark border">PN <?= htmlspecialchars($item['part_number']) ?></span><?= estBotoesBrp($item['part_number']) ?><?php endif; ?>
              <?php if (!empty($item['imagem_ref'])): ?><button type="button" class="btn btn-sm btn-outline-dark py-0" title="Imagem de referência BRP" onclick="verFoto('<?= fotoUrl($item['imagem_ref']) ?>','<?= htmlspecialchars('Referência: ' . $item['nome'], ENT_QUOTES) ?>')"><i class="bi bi-diagram-3"></i></button><?php endif; ?></div>
            <?php if ($item['descricao']): ?>
            <div class="text-muted" style="font-size:.73rem"><?= htmlspecialchars($item['descricao']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $item['condicao']==='novo'?'bg-primary':'bg-secondary' ?>">
              <?= ucfirst($item['condicao']) ?>
            </span>
          </td>
          <td class="text-center">
            <span class="badge <?= $alertaQtd ?> fs-6"><?= $item['quantidade'] ?></span>
            <div class="text-muted" style="font-size:.7rem">mín: <?= $item['estoque_minimo'] ?></div>
            <div class="d-flex gap-1 justify-content-center mt-1">
              <form method="post" class="d-inline">
                <input type="hidden" name="acao" value="ajuste_qtd">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <input type="hidden" name="ajuste" value="-1">
                <button class="btn btn-outline-danger btn-sm py-0 px-1">−</button>
              </form>
              <form method="post" class="d-inline">
                <input type="hidden" name="acao" value="ajuste_qtd">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <input type="hidden" name="ajuste" value="1">
                <button class="btn btn-outline-success btn-sm py-0 px-1">+</button>
              </form>
            </div>
          </td>
          <td class="text-end">R$ <?= number_format($item['valor_custo'],2,',','.') ?></td>
          <td class="text-end fw-semibold text-success">R$ <?= number_format($item['valor_venda'],2,',','.') ?></td>
          <td class="text-end <?= $margem>=20?'text-success':'text-warning' ?>">
            <?= number_format($margem,1) ?>%
          </td>
          <td class="text-nowrap">
            <a href="?editar=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" class="d-inline">
              <input type="hidden" name="acao" value="inativar">
              <input type="hidden" name="id" value="<?= $item['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"
                      data-confirm="Remover '<?= htmlspecialchars($item['nome'],ENT_QUOTES) ?>'?">
                <i class="bi bi-trash3"></i>
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
</form>

<!-- Modal Editar -->
<?php if ($editando): ?>
<div class="modal show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar — <?= htmlspecialchars($editando['nome']) ?></h5>
        <a href="index.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="editar">
          <input type="hidden" name="id" value="<?= $editando['id'] ?>">
          <input type="hidden" name="foto_atual" value="<?= htmlspecialchars($editando['foto']??'') ?>">
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Código</label>
              <input type="text" name="codigo" class="form-control" value="<?= htmlspecialchars($editando['codigo']??'') ?>"></div>
            <div class="col-md-5"><label class="form-label">Nome *</label>
              <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editando['nome']) ?>" required></div>
            <div class="col-md-4"><label class="form-label">Condição</label>
              <select name="condicao" class="form-select">
                <option value="novo"  <?= $editando['condicao']==='novo'?'selected':'' ?>>Novo</option>
                <option value="usado" <?= $editando['condicao']==='usado'?'selected':'' ?>>Usado</option>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Descrição</label>
              <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($editando['descricao']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label">Valor Custo (R$)</label>
              <input type="text" name="valor_custo" class="form-control" value="<?= number_format($editando['valor_custo'],2,',','.') ?>"></div>
            <div class="col-md-3"><label class="form-label">Valor Venda (R$)</label>
              <input type="text" name="valor_venda" class="form-control" value="<?= number_format($editando['valor_venda'],2,',','.') ?>"></div>
            <div class="col-md-3"><label class="form-label">Quantidade</label>
              <input type="number" name="quantidade" class="form-control" value="<?= $editando['quantidade'] ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Estoque Mínimo</label>
              <input type="number" name="estoque_minimo" class="form-control" value="<?= $editando['estoque_minimo'] ?>" min="1"></div>
            <div class="col-md-6">
              <label class="form-label">Foto</label>
              <?php if ($editando['foto']): ?>
              <div class="mb-2">
                <img src="<?= BASE_URL ?>/uploads/estoque/<?= htmlspecialchars($editando['foto']) ?>"
                     style="height:60px;border-radius:6px;object-fit:cover">
              </div>
              <?php endif; ?>
              <input type="file" name="foto" class="form-control form-control-sm" accept="image/*" data-reduzir>
            </div>
            <?= estCamposLocal($estantes, $editando) ?>
            <div class="col-md-6"><label class="form-label">Imagem de referência BRP</label>
              <?php if (!empty($editando['imagem_ref'])): ?><div class="mb-2 d-flex align-items-center gap-2"><img src="<?= fotoUrl($editando['imagem_ref']) ?>" style="height:60px;border-radius:6px;object-fit:contain;background:#fff;border:1px solid #ddd">
                <label class="small"><input type="checkbox" name="remover_ref" value="1"> remover</label></div><?php endif; ?>
              <input type="file" name="imagem_ref" class="form-control form-control-sm" accept="image/*" data-reduzir></div>
          </div>
          <div class="mt-3 text-end">
            <a href="index.php" class="btn btn-secondary me-2">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal foto -->
<div class="modal fade" id="modalFoto" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 bg-black">
      <div class="modal-header border-0 bg-black">
        <h6 class="modal-title text-white" id="modalFotoDesc"></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-2">
        <img id="modalFotoImg" src="" class="img-fluid rounded" style="max-height:70vh">
      </div>
    </div>
  </div>
</div>

<script>
function verFoto(src, desc) {
    document.getElementById('modalFotoImg').src = src;
    document.getElementById('modalFotoDesc').textContent = desc;
    new bootstrap.Modal(document.getElementById('modalFoto')).show();
}

function toggleTodos(cb) {
    document.querySelectorAll('.check-item').forEach(c => c.checked = cb.checked);
    atualizarLote();
}

function desmarcarTodos() {
    document.querySelectorAll('.check-item, #checkTodos').forEach(c => c.checked = false);
    atualizarLote();
}

function atualizarLote() {
    const selecionados = document.querySelectorAll('.check-item:checked').length;
    const barra = document.getElementById('barreLote');
    document.getElementById('totalSelecionados').textContent = selecionados + ' selecionado(s)';
    barra.classList.toggle('d-none', selecionados === 0);
}
</script>

<?php estScriptLocal(); ?>
<?php require_once '../includes/footer.php'; ?>
