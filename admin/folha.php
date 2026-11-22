<?php
// admin/folha.php – Fase 3: folha de pagamento — salário mensal + diárias (corrida ou oficina) + adicionais − descontos
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/financeiro.php';
requireRole('admin');
$u = usuarioLogado(); $area = 'admin'; $titulo = 'Folha de pagamento';

$abas = ['folha' => ['Folha do mês', 'bi-cash-stack'], 'diarias' => ['Diárias', 'bi-calendar-day'], 'pessoas' => ['Pessoas e valores', 'bi-people']];
$aba = $_GET['aba'] ?? 'folha'; if (!isset($abas[$aba])) $aba = 'folha';
$comp = finComp($_GET['comp'] ?? null);
$volta = function (array $x = []) use (&$aba, &$comp) { return 'folha.php?' . http_build_query(array_merge(['aba' => $aba, 'comp' => $comp], $x)); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $msg = ''; $err = ''; $id = (int)($_POST['id'] ?? 0);
    switch ($acao) {
    case 'gerar':
        $n = folhaGerar($pdo, $comp); $msg = $n ? "$n pessoa(s) incluída(s) na folha de " . finCompLabel($comp) . '.' : 'Todos já estavam na folha deste mês.'; break;
    case 'salvar_linha':
        $pdo->prepare("UPDATE folha_pagamentos SET salario=?, adicionais=?, descontos=?, observacao=? WHERE id=? AND status='aberto'")
            ->execute([aprovNum($_POST['salario'] ?? '') ?? 0, aprovNum($_POST['adicionais'] ?? '') ?? 0, aprovNum($_POST['descontos'] ?? '') ?? 0, trim($_POST['observacao'] ?? '') ?: null, $id]);
        folhaRecalcular($pdo, $id); $msg = 'Linha atualizada.'; break;
    case 'pagar':
        [$arq, $e] = finSalvarArquivo($_FILES['comprovante'] ?? [], 'folha', 'folha' . $id);
        if ($e) { $err = $e; break; }
        $err = folhaPagar($pdo, $id, $u['id'], $arq, $_POST['forma_pagamento'] ?? 'pix') ?? '';
        if (!$err) $msg = 'Pagamento registrado e lançado nas despesas da oficina.'; elseif ($arq) fotoApagar($arq);
        break;
    case 'desfazer':
        $f = $pdo->prepare("SELECT * FROM folha_pagamentos WHERE id=? AND status='pago'"); $f->execute([$id]); $f = $f->fetch();
        if ($f) {
            if ($f['despesa_id']) $pdo->prepare("DELETE FROM despesas WHERE id=? AND origem='folha'")->execute([$f['despesa_id']]);
            fotoApagar($f['comprovante']);
            $pdo->prepare("UPDATE folha_pagamentos SET status='aberto', pago_em=NULL, pago_por=NULL, comprovante=NULL, despesa_id=NULL WHERE id=?")->execute([$id]);
            folhaRecalcular($pdo, $id); $msg = 'Pagamento desfeito; a linha voltou para "em aberto".';
        } break;
    case 'remover_linha':
        $pdo->prepare("DELETE FROM folha_pagamentos WHERE id=? AND status='aberto'")->execute([$id]); $msg = 'Pessoa retirada da folha deste mês.'; break;

    case 'diaria':
        $uid = (int)($_POST['usuario_id'] ?? 0); $data = finData($_POST['data'] ?? '') ?? date('Y-m-d');
        $qtd = max(0.5, min(31, (float)str_replace(',', '.', $_POST['quantidade'] ?? '1'))); $vu = aprovNum($_POST['valor_unit'] ?? '');
        if (!$uid || !$vu) { $err = 'Escolha a pessoa e informe o valor da diária.'; break; }
        $c = substr($data, 0, 7);
        if (folhaPaga($pdo, $uid, $c)) { $err = 'A folha de ' . finCompLabel($c) . ' desta pessoa já foi paga. Lance a diária no mês seguinte ou desfaça o pagamento.'; break; }
        $pdo->prepare("INSERT INTO folha_diarias (usuario_id,rally_id,data,quantidade,valor_unit,observacao,lancado_por) VALUES (?,?,?,?,?,?,?)")
            ->execute([$uid, (int)($_POST['rally_id'] ?? 0) ?: null, $data, $qtd, $vu, trim($_POST['observacao'] ?? '') ?: null, $u['id']]);
        $l = $pdo->prepare("SELECT id FROM folha_pagamentos WHERE usuario_id=? AND competencia=?"); $l->execute([$uid, $c]);
        if ($lid = $l->fetchColumn()) folhaRecalcular($pdo, (int)$lid);
        $msg = 'Diária lançada: ' . str_replace('.', ',', (string)$qtd) . ' × ' . finR($vu); $comp = $c; break;
    case 'diaria_del':
        $d = $pdo->prepare("SELECT * FROM folha_diarias WHERE id=?"); $d->execute([$id]); $d = $d->fetch();
        if (!$d) break;
        $c = substr($d['data'], 0, 7);
        if (folhaPaga($pdo, (int)$d['usuario_id'], $c)) { $err = 'Esta diária já está numa folha paga.'; break; }
        $pdo->prepare("DELETE FROM folha_diarias WHERE id=?")->execute([$id]);
        $l = $pdo->prepare("SELECT id FROM folha_pagamentos WHERE usuario_id=? AND competencia=?"); $l->execute([$d['usuario_id'], $c]);
        if ($lid = $l->fetchColumn()) folhaRecalcular($pdo, (int)$lid);
        $msg = 'Diária removida.'; break;

    case 'pessoa':
        $pdo->prepare("UPDATE usuarios SET na_folha=?, salario_mensal=?, valor_diaria=? WHERE id=? AND tipo<>'cliente'")
            ->execute([!empty($_POST['na_folha']) ? 1 : 0, aprovNum($_POST['salario_mensal'] ?? ''), aprovNum($_POST['valor_diaria'] ?? ''), $id]);
        $msg = 'Valores salvos. Valem para as próximas folhas geradas.'; break;
    }
    $_SESSION['flash_folha'] = [$msg, $err];
    header('Location: ' . $volta()); exit;
}
[$sucesso, $erro] = $_SESSION['flash_folha'] ?? ['', '']; unset($_SESSION['flash_folha']);

