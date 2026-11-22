<?php
// cliente/ver_os.php – v8: carro, andamento (afazeres), itens com valor final e fotos por item
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/veiculos.php';
requireRole('cliente');
$u = usuarioLogado();
$area   = 'cliente';
$titulo = 'Detalhes da OS';

$osId = (int)($_GET['id'] ?? 0);
if (!$osId) { header('Location: ordens.php'); exit; }

$stmt = $pdo->prepare("
    SELECT os.*, um.nome AS mecanico_nome, v.marca, v.modelo, v.placa, v.ano, v.cor, v.foto_capa, v.foto_capa_thumb, r.nome AS rally_nome, r.id AS rid
    FROM ordens_servico os
    LEFT JOIN usuarios um ON um.id=os.mecanico_id
    LEFT JOIN veiculos v ON v.id=os.veiculo_id
    LEFT JOIN rallys r ON r.id=os.rally_id
    WHERE os.id=? AND os.cliente_id=?");
$stmt->execute([$osId, $u['id']]);
$os = $stmt->fetch();
if (!$os) { header('Location: ordens.php'); exit; }
$osAberta = !in_array($os['status'], ['concluida', 'cancelada'], true);

// Fase 2: o cliente pede serviços dentro da OS; o admin aprova ou recusa
$sucesso = $erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'pedir' && $osAberta) {
        $desc = trim($_POST['descricao'] ?? '');
        if (mb_strlen($desc) < 3) $erro = 'Descreva o que você quer que seja feito.';
        else {
            $pdo->prepare("INSERT INTO os_itens (ordem_servico_id,descricao,valor,origem,status_aprovacao,solicitado_por) VALUES (?,?,NULL,'cliente','pendente',?)")
                ->execute([$osId, mb_substr($desc, 0, 255), $u['id']]);
            $sucesso = 'Pedido enviado! A equipe vai analisar e você verá a resposta aqui.';
        }
    } elseif ($acao === 'cancelar_pedido') {
        $pdo->prepare("DELETE FROM os_itens WHERE id=? AND ordem_servico_id=? AND origem='cliente' AND solicitado_por=? AND status_aprovacao='pendente'")
            ->execute([(int)($_POST['item_id'] ?? 0), $osId, $u['id']]);
        $sucesso = 'Pedido cancelado.';
    }
    $_SESSION['flash_cli_os'] = [$sucesso, $erro];
    header('Location: ver_os.php?id=' . $osId . '#pedidos'); exit;
}
if (!empty($_SESSION['flash_cli_os'])) { [$sucesso, $erro] = $_SESSION['flash_cli_os']; unset($_SESSION['flash_cli_os']); }
$pedidos = $pdo->prepare("SELECT * FROM os_itens WHERE ordem_servico_id=? AND origem='cliente' ORDER BY criado_em DESC");
$pedidos->execute([$osId]); $pedidos = $pedidos->fetchAll();

$afazeres = osAfazeres($pdo, $osId); [$afT, $afF, $afPct] = osProgresso($afazeres);
$fotos = osFotos($pdo, $osId, true); $fotosItem = osFotosPorItem($fotos); $fotosAfazer = osFotosPorAfazer($fotos);

// Todos os itens aprovados aparecem — inclusive os sem valor (R$ 0,00). Pendentes/recusados não entram na conta.
$itens = $pdo->prepare("SELECT oi.*, e.foto AS estoque_foto FROM os_itens oi LEFT JOIN estoque e ON e.id=oi.estoque_id WHERE oi.ordem_servico_id=? AND oi.status_aprovacao='aprovado' ORDER BY oi.origem ASC, oi.criado_em ASC");
$itens->execute([$osId]); $itens = $itens->fetchAll();
$totalItens = array_sum(array_map(fn($i) => (float)($i['valor'] ?? 0) * $i['quantidade'], $itens));

$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
[$capaThumb, $capaFull] = veiculoCapa($os);