// Mantém as linhas em aberto atualizadas com as diárias lançadas
$abertas = $pdo->prepare("SELECT id FROM folha_pagamentos WHERE competencia=? AND status='aberto'"); $abertas->execute([$comp]);
foreach ($abertas->fetchAll(PDO::FETCH_COLUMN) as $fid) folhaRecalcular($pdo, (int)$fid);

$linhas = $pdo->prepare("SELECT f.*, u.nome, u.tipo, u.funcao, u.pix_tipo, u.pix_chave, u.dados_bancarios FROM folha_pagamentos f JOIN usuarios u ON u.id=f.usuario_id WHERE f.competencia=? ORDER BY f.status, u.nome");
$linhas->execute([$comp]); $linhas = $linhas->fetchAll();
$pessoas = $pdo->query("SELECT id, nome, tipo, funcao, na_folha, salario_mensal, valor_diaria, ativo, pix_chave, sem_acesso FROM usuarios WHERE tipo IN ('mecanico','estoque','equipe','navegador','admin') ORDER BY ativo DESC, na_folha DESC, nome")->fetchAll();
$naFolhaSemLinha = (int)$pdo->query("SELECT COUNT(*) FROM usuarios u WHERE u.na_folha=1 AND u.ativo=1 AND NOT EXISTS (SELECT 1 FROM folha_pagamentos f WHERE f.usuario_id=u.id AND f.competencia=" . $pdo->quote($comp) . ")")->fetchColumn();
$diarias = $pdo->prepare("SELECT d.*, u.nome, r.nome AS rally_nome FROM folha_diarias d JOIN usuarios u ON u.id=d.usuario_id LEFT JOIN rallys r ON r.id=d.rally_id WHERE DATE_FORMAT(d.data,'%Y-%m')=? ORDER BY d.data DESC, u.nome");
$diarias->execute([$comp]); $diarias = $diarias->fetchAll();
$rallys = $pdo->query("SELECT id, nome FROM rallys ORDER BY data_inicio DESC LIMIT 40")->fetchAll();
$tipoLabel = ['mecanico' => 'Mecânico', 'estoque' => 'Estoque', 'equipe' => 'Equipe', 'navegador' => 'Navegador', 'admin' => 'Admin'];
$tot = ['liquido' => array_sum(array_column($linhas, 'liquido')), 'pago' => array_sum(array_map(fn($l) => $l['status'] === 'pago' ? (float)$l['liquido'] : 0, $linhas))];
$tot['aberto'] = $tot['liquido'] - $tot['pago'];
$dec = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, ',', '.');

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div><a href="oficina.php?comp=<?= $comp ?>" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Oficina</a>
    <h5 class="fw-bold mb-0 mt-1"><i class="bi bi-people me-2 text-danger"></i>Folha de pagamento</h5></div>
  <div class="d-flex align-items-center gap-1">
    <a href="<?= htmlspecialchars($volta(['comp' => finCompMais($comp, -1)])) ?>" class="btn btn-sm btn-light border"><i class="bi bi-chevron-left"></i></a>
    <span class="fw-semibold px-2"><?= finCompLabel($comp) ?></span>
    <a href="<?= htmlspecialchars($volta(['comp' => finCompMais($comp, 1)])) ?>" class="btn btn-sm btn-light border"><i class="bi bi-chevron-right"></i></a>
  </div>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($abas as $k => [$l, $ic]): ?><li class="nav-item"><a class="nav-link <?= $aba === $k ? 'active fw-semibold' : '' ?>" href="?aba=<?= $k ?>&comp=<?= $comp ?>"><i class="bi <?= $ic ?> me-1"></i><?= $l ?></a></li><?php endforeach; ?>
</ul>

<?php if ($aba === 'folha'): ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Total da folha</div><div class="fs-4 fw-bold"><?= finR($tot['liquido']) ?></div></div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Pago</div><div class="fs-4 fw-bold text-success"><?= finR($tot['pago']) ?></div></div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body py-3"><div class="small text-muted">Em aberto</div><div class="fs-4 fw-bold text-danger"><?= finR($tot['aberto']) ?></div></div></div></div>
</div>
<?php if ($naFolhaSemLinha || !$linhas): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
  <span><?= $linhas ? "$naFolhaSemLinha pessoa(s) marcada(s) na folha ainda não entraram em " . finCompLabel($comp) . '.' : 'A folha de ' . finCompLabel($comp) . ' ainda não foi gerada.' ?>
    <?php if (!array_filter($pessoas, fn($p) => $p['na_folha'])): ?> Primeiro marque quem está na folha em <a href="?aba=pessoas&comp=<?= $comp ?>">Pessoas e valores</a>.<?php endif; ?></span>
  <form method="post" class="m-0"><input type="hidden" name="acao" value="gerar"><button class="btn btn-sm btn-dark"><i class="bi bi-magic me-1"></i>Gerar folha de <?= finCompLabel($comp) ?></button></form>
</div>
<?php endif; ?>