// Agrupa fotos para a galeria: por item (só itens já com valor), por afazer, gerais
$idsVisiveis = array_flip(array_column($itens, 'id'));
foreach ($fotos as &$f) $f['itens'] = array_values(array_filter($f['itens'], fn($it) => isset($idsVisiveis[$it['id']])));
unset($f);
$gruposFotos = [];
foreach ($fotos as $f) {
    if ($f['itens']) { foreach ($f['itens'] as $it) $gruposFotos['Peças e serviços'][$it['descricao']][] = $f; }
    elseif ($f['afazer_desc']) $gruposFotos['Afazeres'][$f['afazer_desc']][] = $f;
    else $gruposFotos['Gerais']['Fotos gerais da OS'][] = $f;
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="ordens.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    <h5 class="fw-bold mb-0 mt-1"><span class="badge bg-dark me-2">OS <?= htmlspecialchars($os['numero_os'] ?? $os['numero_cliente']) ?></span><?= htmlspecialchars($os['titulo']) ?></h5>
    <div class="text-muted small mt-1">
      <i class="bi bi-calendar3 me-1"></i>Aberta em <?= date('d/m/Y', strtotime($os['criado_em'])) ?>
      <?php if ($os['mecanico_nome']): ?> · <i class="bi bi-wrench me-1"></i><?= htmlspecialchars($os['mecanico_nome']) ?><?php endif; ?>
      <?php if (($os['local'] ?? 'oficina') === 'rally' && $os['rally_nome']): ?> · <a href="ver_corrida.php?id=<?= $os['rid'] ?>" class="text-decoration-none"><i class="bi bi-flag me-1"></i><?= htmlspecialchars($os['rally_nome']) ?></a><?php else: ?> · <i class="bi bi-wrench me-1"></i>Oficina<?php endif; ?>
      <?php if ($os['km_entrada'] !== null): ?> · <i class="bi bi-speedometer2 me-1"></i><?= kmFmt($os['km_entrada']) ?><?php endif; ?>
    </div>
  </div>
  <span class="badge badge-<?= $os['status'] ?> fs-6"><?= $statusLabel[$os['status']] ?></span>
</div>

<div class="row g-4">
  <div class="col-lg-5">
    <!-- Carro -->
    <?php if ($os['marca']): ?>
    <div class="card border-0 shadow-sm mb-4 overflow-hidden">
      <?php if ($capaThumb): ?>
      <div class="carro-capa" style="border-radius:0">
        <img src="<?= $capaFull ?>" style="cursor:pointer" data-galeria="capa" data-src="<?= $capaFull ?>" data-legenda="<?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?>">
        <div class="info"><div class="fw-bold fs-5"><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?></div>
          <div class="small opacity-75"><?= htmlspecialchars(trim(($os['ano'] ?? '').' · '.($os['cor'] ?? '').' · '.($os['placa'] ?? ''), ' ·')) ?></div></div>
      </div>
      <?php else: ?>
      <div class="card-body d-flex align-items-center gap-3">
        <div class="carro-capa vazia rounded" style="width:120px;aspect-ratio:4/3"><i class="bi bi-car-front fs-2"></i></div>
        <div><div class="fw-bold"><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?></div>
          <div class="small text-muted"><?= htmlspecialchars(trim(($os['ano'] ?? '').' · '.($os['cor'] ?? '').' · '.($os['placa'] ?? ''), ' ·')) ?></div>
          <a href="carros.php#v<?= (int)$os['veiculo_id'] ?>" class="small">Histórico do carro</a></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Andamento -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white border-bottom">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-ui-checks me-2 text-success"></i>Andamento dos trabalhos</span>
          <?php if ($afT): ?><span class="fw-bold text-success"><?= $afPct ?>%</span><?php endif; ?>
        </div>
        <?php if ($afT): ?><div class="progresso-os mt-2"><div style="width:<?= $afPct ?>%"></div></div><div class="small text-muted mt-1"><?= $afF ?> de <?= $afT ?> concluídos</div><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($afazeres): foreach ($afazeres as $a): $fa = $fotosAfazer[$a['id']] ?? []; ?>
        <div class="afazer <?= $a['concluido'] ? 'feito' : '' ?>">
          <span class="chk"><?= $a['concluido'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-hourglass-split text-warning"></i>' ?></span>
          <div class="flex-fill">
            <div class="txt"><?= htmlspecialchars($a['descricao']) ?></div>
            <?php if ($a['observacao']): ?><div class="small text-muted"><?= htmlspecialchars($a['observacao']) ?></div><?php endif; ?>
            <div class="meta"><?= $a['concluido'] ? 'Concluído em '.date('d/m H:i', strtotime($a['concluido_em'])) : 'Pendente' ?></div>
          </div>
          <?php if ($fa) echo osBotaoFoto($fa, null, $a['descricao'], 'ver'); ?>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted text-center py-4 mb-0 small"><i class="bi bi-hourglass d-block fs-3 mb-1"></i>A equipe ainda está planejando os trabalhos.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <!-- Pedidos do cliente -->
    <?php if ($osAberta || $pedidos): ?>
    <div class="card border-0 shadow-sm mb-4" id="pedidos">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-chat-square-text me-2 text-primary"></i>Seus pedidos para esta OS</div>
      <?php if ($pedidos): ?>
      <ul class="list-group list-group-flush small">
        <?php foreach ($pedidos as $p): ?>
        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
          <div>
            <div class="<?= $p['status_aprovacao'] === 'recusado' ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>"><?= htmlspecialchars($p['descricao']) ?></div>
            <div class="text-muted"><?= date('d/m/Y H:i', strtotime($p['criado_em'])) ?></div>
            <?php if ($p['status_aprovacao'] === 'recusado' && $p['motivo_recusa']): ?><div class="text-danger">Motivo: <?= htmlspecialchars($p['motivo_recusa']) ?></div><?php endif; ?>
          </div>
          <div class="text-end text-nowrap">
            <?= ['pendente' => '<span class="badge bg-warning text-dark">Em análise</span>', 'aprovado' => '<span class="badge bg-success">Aprovado</span>', 'recusado' => '<span class="badge bg-danger">Recusado</span>'][$p['status_aprovacao']] ?? '' ?>
            <?php if ($p['status_aprovacao'] === 'pendente'): ?>
            <form method="post" class="mt-1"><input type="hidden" name="acao" value="cancelar_pedido"><input type="hidden" name="item_id" value="<?= $p['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Cancelar este pedido?">cancelar</button></form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($osAberta): ?>
      <div class="card-body <?= $pedidos ? 'border-top' : '' ?>">
        <form method="post">
          <input type="hidden" name="acao" value="pedir">
          <label class="form-label small fw-semibold mb-1">Quer que a equipe faça mais alguma coisa no carro?</label>
          <div class="input-group">
            <input type="text" name="descricao" class="form-control" maxlength="255" placeholder="Ex.: verificar barulho na suspensão dianteira" required>
            <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Pedir</button>
          </div>
          <div class="form-text">A equipe analisa o pedido. Se aprovado, ele entra na lista de trabalhos desta OS.</div>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Itens e valores -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-receipt me-2 text-primary"></i>Peças, serviços e valores</div>
      <div class="card-body p-0">
        <?php if ($itens): ?>
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th style="width:44px"></th><th>Descrição</th><th class="text-center">Qtd</th><th class="text-end">Unit.</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($itens as $item): ?>
          <tr>
            <td><?= osBotaoFoto($fotosItem[$item['id']] ?? [], ($item['estoque_foto'] ?? null) ?: ($item['foto_item'] ?? null), $item['descricao'], 'ver') ?></td>
            <td><?= htmlspecialchars($item['descricao']) ?></td>
            <td class="text-center"><?= $item['quantidade'] ?></td>
            <?php $vu = (float)($item['valor'] ?? 0); ?>
            <td class="text-end text-muted small">R$ <?= number_format($vu,2,',','.') ?></td>
            <td class="text-end fw-semibold <?= $vu > 0 ? 'text-success' : 'text-muted' ?>">R$ <?= number_format($vu*$item['quantidade'],2,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light"><tr><td colspan="4" class="fw-bold text-end">Valor final:</td><td class="text-end fw-bold fs-6 text-success">R$ <?= number_format($totalItens,2,',','.') ?></td></tr></tfoot>
        </table>
        <?php else: ?>
        <p class="text-muted text-center py-4 mb-0"><i class="bi bi-hourglass-split d-block fs-3 mb-1"></i>Valores em preparação.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Fotos organizadas -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-images me-2 text-danger"></i>Fotos</span><span class="badge bg-secondary"><?= count($fotos) ?></span>
      </div>
      <div class="card-body">
        <?php if ($gruposFotos): foreach (['Peças e serviços','Afazeres','Gerais'] as $g): if (empty($gruposFotos[$g])) continue; ?>
          <div class="text-uppercase small fw-bold text-muted mb-2" style="letter-spacing:.5px"><?= $g ?></div>
          <?php foreach ($gruposFotos[$g] as $nome => $lista): ?>
          <div class="mb-3">
            <?php if ($g !== 'Gerais'): ?><div class="small fw-semibold mb-1"><?= htmlspecialchars($nome) ?></div><?php endif; ?>
            <div class="thumb-grid">
              <?php foreach ($lista as $f): ?>
              <div class="th"><img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="os" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($nome.($f['descricao'] ? ' · '.$f['descricao'] : '')) ?>"></div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endforeach; else: ?>
        <p class="text-muted text-center py-4 mb-0"><i class="bi bi-camera-slash d-block fs-2 mb-2"></i>Nenhuma foto disponível ainda.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php fotosModalGaleria(); ?>
<?php require_once '../includes/footer.php'; ?>