<?php foreach ($linhas as $l): $pago = $l['status'] === 'pago'; ?>
<div class="card border-0 shadow-sm mb-3 <?= $pago ? 'border-start border-success border-3' : '' ?>">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><strong><?= htmlspecialchars($l['nome']) ?></strong> <span class="text-muted small"><?= htmlspecialchars($l['funcao'] ?: ($tipoLabel[$l['tipo']] ?? '')) ?></span>
      <?php if ($l['pix_chave']): ?><span class="small ms-2">PIX: <code><?= htmlspecialchars(pixFmt($l['pix_tipo'], $l['pix_chave'])) ?></code>
        <button type="button" class="btn btn-sm btn-link py-0" onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($l['pix_chave']), ENT_QUOTES) ?>);this.textContent='copiado'"><i class="bi bi-copy"></i></button></span>
      <?php else: ?><span class="badge bg-warning text-dark ms-2">sem PIX</span><?php endif; ?></div>
    <div class="text-end"><span class="fs-5 fw-bold <?= $pago ? 'text-success' : '' ?>"><?= finR($l['liquido']) ?></span>
      <?= $pago ? '<span class="badge bg-success ms-1">Pago ' . date('d/m', strtotime($l['pago_em'])) . '</span>' : '<span class="badge bg-warning text-dark ms-1">Em aberto</span>' ?></div>
  </div>
  <div class="card-body py-2">
    <?php if (!$pago): ?>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="acao" value="salvar_linha"><input type="hidden" name="id" value="<?= $l['id'] ?>">
      <div class="col-6 col-md-2"><label class="small mb-0">Salário</label><input type="text" name="salario" class="form-control form-control-sm" value="<?= $dec($l['salario']) ?>" inputmode="decimal"></div>
      <div class="col-6 col-md-2"><label class="small mb-0">Diárias <a href="?aba=diarias&comp=<?= $comp ?>" class="small">(lançar)</a></label><div class="form-control form-control-sm bg-light"><?= str_replace('.', ',', (string)(float)$l['diarias_qtd']) ?> = <?= finR($l['diarias_total']) ?></div></div>
      <div class="col-6 col-md-2"><label class="small mb-0">Adicionais (+)</label><input type="text" name="adicionais" class="form-control form-control-sm" value="<?= $dec($l['adicionais']) ?>" inputmode="decimal"></div>
      <div class="col-6 col-md-2"><label class="small mb-0">Descontos (−)</label><input type="text" name="descontos" class="form-control form-control-sm" value="<?= $dec($l['descontos']) ?>" inputmode="decimal"></div>
      <div class="col-md-3"><label class="small mb-0">Observação</label><input type="text" name="observacao" class="form-control form-control-sm" value="<?= htmlspecialchars($l['observacao'] ?? '') ?>" placeholder="Ex.: vale de R$ 200 dia 15"></div>
      <div class="col-md-1"><button class="btn btn-sm btn-outline-primary w-100" title="Salvar"><i class="bi bi-save"></i></button></div>
    </form>
    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mt-2 pt-2 border-top">
      <input type="hidden" name="acao" value="pagar"><input type="hidden" name="id" value="<?= $l['id'] ?>">
      <select name="forma_pagamento" class="form-select form-select-sm" style="max-width:150px"><?php foreach (FORMAS_PAGAMENTO as $k => $f): ?><option value="<?= $k ?>"><?= $f ?></option><?php endforeach; ?></select>
      <input type="file" name="comprovante" accept="image/*,.pdf" class="form-control form-control-sm" style="max-width:260px" data-reduzir title="Comprovante (opcional)">
      <button class="btn btn-sm btn-success" data-confirm="Registrar pagamento de <?= finR($l['liquido']) ?> para <?= htmlspecialchars($l['nome'], ENT_QUOTES) ?>?"><i class="bi bi-check2-circle me-1"></i>Pagar <?= finR($l['liquido']) ?></button>
      <button class="btn btn-sm btn-link text-danger ms-auto" name="acao" value="remover_linha" data-confirm="Tirar <?= htmlspecialchars($l['nome'], ENT_QUOTES) ?> da folha deste mês?">tirar deste mês</button>
    </form>
    <?php else: ?>
    <div class="small d-flex flex-wrap gap-3">
      <span>Salário <?= finR($l['salario']) ?></span><span>Diárias <?= str_replace('.', ',', (string)(float)$l['diarias_qtd']) ?> = <?= finR($l['diarias_total']) ?></span>
      <span>+ <?= finR($l['adicionais']) ?></span><span>− <?= finR($l['descontos']) ?></span>
      <?php if ($l['observacao']): ?><span class="text-muted"><?= htmlspecialchars($l['observacao']) ?></span><?php endif; ?>
      <?php if ($l['comprovante']): ?><a href="<?= fotoUrl($l['comprovante']) ?>" target="_blank"><i class="bi bi-eye"></i> comprovante</a><?php endif; ?>
      <form method="post" class="ms-auto m-0"><input type="hidden" name="acao" value="desfazer"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" data-confirm="Desfazer este pagamento? A despesa lançada na oficina será removida.">desfazer pagamento</button></form>
    </div>
    <?php endif; ?>
    <?php if ((float)$l['diarias_corrida'] > 0): ?><div class="small text-muted mt-1"><i class="bi bi-flag me-1"></i><?= finR($l['diarias_corrida']) ?> em diárias de corrida entram como custo da corrida, não da oficina.</div><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($aba === 'diarias'): ?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-plus-circle me-2 text-success"></i>Lançar diária</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="acao" value="diaria">
          <select name="usuario_id" class="form-select form-select-sm mb-2" required onchange="const v=this.selectedOptions[0].dataset.diaria;if(v)this.form.valor_unit.value=v;">
            <option value="">— Pessoa —</option>
            <?php foreach ($pessoas as $p): if (!$p['ativo']) continue; ?><option value="<?= $p['id'] ?>" data-diaria="<?= $dec($p['valor_diaria']) ?>"><?= htmlspecialchars($p['nome']) ?><?= $p['funcao'] ? ' · ' . htmlspecialchars($p['funcao']) : '' ?></option><?php endforeach; ?>
          </select>
          <select name="rally_id" class="form-select form-select-sm mb-2"><option value="">🔧 Oficina (fora de corrida)</option>
            <?php foreach ($rallys as $r): ?><option value="<?= $r['id'] ?>">🏁 <?= htmlspecialchars($r['nome']) ?></option><?php endforeach; ?></select>
          <div class="row g-2 mb-2">
            <div class="col-5"><input type="date" name="data" class="form-control form-control-sm" value="<?= $comp === date('Y-m') ? date('Y-m-d') : $comp . '-01' ?>"></div>
            <div class="col-3"><input type="text" name="quantidade" class="form-control form-control-sm" value="1" title="Dias (pode ser 0,5)" inputmode="decimal"></div>
            <div class="col-4"><input type="text" name="valor_unit" class="form-control form-control-sm" placeholder="R$/dia" inputmode="decimal" required></div>
          </div>
          <input type="text" name="observacao" class="form-control form-control-sm mb-2" placeholder="Observação (opcional)">
          <button class="btn btn-success btn-sm w-100"><i class="bi bi-save me-1"></i>Lançar</button>
        </form>
        <div class="small text-muted mt-2">A diária entra na folha do mês da data. Diária de corrida também aparece no relatório da corrida.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Diárias de <?= finCompLabel($comp) ?></div>
      <?php if ($diarias): ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
        <thead><tr><th>Data</th><th>Pessoa</th><th>Onde</th><th class="text-center">Dias</th><th class="text-end">R$/dia</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($diarias as $d): ?>
        <tr><td><?= date('d/m', strtotime($d['data'])) ?></td><td class="fw-semibold"><?= htmlspecialchars($d['nome']) ?><?= $d['observacao'] ? '<div class="text-muted fw-normal">' . htmlspecialchars($d['observacao']) . '</div>' : '' ?></td>
          <td><?= $d['rally_nome'] ? '<i class="bi bi-flag me-1"></i>' . htmlspecialchars($d['rally_nome']) : 'Oficina' ?></td>
          <td class="text-center"><?= str_replace('.', ',', (string)(float)$d['quantidade']) ?></td><td class="text-end"><?= finR($d['valor_unit']) ?></td>
          <td class="text-end fw-semibold"><?= finR($d['quantidade'] * $d['valor_unit']) ?></td>
          <td class="text-end"><form method="post" class="m-0"><input type="hidden" name="acao" value="diaria_del"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Remover esta diária?"><i class="bi bi-trash3"></i></button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="5" class="text-end">Total</td><td class="text-end"><?= finR(array_sum(array_map(fn($d) => $d['quantidade'] * $d['valor_unit'], $diarias))) ?></td><td></td></tr></tfoot>
      </table></div>
      <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhuma diária em <?= finCompLabel($comp) ?>.</p><?php endif; ?>
    </div>
  </div>
</div>

<?php else: /* pessoas */ ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-people me-2"></i>Quem está na folha e quanto ganha</span>
    <a href="equipe.php" class="btn btn-sm btn-outline-dark"><i class="bi bi-person-plus me-1"></i>Cadastrar motorista / apoio</a>
  </div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th>Pessoa</th><th>Tipo / função</th><th class="text-center">Na folha</th><th>Salário mensal</th><th>Valor da diária</th><th>PIX</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pessoas as $p): $fid = 'p' . $p['id']; ?>
    <tr class="<?= $p['ativo'] ? '' : 'text-muted' ?>">
      <td class="fw-semibold"><?= htmlspecialchars($p['nome']) ?><?= $p['ativo'] ? '' : ' <span class="badge bg-secondary">inativo</span>' ?><?= $p['sem_acesso'] ? ' <span class="badge bg-light text-dark border">sem login</span>' : '' ?></td>
      <td><?= $tipoLabel[$p['tipo']] ?? $p['tipo'] ?><?= $p['funcao'] ? ' · ' . htmlspecialchars($p['funcao']) : '' ?></td>
      <td class="text-center"><input type="checkbox" class="form-check-input" name="na_folha" value="1" form="<?= $fid ?>" <?= $p['na_folha'] ? 'checked' : '' ?>></td>
      <td><input type="text" name="salario_mensal" form="<?= $fid ?>" class="form-control form-control-sm" style="max-width:120px" value="<?= $dec($p['salario_mensal']) ?>" placeholder="—" inputmode="decimal"></td>
      <td><input type="text" name="valor_diaria" form="<?= $fid ?>" class="form-control form-control-sm" style="max-width:120px" value="<?= $dec($p['valor_diaria']) ?>" placeholder="—" inputmode="decimal"></td>
      <td><?= $p['pix_chave'] ? '<i class="bi bi-check-circle text-success"></i>' : '<span class="text-warning">sem</span>' ?></td>
      <td><form method="post" id="<?= $fid ?>" class="m-0"><input type="hidden" name="acao" value="pessoa"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-save"></i></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer bg-white small text-muted">Marque "Na folha" para quem recebe todo mês. Diaristas não precisam estar marcados: quem tiver diária no mês entra na folha ao gerar. O PIX cada um cadastra no Meu perfil (para quem não tem login, edite em Equipe).</div>
</div>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
