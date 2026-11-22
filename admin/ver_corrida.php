<?php
// admin/ver_corrida.php – v8: Página de organização da corrida
// Abas: Panorama · Programação · Equipe & carros · Gastos · Pagamento de staff · Reembolsos · Relatório · Fotos
// v14: editar gastos, pagamento de staff e relatório financeiro (entradas × saídas = lucro). Tudo aqui é só do admin.
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/corridas.php';
require_once '../includes/aprovacoes.php';
require_once '../includes/financeiro.php';
require_once '../includes/corrida_financeiro.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Corrida';

$rallyId = (int)($_GET['id'] ?? 0);
$rs = $pdo->prepare("SELECT * FROM rallys WHERE id=?"); $rs->execute([$rallyId]); $rally = $rs->fetch();
if (!$rally) { header('Location: corridas.php'); exit; }

$abas = ['panorama'=>['Panorama','bi-speedometer2'],'programacao'=>['Programação','bi-calendar-week'],'equipe'=>['Equipe & carros','bi-people'],'gastos'=>['Gastos','bi-cash-coin'],'staff'=>['Pagamento de staff','bi-person-badge'],'reembolsos'=>['Reembolsos','bi-wallet2'],'relatorio'=>['Relatório','bi-bar-chart'],'fotos'=>['Fotos','bi-images']];
$aba = $_GET['aba'] ?? 'panorama'; if (!isset($abas[$aba])) $aba = 'panorama';
$sucesso = $erro = '';
$num = fn($v) => (float)str_replace(['.', ','], ['', '.'], $v ?? '0');
// comprovante (foto reduzida ou PDF) → caminho ou null; erro vai para $erro
$salvarComp = function (string $campo, string $subdir, string $prefixo) use (&$erro) {
    [$c, $e] = finSalvarArquivo($_FILES[$campo] ?? [], $subdir, $prefixo); if ($e) $erro = $e; return $c;
};
$pagarStaff = function (int $sid) use ($pdo, $salvarComp, $rallyId) {
    $comp = $salvarComp('comprovante', 'corridas_staff', 'staff' . $rallyId);
    $forma = isset(FORMAS_PAGAMENTO[$_POST['forma'] ?? '']) ? $_POST['forma'] : 'pix';
    $pdo->prepare("UPDATE rally_staff SET pago=1, pago_em=?, forma=?, comprovante=COALESCE(?,comprovante) WHERE id=? AND rally_id=?")
        ->execute([finData($_POST['pago_em'] ?? '') ?: date('Y-m-d'), $forma, $comp, $sid, $rallyId]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    switch ($acao) {
    case 'salvar_info':
        $st = array_key_exists($_POST['status'] ?? '', RALLY_STATUS) ? $_POST['status'] : 'planejada';
        $pdo->prepare("UPDATE rallys SET nome=?,local=?,data_inicio=?,data_fim=?,status=?,descricao=?,hospedagem=?,logistica=?,observacoes=? WHERE id=?")
            ->execute([trim($_POST['nome']), trim($_POST['local'] ?? ''), ($_POST['data_inicio'] ?? '') ?: null, ($_POST['data_fim'] ?? '') ?: null, $st,
                       trim($_POST['descricao'] ?? ''), trim($_POST['hospedagem'] ?? '') ?: null, trim($_POST['logistica'] ?? '') ?: null, trim($_POST['observacoes'] ?? '') ?: null, $rallyId]);
        $sucesso = 'Informações da corrida salvas!'; break;

    case 'salvar_evento':
        $tipo = array_key_exists($_POST['tipo'] ?? '', PROG_TIPOS) ? $_POST['tipo'] : 'outro';
        $mapsUrl = trim($_POST['maps_url'] ?? '');
        if ($mapsUrl !== '' && !preg_match('#^https://#i', $mapsUrl)) { $erro = 'O link do mapa precisa começar com https:// (copie em Google Maps → Compartilhar → Copiar link).'; break; }
        $dados = [($_POST['data'] ?? '') ?: date('Y-m-d'), ($_POST['hora_inicio'] ?? '') ?: null, ($_POST['hora_fim'] ?? '') ?: null, $tipo, trim($_POST['titulo'] ?? ''), trim($_POST['local'] ?? '') ?: null, trim($_POST['observacao'] ?? '') ?: null, mb_substr($mapsUrl, 0, 500) ?: null];
        if (!$dados[4]) { $erro = 'Informe o título do evento.'; break; }
        if ($eid = (int)($_POST['evento_id'] ?? 0)) { $dados[] = $eid; $dados[] = $rallyId;
            $pdo->prepare("UPDATE rally_programacao SET data=?,hora_inicio=?,hora_fim=?,tipo=?,titulo=?,local=?,observacao=?,maps_url=? WHERE id=? AND rally_id=?")->execute($dados); }
        else { array_unshift($dados, $rallyId);
            $pdo->prepare("INSERT INTO rally_programacao (rally_id,data,hora_inicio,hora_fim,tipo,titulo,local,observacao,maps_url) VALUES (?,?,?,?,?,?,?,?,?)")->execute($dados); }
        $sucesso = 'Programação atualizada!'; break;
    case 'del_evento':
        $pdo->prepare("DELETE FROM rally_programacao WHERE id=? AND rally_id=?")->execute([(int)$_POST['evento_id'], $rallyId]); $sucesso = 'Evento removido.'; break;

    case 'add_equipe':
        foreach (array_map('intval', $_POST['usuario_ids'] ?? []) as $uid)
            $pdo->prepare("INSERT INTO rally_equipe (rally_id,usuario_id,funcao) VALUES (?,?,?) ON DUPLICATE KEY UPDATE funcao=VALUES(funcao)")->execute([$rallyId, $uid, trim($_POST['funcao'] ?? '') ?: null]);
        $sucesso = 'Equipe atualizada!'; break;
    case 'edit_equipe':
        $pdo->prepare("UPDATE rally_equipe SET funcao=?,observacao=? WHERE id=? AND rally_id=?")->execute([trim($_POST['funcao'] ?? '') ?: null, trim($_POST['observacao'] ?? '') ?: null, (int)$_POST['equipe_id'], $rallyId]);
        $sucesso = 'Função atualizada!'; break;
    case 'del_equipe':
        $pdo->prepare("DELETE FROM rally_equipe WHERE id=? AND rally_id=?")->execute([(int)$_POST['equipe_id'], $rallyId]); $sucesso = 'Removido da equipe.'; break;

    case 'salvar_participante':
        $cid = (int)($_POST['cliente_id'] ?? 0); if (!$cid) { $erro = 'Selecione o cliente.'; break; }
        $vid = (int)($_POST['veiculo_id'] ?? 0) ?: null;
        if ($vid) { $c = $pdo->prepare("SELECT COUNT(*) FROM veiculos WHERE id=? AND cliente_id=?"); $c->execute([$vid, $cid]); if (!$c->fetchColumn()) $vid = null; }
        $d = [$cid, $vid, (int)($_POST['navegador_id'] ?? 0) ?: null, trim($_POST['numero_carro'] ?? '') ?: null, trim($_POST['categoria'] ?? '') ?: null, trim($_POST['observacao'] ?? '') ?: null];
        if ($pid = (int)($_POST['participante_id'] ?? 0)) { $d[] = $pid; $d[] = $rallyId;
            $pdo->prepare("UPDATE rally_participantes SET cliente_id=?,veiculo_id=?,navegador_id=?,numero_carro=?,categoria=?,observacao=? WHERE id=? AND rally_id=?")->execute($d); }
        else { array_unshift($d, $rallyId);
            $pdo->prepare("INSERT INTO rally_participantes (rally_id,cliente_id,veiculo_id,navegador_id,numero_carro,categoria,observacao) VALUES (?,?,?,?,?,?,?)")->execute($d); }
        $sucesso = 'Carro/participante salvo!'; break;
    case 'del_participante':
        $pid = (int)$_POST['participante_id'];
        $pdo->prepare("UPDATE rally_fotos SET participante_id=NULL WHERE participante_id=? AND rally_id=?")->execute([$pid, $rallyId]);
        $pdo->prepare("DELETE FROM rally_participantes WHERE id=? AND rally_id=?")->execute([$pid, $rallyId]); $sucesso = 'Participante removido.'; break;

    case 'add_gasto':
        $desc = trim($_POST['descricao'] ?? ''); $valor = $num($_POST['valor'] ?? '');
        if (!$desc || $valor <= 0) { $erro = 'Informe descrição e valor.'; break; }
        $comp = null;
        if (!empty($_FILES['comprovante']['name']) && $_FILES['comprovante']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $dir = UPLOAD_DIR . 'corridas_gastos/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $nome = 'gasto' . $rallyId . '_' . date('ymd') . '_' . bin2hex(random_bytes(4)) . '.pdf';
                if (move_uploaded_file($_FILES['comprovante']['tmp_name'], $dir . $nome)) $comp = 'corridas_gastos/' . $nome;
            } else { $r = fotoSalvarUpload($_FILES['comprovante'], 'corridas_gastos', 'gasto' . $rallyId); if (!isset($r['erro'])) { $comp = $r['caminho']; fotoApagar($r['thumb']); } else $erro = $r['erro']; }
        }
        $pdo->prepare("INSERT INTO rally_gastos (rally_id,categoria_id,descricao,valor,data,fornecedor,cliente_id,comprovante,lancado_por) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$rallyId, (int)($_POST['categoria_id'] ?? 0) ?: null, $desc, $valor, ($_POST['data'] ?? '') ?: date('Y-m-d'), trim($_POST['fornecedor'] ?? '') ?: null, (int)($_POST['cliente_id'] ?? 0) ?: null, $comp, $u['id']]);
        $sucesso = 'Gasto lançado!'; break;
    case 'del_gasto':
        $g = $pdo->prepare("SELECT * FROM rally_gastos WHERE id=? AND rally_id=?"); $g->execute([(int)$_POST['gasto_id'], $rallyId]); $g = $g->fetch();
        if ($g && $g['cc_id']) { $erro = 'Este gasto já foi lançado na conta do cliente. Remova o lançamento na Conta Corrente antes.'; break; }
        if ($g && !empty($g['reembolso_id'])) { $erro = 'Este gasto veio de um reembolso aprovado e não pode ser apagado aqui.'; break; }
        if ($g) { fotoApagar($g['comprovante']); $pdo->prepare("DELETE FROM rally_gastos WHERE id=?")->execute([$g['id']]); $sucesso = 'Gasto removido.'; }
        break;
    case 'edit_gasto':
        $g = $pdo->prepare("SELECT * FROM rally_gastos WHERE id=? AND rally_id=?"); $g->execute([(int)$_POST['gasto_id'], $rallyId]); $g = $g->fetch();
        if (!$g) break;
        if (!empty($g['reembolso_id'])) { $erro = 'Este gasto veio de um reembolso aprovado. Para mudar, use Financeiro › Reembolsos.'; break; }
        $desc = trim($_POST['descricao'] ?? ''); $valor = $num($_POST['valor'] ?? ''); $cli = (int)($_POST['cliente_id'] ?? 0) ?: null;
        if ($g['cc_id']) { $valor = (float)$g['valor']; $cli = $g['cliente_id']; }   // já está na conta do cliente: valor e cliente travados
        if (!$desc || $valor <= 0) { $erro = 'Informe descrição e valor.'; break; }
        $comp = $g['comprovante'];
        if ($novo = $salvarComp('comprovante', 'corridas_gastos', 'gasto' . $rallyId)) { if ($comp) fotoApagar($comp); $comp = $novo; }
        $pdo->prepare("UPDATE rally_gastos SET categoria_id=?,descricao=?,valor=?,data=?,fornecedor=?,cliente_id=?,comprovante=? WHERE id=?")
            ->execute([(int)($_POST['categoria_id'] ?? 0) ?: null, $desc, $valor, finData($_POST['data'] ?? '') ?: $g['data'], trim($_POST['fornecedor'] ?? '') ?: null, $cli, $comp, $g['id']]);
        $sucesso = 'Gasto atualizado!' . ($g['cc_id'] ? ' (valor e cliente não mudam: já está na conta do cliente)' : ''); break;

    case 'salvar_staff':
        $uid = (int)($_POST['usuario_id'] ?? 0) ?: null; $nome = trim($_POST['nome'] ?? '');
        if ($uid && $nome === '') { $q = $pdo->prepare("SELECT nome FROM usuarios WHERE id=?"); $q->execute([$uid]); $nome = (string)$q->fetchColumn(); }
        $dias = max(0, (float)str_replace(',', '.', $_POST['dias'] ?? '1')); $diaria = $num($_POST['valor_diaria'] ?? ''); $adic = $num($_POST['adicional'] ?? '');
        $cli = (int)($_POST['cliente_id'] ?? 0) ?: null;
        $sid = (int)($_POST['staff_id'] ?? 0); $atual = null;
        if ($sid) { $q = $pdo->prepare("SELECT * FROM rally_staff WHERE id=? AND rally_id=?"); $q->execute([$sid, $rallyId]); if (!($atual = $q->fetch())) break; }
        if ($atual && $atual['cc_id']) { $dias = (float)$atual['dias']; $diaria = (float)$atual['valor_diaria']; $adic = (float)$atual['adicional']; $cli = $atual['cliente_id']; }
        $total = round($dias * $diaria + $adic, 2);
        if ($nome === '') { $erro = 'Escolha a pessoa ou digite o nome do freelancer.'; break; }
        if ($total <= 0) { $erro = 'Informe os valores: dias × valor da diária e/ou adicional.'; break; }
        $d = [$uid, mb_substr($nome, 0, 120), mb_substr(trim($_POST['funcao'] ?? ''), 0, 80) ?: null, finData($_POST['data'] ?? '') ?: date('Y-m-d'), $dias, $diaria, $adic,
              mb_substr(trim($_POST['adicional_desc'] ?? ''), 0, 120) ?: null, $total, mb_substr(trim($_POST['pix'] ?? ''), 0, 190) ?: null, $cli, mb_substr(trim($_POST['observacao'] ?? ''), 0, 255) ?: null];
        if ($atual) { $d[] = $atual['id'];
            $pdo->prepare("UPDATE rally_staff SET usuario_id=?,nome=?,funcao=?,data=?,dias=?,valor_diaria=?,adicional=?,adicional_desc=?,total=?,pix=?,cliente_id=?,observacao=? WHERE id=?")->execute($d);
            $sucesso = 'Pagamento de staff atualizado!'; }
        else { array_unshift($d, $rallyId); $d[] = $u['id'];
            $pdo->prepare("INSERT INTO rally_staff (rally_id,usuario_id,nome,funcao,data,dias,valor_diaria,adicional,adicional_desc,total,pix,cliente_id,observacao,lancado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($d);
            $novoId = (int)$pdo->lastInsertId();
            if (!empty($_POST['ja_pago'])) $pagarStaff($novoId);
            $sucesso = 'Staff lançado: ' . $nome . ' – R$ ' . number_format($total, 2, ',', '.') . (!empty($_POST['ja_pago']) ? ' (pago)' : ' (a pagar)'); }
        break;
    case 'pagar_staff':
        $pagarStaff((int)$_POST['staff_id']); $sucesso = 'Pagamento registrado!'; break;
    case 'despagar_staff':
        $q = $pdo->prepare("SELECT * FROM rally_staff WHERE id=? AND rally_id=?"); $q->execute([(int)$_POST['staff_id'], $rallyId]);
        if ($st = $q->fetch()) { if ($st['comprovante']) fotoApagar($st['comprovante']);
            $pdo->prepare("UPDATE rally_staff SET pago=0, pago_em=NULL, forma=NULL, comprovante=NULL WHERE id=?")->execute([$st['id']]); $sucesso = 'Pagamento desfeito: voltou para "a pagar".'; }
        break;
    case 'del_staff':
        $q = $pdo->prepare("SELECT * FROM rally_staff WHERE id=? AND rally_id=?"); $q->execute([(int)$_POST['staff_id'], $rallyId]); $st = $q->fetch();
        if ($st && $st['cc_id']) { $erro = 'Este valor já foi lançado na conta do cliente. Remova o lançamento na Conta Corrente antes.'; break; }
        if ($st) { if ($st['comprovante']) fotoApagar($st['comprovante']); $pdo->prepare("DELETE FROM rally_staff WHERE id=?")->execute([$st['id']]); $sucesso = 'Pagamento de staff excluído.'; }
        break;
    case 'staff_cc':
        $q = $pdo->prepare("SELECT * FROM rally_staff WHERE id=? AND rally_id=? AND cliente_id IS NOT NULL AND cc_id IS NULL"); $q->execute([(int)$_POST['staff_id'], $rallyId]);
        if ($st = $q->fetch()) {
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_arquivo) VALUES (?,?,?,'debito',?,NULL)")
                ->execute([$st['cliente_id'], $rally['nome'] . ' – Staff: ' . ($st['funcao'] ? $st['funcao'] . ' ' : '') . $st['nome'], $st['total'], $u['id']]);
            $pdo->prepare("UPDATE rally_staff SET cc_id=? WHERE id=?")->execute([$pdo->lastInsertId(), $st['id']]);
            $sucesso = 'Lançado na conta corrente do cliente!';
        }
        break;
    case 'lancar_cc':
        $g = $pdo->prepare("SELECT * FROM rally_gastos WHERE id=? AND rally_id=? AND cliente_id IS NOT NULL AND cc_id IS NULL"); $g->execute([(int)$_POST['gasto_id'], $rallyId]); $g = $g->fetch();
        if ($g) {
            $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id,comprovante_arquivo) VALUES (?,?,?,'debito',?,NULL)")
                ->execute([$g['cliente_id'], $rally['nome'] . ' – ' . $g['descricao'], $g['valor'], $u['id']]);
            $pdo->prepare("UPDATE rally_gastos SET cc_id=? WHERE id=?")->execute([$pdo->lastInsertId(), $g['id']]);
            $sucesso = 'Lançado na conta corrente do cliente!';
        }
        break;

    case 'upload_fotos':
        $pid = (int)($_POST['participante_id'] ?? 0) ?: null; $n = 0;
        foreach (fotosNormalizarFiles($_FILES['fotos'] ?? []) as $f) {
            $r = fotoSalvarUpload($f, 'corridas/' . $rallyId, 'rally' . $rallyId);
            if (isset($r['erro'])) { $erro = $r['erro']; continue; }
            $pdo->prepare("INSERT INTO rally_fotos (rally_id,participante_id,caminho,thumb,tamanho,descricao,enviado_por) VALUES (?,?,?,?,?,?,?)")
                ->execute([$rallyId, $pid, $r['caminho'], $r['thumb'], $r['tamanho'], trim($_POST['descricao'] ?? '') ?: null, $u['id']]); $n++;
        }
        if ($n) $sucesso = "$n foto(s) adicionada(s)!"; break;
    case 'del_foto':
        $f = $pdo->prepare("SELECT * FROM rally_fotos WHERE id=? AND rally_id=?"); $f->execute([(int)$_POST['foto_id'], $rallyId]); $f = $f->fetch();
        if ($f) { fotoApagar($f['caminho'], $f['thumb']); $pdo->prepare("DELETE FROM rally_fotos WHERE id=?")->execute([$f['id']]); $sucesso = 'Foto removida.'; }
        break;
    }
    $_SESSION['flash_rally'] = [$sucesso, $erro];
    header("Location: ver_corrida.php?id=$rallyId&aba=" . ($_POST['aba'] ?? $aba)); exit;
}
if (!empty($_SESSION['flash_rally'])) { [$sucesso, $erro] = $_SESSION['flash_rally']; unset($_SESSION['flash_rally']); }

// ── Dados ──
$participantes = rallyParticipantes($pdo, $rallyId);
$equipe = rallyEquipe($pdo, $rallyId);
$programacao = rallyProgramacao($pdo, $rallyId);
$categorias = $pdo->query("SELECT id,nome,cor,icone FROM rally_categorias WHERE ativo=1 ORDER BY nome")->fetchAll();
$clientes = $pdo->query("SELECT id,nome FROM usuarios WHERE tipo='cliente' AND ativo=1 ORDER BY nome")->fetchAll();
$navegadores = $pdo->query("SELECT id,nome,piloto_id FROM usuarios WHERE tipo='navegador' AND ativo=1 ORDER BY nome")->fetchAll();
$staff = $pdo->query("SELECT id,nome,tipo,funcao,pix_tipo,pix_chave FROM usuarios WHERE tipo IN ('mecanico','equipe','admin','estoque') AND ativo=1 ORDER BY FIELD(tipo,'mecanico','equipe','admin','estoque'), nome")->fetchAll();
$veicPorCliente = [];
foreach ($pdo->query("SELECT id,cliente_id,marca,modelo,placa FROM veiculos ORDER BY marca")->fetchAll() as $v) $veicPorCliente[$v['cliente_id']][] = ['id'=>(int)$v['id'],'nome'=>trim($v['marca'].' '.$v['modelo'].' '.$v['placa'])];

$gastos = $pdo->prepare("SELECT g.*, c.nome AS cat_nome, c.cor AS cat_cor, u.nome AS cliente_nome FROM rally_gastos g LEFT JOIN rally_categorias c ON c.id=g.categoria_id LEFT JOIN usuarios u ON u.id=g.cliente_id WHERE g.rally_id=? ORDER BY g.data DESC, g.id DESC");
$gastos->execute([$rallyId]); $gastos = $gastos->fetchAll();
$totGastosEquipe = array_sum(array_column($gastos, 'valor'));

$osClientes = $pdo->prepare("SELECT os.*, uc.nome AS cliente_nome, um.nome AS mecanico_nome,
    (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
    (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos
    FROM ordens_servico os JOIN usuarios uc ON uc.id=os.cliente_id LEFT JOIN usuarios um ON um.id=os.mecanico_id WHERE os.rally_id=? ORDER BY uc.nome, os.numero_cliente");
$osClientes->execute([$rallyId]); $osClientes = $osClientes->fetchAll();
$osNav = $pdo->prepare("SELECT n.*, u.nome AS navegador_nome, p.nome AS piloto_nome FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id JOIN usuarios p ON p.id=n.piloto_id WHERE n.rally_id=? ORDER BY p.nome, n.titulo");
$osNav->execute([$rallyId]); $osNav = $osNav->fetchAll();

// v14: resultado financeiro (entradas × saídas = lucro) — ver includes/corrida_financeiro.php
$fin = corridaFinanceiro($pdo, $rallyId);
// Pagamento de staff + diárias da folha desta corrida
$staffPag = []; $staffOk = true;
try { $q = $pdo->prepare("SELECT s.*, c.nome AS cliente_nome FROM rally_staff s LEFT JOIN usuarios c ON c.id=s.cliente_id WHERE s.rally_id=? ORDER BY s.data, s.nome"); $q->execute([$rallyId]); $staffPag = $q->fetchAll(); }
catch (PDOException $e) { $staffOk = false; }
$q = $pdo->prepare("SELECT d.*, u.nome FROM folha_diarias d JOIN usuarios u ON u.id=d.usuario_id WHERE d.rally_id=? ORDER BY d.data, u.nome"); $q->execute([$rallyId]); $diariasCorrida = $q->fetchAll();

// Fase 2: reembolsos da equipe nesta corrida, por pessoa
$reemb = $pdo->prepare("SELECT u.id, u.nome, u.pix_chave,
        SUM(CASE WHEN r.status='aprovado' THEN r.valor ELSE 0 END) AS aprovado,
        SUM(CASE WHEN r.status='aprovado' AND r.pago=1 THEN r.valor ELSE 0 END) AS pago,
        SUM(CASE WHEN r.status='aprovado' AND r.pago=0 THEN r.valor ELSE 0 END) AS apagar,
        SUM(CASE WHEN r.status='pendente' THEN r.valor ELSE 0 END) AS pendente,
        SUM(r.status='pendente') AS n_pend, COUNT(*) AS n
    FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id WHERE r.rally_id=? AND r.status<>'recusado' GROUP BY u.id, u.nome, u.pix_chave ORDER BY u.nome");
$reemb->execute([$rallyId]); $reemb = $reemb->fetchAll();

$fotos = $pdo->prepare("SELECT f.*, c.nome AS cliente_nome, p.numero_carro FROM rally_fotos f LEFT JOIN rally_participantes p ON p.id=f.participante_id LEFT JOIN usuarios c ON c.id=p.cliente_id WHERE f.rally_id=? ORDER BY f.criado_em DESC");
$fotos->execute([$rallyId]); $fotos = $fotos->fetchAll();

$dias = rallyDiasPara($rally);
$proximos = array_values(array_filter($programacao, fn($e) => $e['data'] >= date('Y-m-d')));
$statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
$navStatus = ['aberta'=>'Aberta','encerrada'=>'Aguard. aprovação','concluida'=>'Aprovada'];
$R = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

require_once '../includes/header.php';
?>

<a href="corridas.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Corridas</a>
<div class="rally-hero mt-2 mb-3">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative" style="z-index:1">
    <div>
      <div class="mb-1"><?= rallyBadge($rally['status'] ?? 'planejada') ?></div>
      <h4 class="fw-bold mb-1"><?= htmlspecialchars($rally['nome']) ?></h4>
      <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($rally['local'] ?: 'Local a definir') ?> · <i class="bi bi-calendar3 ms-1 me-1"></i><?= rallyPeriodo($rally) ?></div>
      <?php if ($rally['descricao']): ?><div class="small opacity-75 mt-1"><?= htmlspecialchars($rally['descricao']) ?></div><?php endif; ?>
    </div>
    <div class="text-end">
      <?php if ($dias !== null && $dias > 0): ?><div class="contagem"><?= $dias ?></div><div class="small opacity-75">dia(s) para a largada</div>
      <?php elseif ($dias !== null && $dias <= 0 && (empty($rally['data_fim']) || $rally['data_fim'] >= date('Y-m-d'))): ?><div class="contagem text-warning">Em prova</div>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-light mt-2" data-bs-toggle="modal" data-bs-target="#modalInfo"><i class="bi bi-pencil me-1"></i>Editar informações</button>
    </div>
  </div>
</div>

<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto">
  <?php foreach ($abas as $k => [$l, $ic]): ?>
  <li class="nav-item"><a class="nav-link text-nowrap <?= $aba === $k ? 'active' : '' ?>" href="?id=<?= $rallyId ?>&aba=<?= $k ?>"><i class="bi <?= $ic ?> me-1"></i><?= $l ?>
    <?php if ($k === 'equipe'): ?><span class="badge bg-secondary ms-1"><?= count($participantes) + count($equipe) ?></span><?php endif; ?>
    <?php if ($k === 'fotos' && $fotos): ?><span class="badge bg-secondary ms-1"><?= count($fotos) ?></span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($aba === 'panorama'): ?>
<div class="row g-3 mb-4">
  <?php $kpis = [['Carros inscritos', count($participantes), 'bi-car-front', '#dc3545', 'equipe'], ['Equipe escalada', count($equipe), 'bi-people', '#0d6efd', 'equipe'], ['OS da corrida', count($osClientes) + count($osNav), 'bi-clipboard-check', '#fd7e14', 'panorama'], [$fin['lucro'] >= 0 ? 'Lucro da corrida' : 'Prejuízo da corrida', $R($fin['lucro']), 'bi-graph-up-arrow', $fin['lucro'] >= 0 ? '#198754' : '#dc3545', 'relatorio']];
  foreach ($kpis as [$l, $v, $ic, $c, $a]): ?>
  <div class="col-6 col-lg-3"><a href="?id=<?= $rallyId ?>&aba=<?= $a ?>" class="pend-tile"><div class="ic" style="background:<?= $c ?>1a;color:<?= $c ?>"><i class="bi <?= $ic ?>"></i></div><div><div class="n"><?= $v ?></div><div class="l"><?= $l ?></div></div></a></div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between"><span><i class="bi bi-car-front me-2 text-danger"></i>Carros e pilotos</span><a href="?id=<?= $rallyId ?>&aba=equipe" class="small">Gerenciar</a></div>
      <div class="card-body">
        <?php if ($participantes): ?>
        <div class="row g-3"><?php foreach ($participantes as $p): ?><div class="col-6 col-md-4"><?php renderParticipante($p, true); ?></div><?php endforeach; ?></div>
        <?php else: ?><p class="text-muted text-center mb-0 py-3">Nenhum carro vinculado. <a href="?id=<?= $rallyId ?>&aba=equipe">Vincular clientes/carros</a></p><?php endif; ?>
      </div>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-clipboard-check me-2 text-warning"></i>Preparação dos carros (OS vinculadas)</div>
      <div class="card-body p-0">
        <?php if ($osClientes || $osNav): ?>
        <table class="table table-hover mb-0 small align-middle">
          <thead><tr><th>OS</th><th>Cliente</th><th>Mecânico</th><th>Afazeres</th><th>Status</th><th class="text-end">Valor</th></tr></thead>
          <tbody>
          <?php foreach ($osClientes as $o): ?>
          <tr class="tr-link" onclick="location.href='editar_os.php?id=<?= $o['id'] ?>'">
            <td><span class="badge bg-dark"><?= htmlspecialchars($o['numero_os'] ?? '') ?></span> <?= htmlspecialchars($o['titulo']) ?></td>
            <td><?= htmlspecialchars($o['cliente_nome']) ?></td><td class="text-muted"><?= htmlspecialchars($o['mecanico_nome'] ?? '—') ?></td>
            <td style="min-width:90px"><?php if ($o['af_total']): $pc = round($o['af_feitos'] / $o['af_total'] * 100); ?><div class="progresso-os"><div style="width:<?= $pc ?>%"></div></div><span class="text-muted" style="font-size:.7rem"><?= $o['af_feitos'] ?>/<?= $o['af_total'] ?></span><?php else: ?>—<?php endif; ?></td>
            <td><span class="badge badge-<?= $o['status'] ?>"><?= $statusLabel[$o['status']] ?></span></td>
            <td class="text-end"><?= $R($o['valor_total']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php foreach ($osNav as $o): ?>
          <tr class="tr-link" onclick="location.href='aprovar_nav_os.php?id=<?= $o['id'] ?>'">
            <td><span class="badge" style="background:#fd7e14">NAV</span> <?= htmlspecialchars($o['titulo']) ?></td>
            <td><?= htmlspecialchars($o['piloto_nome']) ?></td><td class="text-muted"><i class="bi bi-compass me-1"></i><?= htmlspecialchars($o['navegador_nome']) ?></td><td>—</td>
            <td><span class="badge bg-warning text-dark"><?= $navStatus[$o['status']] ?? $o['status'] ?></span></td>
            <td class="text-end"><?= $R($o['valor_total']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p class="text-muted text-center py-4 mb-0 small">Nenhuma OS vinculada a esta corrida. Vincule pelo campo "Rally / Corrida" na OS.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between"><span><i class="bi bi-calendar-week me-2 text-primary"></i>Próximos compromissos</span><a href="?id=<?= $rallyId ?>&aba=programacao" class="small">Ver tudo</a></div>
      <div class="card-body"><?php renderProgramacao(array_slice($proximos ?: $programacao, 0, 5)); ?></div>
    </div>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-people me-2 text-primary"></i>Equipe</div>
      <div class="card-body">
        <?php if ($equipe): foreach ($equipe as $e): ?>
        <div class="d-flex align-items-center gap-2 mb-2"><span class="pessoa-chip m-0"><span class="av"><?= iniciais($e['nome']) ?></span><?= htmlspecialchars($e['nome']) ?></span><span class="small text-muted"><?= htmlspecialchars($e['funcao'] ?? '') ?></span></div>
        <?php endforeach; else: ?><p class="text-muted small mb-0">Ninguém escalado ainda.</p><?php endif; ?>
      </div>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-info-circle me-2"></i>Informações práticas</div>
      <div class="card-body"><?php renderInfoPratica($rally); ?></div>
    </div>
  </div>
</div>

<?php elseif ($aba === 'programacao'): ?>
<div class="row g-4">
  <div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-calendar-week me-2"></i>Cronograma</div>
    <div class="card-body"><?php renderProgramacao($programacao, true); ?></div></div></div>
  <div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold border-bottom" id="tituloEvento">Novo evento</div>
    <div class="card-body">
      <form method="post" id="formEvento"><input type="hidden" name="acao" value="salvar_evento"><input type="hidden" name="aba" value="programacao"><input type="hidden" name="evento_id" id="evId">
        <div class="mb-2"><label class="form-label small mb-1">Título *</label><input type="text" name="titulo" id="evTitulo" class="form-control form-control-sm" placeholder="Ex: Largada promocional" required></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Data</label><input type="date" name="data" id="evData" class="form-control form-control-sm" value="<?= $rally['data_inicio'] ?? date('Y-m-d') ?>" required></div>
          <div class="col-6"><label class="form-label small mb-1">Tipo</label><select name="tipo" id="evTipo" class="form-select form-select-sm"><?php foreach (PROG_TIPOS as $k => [$l]): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small mb-1">Início</label><input type="time" name="hora_inicio" id="evIni" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small mb-1">Fim</label><input type="time" name="hora_fim" id="evFim" class="form-control form-control-sm"></div>
        </div>
        <div class="mb-2"><label class="form-label small mb-1">Local / endereço</label><input type="text" name="local" id="evLocal" class="form-control form-control-sm" placeholder="Ex.: Parque de apoio – Av. Brasil, 1000, Cidade"></div>
        <div class="mb-2"><label class="form-label small mb-1"><i class="bi bi-map me-1 text-primary"></i>Link do Google Maps <span class="text-muted">(opcional)</span></label>
          <input type="url" name="maps_url" id="evMaps" class="form-control form-control-sm" placeholder="https://maps.app.goo.gl/...">
          <div class="form-text">No celular: Google Maps → segure o dedo no ponto → <strong>Compartilhar</strong> → <strong>Copiar link</strong> e cole aqui. Sem link, o botão usa o endereço acima.</div></div>
        <div class="mb-3"><label class="form-label small mb-1">Observação</label><textarea name="observacao" id="evObs" rows="2" class="form-control form-control-sm"></textarea></div>
        <div class="d-flex gap-2"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-save me-1"></i>Salvar</button><button type="button" class="btn btn-light btn-sm border" onclick="novoEvento()">Limpar</button></div>
      </form>
    </div></div></div>
</div>

<?php elseif ($aba === 'equipe'): ?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center"><span><i class="bi bi-car-front me-2 text-danger"></i>Clientes e carros na corrida</span>
        <button class="btn btn-sm btn-primary" onclick="editarParticipante(null)"><i class="bi bi-plus-lg me-1"></i>Vincular carro</button></div>
      <div class="card-body p-0">
        <?php if ($participantes): ?>
        <table class="table table-hover mb-0 small align-middle">
          <thead><tr><th>#</th><th>Piloto / cliente</th><th>Carro</th><th>Navegador</th><th>Categoria</th><th></th></tr></thead>
          <tbody><?php foreach ($participantes as $p): ?>
          <tr>
            <td class="fw-bold text-danger"><?= $p['numero_carro'] ? '#'.htmlspecialchars($p['numero_carro']) : '—' ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($p['cliente_nome']) ?></td>
            <td><?php if ($p['foto_capa']): ?><img src="<?= fotoThumbUrl($p['foto_capa_thumb'], $p['foto_capa']) ?>" class="carro-mini me-1" style="width:40px;height:28px"><?php endif; ?><?= htmlspecialchars(trim(($p['marca'] ?? '').' '.($p['modelo'] ?? ''))) ?: '—' ?></td>
            <td><?= htmlspecialchars($p['navegador_nome'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['categoria'] ?? '') ?></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick='editarParticipante(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline"><input type="hidden" name="acao" value="del_participante"><input type="hidden" name="aba" value="equipe"><input type="hidden" name="participante_id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover este carro da corrida?"><i class="bi bi-trash3"></i></button></form>
            </td>
          </tr><?php endforeach; ?></tbody>
        </table>
        <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum carro vinculado ainda.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-people me-2 text-primary"></i>Equipe escalada (mecânicos e apoio)</div>
      <div class="card-body p-0">
        <?php if ($equipe): ?><ul class="list-group list-group-flush small">
          <?php foreach ($equipe as $e): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
            <span class="pessoa-chip m-0"><span class="av"><?= iniciais($e['nome']) ?></span><?= htmlspecialchars($e['nome']) ?></span>
            <form method="post" class="d-flex gap-1 flex-fill"><input type="hidden" name="acao" value="edit_equipe"><input type="hidden" name="aba" value="equipe"><input type="hidden" name="equipe_id" value="<?= $e['id'] ?>">
              <input type="text" name="funcao" list="funcoes" class="form-control form-control-sm" value="<?= htmlspecialchars($e['funcao'] ?? '') ?>" placeholder="Função" onchange="this.form.submit()"></form>
            <form method="post" class="m-0"><input type="hidden" name="acao" value="del_equipe"><input type="hidden" name="aba" value="equipe"><input type="hidden" name="equipe_id" value="<?= $e['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover da equipe?"><i class="bi bi-x-lg"></i></button></form>
          </li>
          <?php endforeach; ?></ul>
        <?php else: ?><p class="text-muted text-center py-3 mb-0 small">Ninguém escalado.</p><?php endif; ?>
      </div>
      <div class="card-footer bg-light">
        <form method="post"><input type="hidden" name="acao" value="add_equipe"><input type="hidden" name="aba" value="equipe">
          <label class="form-label small fw-semibold mb-1">Escalar pessoas</label>
          <select name="usuario_ids[]" class="form-select form-select-sm mb-2" multiple size="5" required>
            <?php $ja = array_column($equipe, 'usuario_id'); $tipoL = ['mecanico'=>'Mecânicos','admin'=>'Administração','estoque'=>'Estoque']; $grp = null;
            foreach ($staff as $s): if (in_array($s['id'], $ja)) continue; if ($grp !== $s['tipo']) { if ($grp) echo '</optgroup>'; $grp = $s['tipo']; echo '<optgroup label="'.$tipoL[$grp].'">'; } ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?></option>
            <?php endforeach; if ($grp) echo '</optgroup>'; ?>
          </select>
          <div class="d-flex gap-2"><input type="text" name="funcao" list="funcoes" class="form-control form-control-sm" placeholder="Função (ex: Mecânico)"><button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>Escalar</button></div>
          <div class="form-text">Segure Ctrl (ou toque longo) para escolher vários.</div>
        </form>
      </div>
    </div>
  </div>
</div>
<datalist id="funcoes"><?php foreach (EQUIPE_FUNCOES as $f): ?><option value="<?= $f ?>"><?php endforeach; ?></datalist>

<?php elseif ($aba === 'gastos'): ?>
<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between"><span><i class="bi bi-cash-coin me-2 text-success"></i>Gastos da equipe na corrida</span><span class="fw-bold text-danger"><?= $R($totGastosEquipe) ?></span></div>
      <div class="card-body p-0">
        <?php if ($gastos): ?>
        <table class="table table-hover mb-0 small align-middle">
          <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Cobrar de</th><th class="text-end">Valor</th><th></th></tr></thead>
          <tbody><?php foreach ($gastos as $g): ?>
          <tr>
            <td class="text-muted"><?= $g['data'] ? date('d/m', strtotime($g['data'])) : '' ?></td>
            <td><?= htmlspecialchars($g['descricao']) ?><?php if ($g['fornecedor']): ?><div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($g['fornecedor']) ?></div><?php endif; ?></td>
            <td><?php if ($g['cat_nome']): ?><span class="badge" style="background:<?= $g['cat_cor'] ?>"><?= htmlspecialchars($g['cat_nome']) ?></span><?php endif; ?></td>
            <td><?php if ($g['cliente_nome']): ?><?= htmlspecialchars($g['cliente_nome']) ?> <?= $g['cc_id'] ? '<span class="badge bg-success">na CC</span>' : '' ?><?php else: ?><span class="text-muted">Equipe</span><?php endif; ?></td>
            <td class="text-end fw-semibold"><?= $R($g['valor']) ?></td>
            <td class="text-nowrap text-end">
              <?php if ($g['comprovante']): ?><a href="<?= fotoUrl($g['comprovante']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Comprovante"><i class="bi bi-eye"></i></a><?php endif; ?>
              <?php if ($g['cliente_id'] && !$g['cc_id']): ?><form method="post" class="d-inline"><input type="hidden" name="acao" value="lancar_cc"><input type="hidden" name="aba" value="gastos"><input type="hidden" name="gasto_id" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-outline-success py-0 px-1" title="Lançar na conta do cliente" data-confirm="Lançar <?= $R($g['valor']) ?> como débito na conta de <?= htmlspecialchars($g['cliente_nome'], ENT_QUOTES) ?>?"><i class="bi bi-box-arrow-in-right"></i> CC</button></form><?php endif; ?>
              <?php if (!empty($g['reembolso_id'])): ?><a href="reembolsos.php?onde=<?= $rallyId ?>&sit=tudo" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Veio de um reembolso: edite/exclua em Financeiro › Reembolsos"><i class="bi bi-lock"></i></a>
              <?php else: $gj = ['id'=>(int)$g['id'],'descricao'=>$g['descricao'],'valor'=>number_format((float)$g['valor'], 2, ',', '.'),'data'=>$g['data'],'categoria_id'=>$g['categoria_id'] ? (int)$g['categoria_id'] : '','fornecedor'=>$g['fornecedor'] ?? '','cliente_id'=>$g['cliente_id'] ? (int)$g['cliente_id'] : '','cc'=>(bool)$g['cc_id']]; ?>
              <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" title="Editar gasto" onclick='editarGasto(<?= htmlspecialchars(json_encode($gj, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline"><input type="hidden" name="acao" value="del_gasto"><input type="hidden" name="aba" value="gastos"><input type="hidden" name="gasto_id" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover este gasto?"><i class="bi bi-trash3"></i></button></form><?php endif; ?>
            </td>
          </tr><?php endforeach; ?></tbody>
        </table>
        <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum gasto lançado.</p><?php endif; ?>
      </div>
      <div class="card-footer bg-light small text-muted"><i class="bi bi-info-circle me-1"></i>Gastos são o que você gastou para fazer a corrida e entram como <b>saída</b> no Relatório. Use <i class="bi bi-pencil"></i> para corrigir um gasto já lançado. Gasto com cliente escolhido também entra como <b>entrada</b> (repasse) — lance na conta dele pelo botão “CC”. Gastos com <i class="bi bi-lock"></i> vieram de reembolsos e são alterados em Financeiro › Reembolsos.</div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold border-bottom">Lançar gasto</div><div class="card-body">
      <form method="post" enctype="multipart/form-data"><input type="hidden" name="acao" value="add_gasto"><input type="hidden" name="aba" value="gastos">
        <div class="mb-2"><label class="form-label small mb-1">Descrição *</label><input type="text" name="descricao" class="form-control form-control-sm" placeholder="Ex: Diesel caminhão de apoio" required></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Valor (R$) *</label><input type="text" name="valor" class="form-control form-control-sm" placeholder="0,00" required></div>
          <div class="col-6"><label class="form-label small mb-1">Data</label><input type="date" name="data" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="mb-2"><label class="form-label small mb-1">Categoria</label><select name="categoria_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label small mb-1">Fornecedor / local</label><input type="text" name="fornecedor" class="form-control form-control-sm"></div>
        <div class="mb-2"><label class="form-label small mb-1">Cobrar de um cliente? <span class="text-muted">(opcional)</span></label>
          <select name="cliente_id" class="form-select form-select-sm"><option value="">Não — custo da equipe</option><?php foreach ($participantes as $p): ?><option value="<?= $p['cliente_id'] ?>"><?= htmlspecialchars($p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label small mb-1">Comprovante (foto ou PDF)</label><input type="file" name="comprovante" class="form-control form-control-sm" accept="image/*,application/pdf"></div>
        <button class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Lançar gasto</button>
      </form>
    </div></div>
  </div>
</div>

<?php elseif ($aba === 'staff'):
  $stTot = 0; $stPago = 0; foreach ($staffPag as $s) { $stTot += (float)$s['total']; if ($s['pago']) $stPago += (float)$s['total']; }
  $diTot = 0; foreach ($diariasCorrida as $d) $diTot += (float)$d['quantidade'] * (float)$d['valor_unit'];
  $fmtDias = fn($v) => rtrim(rtrim(number_format((float)$v, 1, ',', ''), '0'), ','); ?>
<?php if (!$staffOk): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Para usar esta aba, rode uma vez o <b>migrate13.php</b> (logado como admin) e depois apague o arquivo.</div><?php endif; ?>
<div class="alert alert-light border small d-flex gap-3 align-items-start">
  <i class="bi bi-person-badge fs-4 text-warning"></i>
  <div>Lance aqui <b>quanto você pagou para cada pessoa trabalhar nesta corrida</b>: freelancers, mecânicos, motoristas, cozinha, apoio. Cada lançamento entra como <b>saída</b> no Relatório.
  Se esse custo vai ser <b>cobrado de um cliente</b>, escolha o cliente: o valor também entra como <b>entrada</b> (repasse) e aparece o botão para lançar na conta corrente dele.
  <div class="mt-1 text-muted"><i class="bi bi-shield-lock me-1"></i>Só o admin vê esta aba. Equipe e clientes não veem valores nem dados de pagamento.</div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Total de staff</div><div class="fs-5 fw-bold"><?= $R($stTot) ?></div><div class="small text-muted"><?= count($staffPag) ?> pessoa(s)/lançamento(s)</div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Já pago</div><div class="fs-5 fw-bold text-success"><?= $R($stPago) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Falta pagar</div><div class="fs-5 fw-bold <?= $stTot - $stPago > 0 ? 'text-danger' : '' ?>"><?= $R($stTot - $stPago) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Diárias da folha (nesta corrida)</div><div class="fs-5 fw-bold"><?= $R($diTot) ?></div><div class="small text-muted">pagas na folha do mês</div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-person-badge me-2 text-warning"></i>Pagamentos de staff nesta corrida</span>
    <?php if ($staffOk): ?><button class="btn btn-sm btn-warning" onclick="editarStaff(null)"><i class="bi bi-plus-lg me-1"></i>Lançar pagamento de staff</button><?php endif; ?>
  </div>
  <?php if ($staffPag): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th>Data</th><th>Pessoa</th><th>Função</th><th>Cálculo</th><th>Cobrar de</th><th>Situação</th><th class="text-end">Total</th><th></th></tr></thead>
    <tbody><?php foreach ($staffPag as $s):
      $js = ['id'=>(int)$s['id'],'usuario_id'=>$s['usuario_id'] ? (int)$s['usuario_id'] : '','nome'=>$s['nome'],'funcao'=>$s['funcao'] ?? '','data'=>$s['data'],'dias'=>(float)$s['dias'],
             'valor_diaria'=>number_format((float)$s['valor_diaria'], 2, ',', '.'),'adicional'=>(float)$s['adicional'] ? number_format((float)$s['adicional'], 2, ',', '.') : '','adicional_desc'=>$s['adicional_desc'] ?? '',
             'pix'=>$s['pix'] ?? '','cliente_id'=>$s['cliente_id'] ? (int)$s['cliente_id'] : '','observacao'=>$s['observacao'] ?? '','cc'=>(bool)$s['cc_id'],'total'=>$R($s['total'])]; ?>
    <tr>
      <td class="text-muted text-nowrap"><?= date('d/m', strtotime($s['data'])) ?></td>
      <td><span class="fw-semibold"><?= htmlspecialchars($s['nome']) ?></span> <?= $s['usuario_id'] ? '<span class="badge bg-light text-dark border">Cadastrado</span>' : '<span class="badge bg-warning text-dark">Freelancer</span>' ?>
        <?php if ($s['pix']): ?><div class="text-muted" style="font-size:.7rem"><i class="bi bi-qr-code me-1"></i><?= htmlspecialchars($s['pix']) ?></div><?php endif; ?>
        <?php if ($s['observacao']): ?><div class="text-muted fst-italic" style="font-size:.7rem"><?= htmlspecialchars($s['observacao']) ?></div><?php endif; ?></td>
      <td><?= htmlspecialchars($s['funcao'] ?? '') ?></td>
      <td class="text-muted text-nowrap"><?php if ((float)$s['valor_diaria'] > 0): ?><?= $fmtDias($s['dias']) ?> dia(s) × <?= $R($s['valor_diaria']) ?><?php endif; ?>
        <?php if ((float)$s['adicional'] > 0): ?><div>+ <?= $R($s['adicional']) ?><?= $s['adicional_desc'] ? ' (' . htmlspecialchars($s['adicional_desc']) . ')' : '' ?></div><?php endif; ?></td>
      <td><?php if ($s['cliente_nome']): ?><?= htmlspecialchars($s['cliente_nome']) ?> <?= $s['cc_id'] ? '<span class="badge bg-success">na CC</span>' : '' ?><?php else: ?><span class="text-muted">Equipe</span><?php endif; ?></td>
      <td><?php if ($s['pago']): ?><span class="badge bg-success">Pago <?= $s['pago_em'] ? date('d/m', strtotime($s['pago_em'])) : '' ?></span> <span class="text-muted"><?= FORMAS_PAGAMENTO[$s['forma']] ?? '' ?></span>
            <?php if ($s['comprovante']): ?><a href="<?= fotoUrl($s['comprovante']) ?>" target="_blank" class="ms-1" title="Comprovante"><i class="bi bi-paperclip"></i></a><?php endif; ?>
          <?php else: ?><span class="badge bg-warning text-dark">A pagar</span>
            <button type="button" class="btn btn-sm btn-success py-0 px-2 ms-1" onclick='pagarStaff(<?= htmlspecialchars(json_encode($js, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'><i class="bi bi-check2 me-1"></i>Pagar</button><?php endif; ?></td>
      <td class="text-end fw-bold"><?= $R($s['total']) ?></td>
      <td class="text-nowrap text-end">
        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" title="Editar" onclick='editarStaff(<?= htmlspecialchars(json_encode($js, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
        <?php if ($s['cliente_id'] && !$s['cc_id']): ?><form method="post" class="d-inline"><input type="hidden" name="acao" value="staff_cc"><input type="hidden" name="aba" value="staff"><input type="hidden" name="staff_id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-success py-0 px-1" title="Lançar na conta do cliente" data-confirm="Lançar <?= $R($s['total']) ?> como débito na conta de <?= htmlspecialchars($s['cliente_nome'], ENT_QUOTES) ?>?"><i class="bi bi-box-arrow-in-right"></i> CC</button></form><?php endif; ?>
        <?php if ($s['pago']): ?><form method="post" class="d-inline"><input type="hidden" name="acao" value="despagar_staff"><input type="hidden" name="aba" value="staff"><input type="hidden" name="staff_id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Desfazer pagamento" data-confirm="Marcar como NÃO pago? O comprovante anexado será apagado."><i class="bi bi-arrow-counterclockwise"></i></button></form><?php endif; ?>
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="del_staff"><input type="hidden" name="aba" value="staff"><input type="hidden" name="staff_id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" title="Excluir" data-confirm="Excluir o pagamento de <?= htmlspecialchars($s['nome'], ENT_QUOTES) ?>?"><i class="bi bi-trash3"></i></button></form>
      </td>
    </tr><?php endforeach; ?></tbody>
    <tfoot class="table-light fw-bold"><tr><td colspan="6">Total de staff da corrida</td><td class="text-end"><?= $R($stTot) ?></td><td></td></tr></tfoot>
  </table></div>
  <?php elseif ($staffOk): ?><p class="text-muted text-center py-4 mb-0">Nenhum pagamento de staff lançado. Clique em <b>Lançar pagamento de staff</b>.</p><?php endif; ?>
  <div class="card-footer bg-white small text-muted"><i class="bi bi-info-circle me-1"></i>Cálculo: <b>dias × valor da diária + adicional</b> (alimentação, hora extra, combustível do próprio carro...). Para um valor fechado, deixe a diária em branco e coloque tudo no adicional.</div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between"><span><i class="bi bi-calendar-check me-2" style="color:#a61e4d"></i>Diárias da folha ligadas a esta corrida</span><a href="folha.php" class="small">Abrir a folha</a></div>
  <?php if ($diariasCorrida): ?>
  <table class="table table-sm mb-0 small align-middle"><thead><tr><th>Data</th><th>Pessoa</th><th>Dias</th><th class="text-end">Valor</th></tr></thead><tbody>
  <?php foreach ($diariasCorrida as $d): ?><tr><td class="text-muted"><?= date('d/m', strtotime($d['data'])) ?></td><td><?= htmlspecialchars($d['nome']) ?></td><td><?= $fmtDias($d['quantidade']) ?> × <?= $R($d['valor_unit']) ?></td><td class="text-end fw-semibold"><?= $R($d['quantidade'] * $d['valor_unit']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="text-muted text-center py-3 mb-0 small">Nenhuma diária da folha ligada a esta corrida.</p><?php endif; ?>
  <div class="card-footer bg-white small text-muted">Estas diárias são de quem está na <b>folha mensal</b> e já entram no Relatório. Não lance a mesma pessoa de novo aqui no staff, senão o custo conta duas vezes.</div>
</div>

<?php elseif ($aba === 'reembolsos'): $rt = fn($k) => array_sum(array_column($reemb, $k)); ?>
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Aprovado</div><div class="fs-5 fw-bold"><?= $R($rt('aprovado')) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">A pagar</div><div class="fs-5 fw-bold text-danger"><?= $R($rt('apagar')) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Já pago</div><div class="fs-5 fw-bold text-success"><?= $R($rt('pago')) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Aguardando aprovação</div><div class="fs-5 fw-bold text-warning"><?= $R($rt('pendente')) ?></div></div></div>
</div>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-wallet2 me-2 text-danger"></i>Reembolsos da equipe nesta corrida</span>
    <span class="d-flex gap-2"><?php if ($rt('n_pend')): ?><a href="aprovacoes.php?aba=reembolsos" class="btn btn-sm btn-warning"><?= (int)$rt('n_pend') ?> para aprovar</a><?php endif; ?>
      <a href="reembolsos.php?onde=<?= $rallyId ?>&sit=todos" class="btn btn-sm btn-danger"><i class="bi bi-cash-stack me-1"></i>Pagar / detalhar</a></span>
  </div>
  <?php if ($reemb): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th>Pessoa</th><th class="text-center">Lançamentos</th><th class="text-end">Aprovado</th><th class="text-end">Pago</th><th class="text-end">A pagar</th><th class="text-end">Em análise</th><th>PIX</th></tr></thead>
    <tbody><?php foreach ($reemb as $p): ?>
    <tr><td class="fw-semibold"><?= htmlspecialchars($p['nome']) ?></td><td class="text-center"><?= (int)$p['n'] ?></td>
      <td class="text-end"><?= $R($p['aprovado']) ?></td><td class="text-end text-success"><?= $R($p['pago']) ?></td>
      <td class="text-end fw-bold <?= $p['apagar'] > 0 ? 'text-danger' : '' ?>"><?= $R($p['apagar']) ?></td><td class="text-end text-warning"><?= $p['pendente'] > 0 ? $R($p['pendente']) : '—' ?></td>
      <td><?= $p['pix_chave'] ? '<i class="bi bi-check-circle text-success"></i>' : '<span class="badge bg-warning text-dark">sem PIX</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot class="table-light fw-bold"><tr><td colspan="2">Total da corrida</td><td class="text-end"><?= $R($rt('aprovado')) ?></td><td class="text-end"><?= $R($rt('pago')) ?></td><td class="text-end text-danger"><?= $R($rt('apagar')) ?></td><td class="text-end"><?= $R($rt('pendente')) ?></td><td></td></tr></tfoot>
  </table></div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhum reembolso lançado para esta corrida.</p><?php endif; ?>
  <div class="card-footer bg-white small text-muted">Reembolsos aprovados entram automaticamente em "Gastos" e no "Relatório" da corrida.</div>
</div>

<?php elseif ($aba === 'relatorio'):
  $L = $fin['lucro']; $maxE = max(array_merge([0], array_values($fin['porTipoE']))) ?: 1; $maxS = max(array_merge([0], array_values($fin['porTipoS']))) ?: 1;
  $maxCat = $fin['porCategoria'] ? max(array_column($fin['porCategoria'], 'total')) : 1;
  $pct = fn($v) => number_format((float)$v, 1, ',', '.') . '%'; ?>
<style>@media print{.nav-tabs,.rally-hero .btn,.navbar,.d-print-none,a.text-muted.small{display:none!important}.card{break-inside:avoid;box-shadow:none!important;border:1px solid #ddd!important}.rally-hero{color:#000!important;background:none!important}}</style>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h5 class="fw-bold mb-0">Resultado financeiro da corrida</h5>
    <div class="small text-muted">Quanto entrou, quanto saiu e quanto sobrou nesta corrida. <i class="bi bi-shield-lock ms-1"></i> Só o admin vê esta tela.</div></div>
  <button class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / salvar PDF</button>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card border-0 shadow-sm p-3 h-100" style="border-left:5px solid #198754!important">
    <div class="small text-muted text-uppercase fw-semibold"><i class="bi bi-arrow-down-circle me-1 text-success"></i>Entradas</div>
    <div class="fs-3 fw-bold text-success"><?= $R($fin['totE']) ?></div>
    <div class="small text-muted">O que a corrida traz para a equipe: OS dos carros, repasses cobrados dos clientes e navegadores.</div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm p-3 h-100" style="border-left:5px solid #dc3545!important">
    <div class="small text-muted text-uppercase fw-semibold"><i class="bi bi-arrow-up-circle me-1 text-danger"></i>Saídas</div>
    <div class="fs-3 fw-bold text-danger"><?= $R($fin['totS']) ?></div>
    <div class="small text-muted">O que você gastou para fazer a corrida: gastos, reembolsos, staff, diárias e peças das OS.</div></div></div>
  <div class="col-md-4"><div class="card border-0 shadow-sm p-3 h-100 bg-dark text-white">
    <div class="small opacity-75 text-uppercase fw-semibold"><i class="bi bi-graph-up-arrow me-1"></i><?= $L >= 0 ? 'Lucro da corrida' : 'Prejuízo da corrida' ?></div>
    <div class="fs-3 fw-bold" style="color:<?= $L >= 0 ? '#4FDCB2' : '#ff6b6b' ?>"><?= $R($L) ?></div>
    <div class="small opacity-75">Entradas − Saídas<?= $fin['margem'] !== null ? ' · margem de ' . $pct($fin['margem']) : '' ?></div></div></div>
</div>

<?php if ($fin['aReceber'] > 0 || $fin['aPagarStaff'] > 0 || $fin['aPagarReemb'] > 0 || $fin['reembPendente'] > 0): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body py-2 small">
  <div class="fw-semibold mb-1"><i class="bi bi-hourglass-split me-1 text-warning"></i>O que ainda falta acertar</div>
  <ul class="mb-0 ps-3">
    <?php if ($fin['aReceber'] > 0): ?><li><b><?= $R($fin['aReceber']) ?></b> de repasses ainda <b>não lançados na conta do cliente</b> (já contam como entrada). Lance pelo botão “CC” nas abas Gastos e Pagamento de staff.</li><?php endif; ?>
    <?php if ($fin['aPagarStaff'] > 0): ?><li><b><?= $R($fin['aPagarStaff']) ?></b> de staff <b>ainda não pago</b> (já conta como saída). <a href="?id=<?= $rallyId ?>&aba=staff" class="d-print-none">Pagar</a></li><?php endif; ?>
    <?php if ($fin['aPagarReemb'] > 0): ?><li><b><?= $R($fin['aPagarReemb']) ?></b> de reembolsos aprovados <b>ainda não pagos</b> (já contam como saída). <a href="reembolsos.php?onde=<?= $rallyId ?>&sit=todos" class="d-print-none">Pagar</a></li><?php endif; ?>
    <?php if ($fin['reembPendente'] > 0): ?><li><b><?= $R($fin['reembPendente']) ?></b> de reembolsos <b>aguardando sua aprovação</b> — ainda <b>não</b> entram na conta. <a href="aprovacoes.php?aba=reembolsos" class="d-print-none">Analisar</a></li><?php endif; ?>
  </ul>
</div></div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-white fw-bold border-bottom"><i class="bi bi-box-arrow-in-down me-2 text-success"></i>De onde veio o dinheiro</div>
    <div class="card-body">
      <?php $temE = false; foreach (CF_ENTRADAS as $k => [$nome, $ic, $cor, $expl]): if ($fin['porTipoE'][$k] <= 0) continue; $temE = true; ?>
      <div class="mb-3">
        <div class="d-flex justify-content-between"><span class="fw-semibold"><i class="bi <?= $ic ?> me-1" style="color:<?= $cor ?>"></i><?= $nome ?></span><span class="fw-bold"><?= $R($fin['porTipoE'][$k]) ?></span></div>
        <div class="progress my-1" style="height:6px"><div class="progress-bar" style="width:<?= $fin['porTipoE'][$k] / $maxE * 100 ?>%;background:<?= $cor ?>"></div></div>
        <div class="small text-muted"><?= $expl ?></div>
      </div>
      <?php endforeach; if (!$temE): ?><p class="text-muted small mb-0">Nenhuma entrada ainda. As entradas aparecem quando você cria OS para os carros desta corrida (com itens aprovados) ou marca gastos/staff para cobrar de um cliente.</p><?php endif; ?>
    </div></div></div>
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-white fw-bold border-bottom"><i class="bi bi-box-arrow-up me-2 text-danger"></i>Para onde foi o dinheiro</div>
    <div class="card-body">
      <?php $temS = false; foreach (CF_SAIDAS as $k => [$nome, $ic, $cor, $expl]): if ($fin['porTipoS'][$k] <= 0) continue; $temS = true; ?>
      <div class="mb-3">
        <div class="d-flex justify-content-between"><span class="fw-semibold"><i class="bi <?= $ic ?> me-1" style="color:<?= $cor ?>"></i><?= $nome ?></span><span class="fw-bold"><?= $R($fin['porTipoS'][$k]) ?></span></div>
        <div class="progress my-1" style="height:6px"><div class="progress-bar" style="width:<?= $fin['porTipoS'][$k] / $maxS * 100 ?>%;background:<?= $cor ?>"></div></div>
        <div class="small text-muted"><?= $expl ?></div>
      </div>
      <?php endforeach; if (!$temS): ?><p class="text-muted small mb-0">Nenhuma saída lançada ainda.</p><?php endif; ?>
      <?php if ($fin['porCategoria']): ?>
      <hr><div class="small fw-semibold mb-2">Saídas por categoria</div>
      <?php foreach ($fin['porCategoria'] as $c): ?>
      <div class="d-flex align-items-center gap-2 small mb-1">
        <span class="text-truncate" style="width:38%"><span class="badge me-1" style="background:<?= htmlspecialchars($c['cor']) ?>">&nbsp;</span><?= htmlspecialchars($c['nome']) ?> <span class="text-muted">(<?= $c['n'] ?>)</span></span>
        <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar" style="width:<?= $c['total'] / $maxCat * 100 ?>%;background:<?= htmlspecialchars($c['cor']) ?>"></div></div>
        <span class="fw-semibold text-end" style="width:28%"><?= $R($c['total']) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-bold border-bottom"><i class="bi bi-car-front me-2 text-primary"></i>Resultado por carro / cliente</div>
  <?php if ($fin['porCliente']): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
    <thead><tr><th>Cliente</th><th class="text-end">Entradas</th><th class="text-end">Custos diretos</th><th class="text-end">Resultado direto</th><th class="text-end">Parte dos custos comuns</th><th class="text-end">Resultado final</th></tr></thead>
    <tbody><?php foreach ($fin['porCliente'] as $c): ?>
    <tr><td class="fw-semibold"><?= $c['carro'] ? '<span class="badge bg-dark me-1">#' . htmlspecialchars($c['carro']) . '</span>' : '' ?><?= htmlspecialchars($c['nome']) ?></td>
      <td class="text-end text-success"><?= $R($c['entradas']) ?></td>
      <td class="text-end text-danger"><?= $c['diretos'] > 0 ? '− ' . $R($c['diretos']) : '—' ?></td>
      <td class="text-end fw-semibold"><?= $R($c['direto']) ?></td>
      <td class="text-end text-muted"><?= $c['rateio'] > 0 ? '− ' . $R($c['rateio']) : '—' ?></td>
      <td class="text-end fw-bold <?= $c['final'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $R($c['final']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="card-footer bg-white small text-muted">
    <b>Custos diretos</b> = peças das OS daquele carro + gastos e staff que você marcou para cobrar daquele cliente.
    <b>Custos comuns</b> = tudo que é da equipe e não foi atribuído a um cliente (<?= $R($fin['custoComum']) ?>), dividido igualmente entre os <?= count($fin['porCliente']) ?> carro(s): <?= $R($fin['rateio']) ?> para cada.
    Um <b>resultado final negativo</b> quer dizer que aquele carro não cobriu a parte dele nos custos da corrida — use para ajustar o que cobrar.
  </div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0 small">Vincule os carros na aba <a href="?id=<?= $rallyId ?>&aba=equipe">Equipe &amp; carros</a> e crie as OS deles para ver o resultado de cada um.</p><?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-bold border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-list-columns-reverse me-2"></i>Extrato da corrida — o que, onde e quando</span>
    <div class="btn-group btn-group-sm d-print-none" role="group">
      <button type="button" class="btn btn-outline-secondary active" data-filtro="">Tudo</button>
      <button type="button" class="btn btn-outline-success" data-filtro="E">Só entradas</button>
      <button type="button" class="btn btn-outline-danger" data-filtro="S">Só saídas</button>
    </div>
  </div>
  <?php if ($fin['extrato']): ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small" id="tabExtrato">
    <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Quem / onde</th><th>Situação</th><th class="text-end">Entrada</th><th class="text-end">Saída</th><th class="text-end">Saldo</th></tr></thead>
    <tbody><?php foreach ($fin['extrato'] as $x): $def = $x['lado'] === 'E' ? CF_ENTRADAS[$x['tipo']] : CF_SAIDAS[$x['tipo']]; ?>
    <tr data-lado="<?= $x['lado'] ?>">
      <td class="text-nowrap text-muted"><?= $x['data'] ? date('d/m/y', strtotime($x['data'])) : '—' ?></td>
      <td class="text-nowrap"><span class="badge" style="background:<?= $def[2] ?>"><i class="bi <?= $def[1] ?> me-1"></i><?= $def[0] ?></span></td>
      <td><?= htmlspecialchars($x['descricao']) ?><?php if (!empty($x['link'])): ?> <a href="<?= $x['link'] ?>" class="d-print-none" title="Abrir"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></td>
      <td class="text-muted"><?= htmlspecialchars($x['quem'] ?? '') ?></td>
      <td class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($x['status'] ?? '') ?></td>
      <td class="text-end text-nowrap text-success fw-semibold"><?= $x['lado'] === 'E' ? $R($x['valor']) : '' ?></td>
      <td class="text-end text-nowrap text-danger fw-semibold"><?= $x['lado'] === 'S' ? $R($x['valor']) : '' ?></td>
      <td class="text-end text-nowrap <?= $x['saldo'] >= 0 ? '' : 'text-danger' ?>"><?= $R($x['saldo']) ?></td>
    </tr><?php endforeach; ?></tbody>
    <tfoot class="table-light fw-bold text-nowrap"><tr><td colspan="5">Totais da corrida</td><td class="text-end text-success"><?= $R($fin['totE']) ?></td><td class="text-end text-danger"><?= $R($fin['totS']) ?></td><td class="text-end <?= $L >= 0 ? 'text-success' : 'text-danger' ?>"><?= $R($L) ?></td></tr></tfoot>
  </table></div>
  <?php else: ?><p class="text-muted text-center py-5 mb-0">Nada lançado nesta corrida ainda.</p><?php endif; ?>
  <div class="card-footer bg-white small text-muted">
    <b>Como ler:</b> cada linha é um dinheiro que entrou (verde) ou saiu (vermelho), na data em que aconteceu. O <b>saldo</b> é o acumulado até aquela linha — o último saldo é o lucro da corrida.
    Itens de OS aguardando aprovação ainda não contam. A data das OS é a data em que a OS foi aberta.
  </div>
</div>
<script>
document.querySelectorAll('[data-filtro]').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('[data-filtro]').forEach(o => o.classList.toggle('active', o === b));
  document.querySelectorAll('#tabExtrato tbody tr').forEach(tr => tr.style.display = (!b.dataset.filtro || tr.dataset.lado === b.dataset.filtro) ? '' : 'none');
}));
</script>

<?php elseif ($aba === 'fotos'): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end"><input type="hidden" name="acao" value="upload_fotos"><input type="hidden" name="aba" value="fotos">
    <div class="col-md-4"><label class="form-label small mb-1">Fotos</label><input type="file" name="fotos[]" class="form-control form-control-sm" accept="image/*" multiple required data-reduzir data-preview="rfPrev"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Carro (opcional)</label><select name="participante_id" class="form-select form-select-sm"><option value="">Foto geral da corrida</option><?php foreach ($participantes as $p): ?><option value="<?= $p['id'] ?>"><?= ($p['numero_carro'] ? '#'.htmlspecialchars($p['numero_carro']).' · ' : '').htmlspecialchars($p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-1">Legenda</label><input type="text" name="descricao" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-cloud-upload me-1"></i>Enviar</button></div>
    <div class="col-12"><div id="rfPrev" class="d-flex gap-1 flex-wrap"></div><div class="form-text">Fotos gerais aparecem para todos os clientes da corrida; fotos de um carro aparecem só para o dono.</div></div>
  </form>
</div></div>
<?php if ($fotos): ?>
<div class="thumb-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
  <?php foreach ($fotos as $f): ?>
  <div><div class="th"><img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="rally" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($f['descricao'] ?? '') ?>">
    <span class="tag"><?= $f['cliente_nome'] ? ($f['numero_carro'] ? '#'.htmlspecialchars($f['numero_carro']).' · ' : '').htmlspecialchars($f['cliente_nome']) : 'Geral' ?></span></div>
    <form method="post" class="text-end"><input type="hidden" name="acao" value="del_foto"><input type="hidden" name="aba" value="fotos"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Apagar foto?"><i class="bi bi-trash3"></i></button></form></div>
  <?php endforeach; ?>
</div>
<?php else: ?><div class="card border-0 shadow-sm text-center py-5 text-muted"><i class="bi bi-images fs-1 d-block mb-2"></i>Nenhuma foto desta corrida ainda.</div><?php endif; ?>
<?php endif; ?>

<!-- Modal informações -->
<div class="modal fade" id="modalInfo" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Informações da corrida</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="post"><input type="hidden" name="acao" value="salvar_info"><input type="hidden" name="aba" value="<?= $aba ?>">
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($rally['nome']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Local</label><input type="text" name="local" class="form-control" value="<?= htmlspecialchars($rally['local'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Início</label><input type="date" name="data_inicio" class="form-control" value="<?= $rally['data_inicio'] ?>"></div>
      <div class="col-md-4"><label class="form-label">Fim</label><input type="date" name="data_fim" class="form-control" value="<?= $rally['data_fim'] ?>"></div>
      <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (RALLY_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= ($rally['status'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Descrição</label><input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($rally['descricao'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Hospedagem</label><textarea name="hospedagem" rows="3" class="form-control" placeholder="Hotel, endereço, check-in..."><?= htmlspecialchars($rally['hospedagem'] ?? '') ?></textarea></div>
      <div class="col-md-6"><label class="form-label">Logística / deslocamento</label><textarea name="logistica" rows="3" class="form-control" placeholder="Saída do caminhão, rota, horários..."><?= htmlspecialchars($rally['logistica'] ?? '') ?></textarea></div>
      <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" rows="2" class="form-control"><?= htmlspecialchars($rally['observacoes'] ?? '') ?></textarea></div>
    </div><div class="form-text mt-2">Hospedagem, logística e observações aparecem para a equipe escalada e para os clientes da corrida.</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
  </form>
</div></div></div>

<!-- Modal participante -->
<div class="modal fade" id="modalPart" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="partTitulo">Vincular carro</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="post"><input type="hidden" name="acao" value="salvar_participante"><input type="hidden" name="aba" value="equipe"><input type="hidden" name="participante_id" id="pId">
    <div class="modal-body"><div class="row g-3">
      <div class="col-12"><label class="form-label">Cliente / piloto *</label><select name="cliente_id" id="pCliente" class="form-select" required onchange="carregarVeiculos()"><option value="">Selecione…</option><?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Carro</label><select name="veiculo_id" id="pVeiculo" class="form-select"><option value="">—</option></select></div>
      <div class="col-6"><label class="form-label">Nº do carro</label><input type="text" name="numero_carro" id="pNum" class="form-control" maxlength="10"></div>
      <div class="col-6"><label class="form-label">Categoria</label><input type="text" name="categoria" id="pCat" class="form-control" placeholder="Ex: UTV T4"></div>
      <div class="col-12"><label class="form-label">Navegador</label><select name="navegador_id" id="pNav" class="form-select"><option value="">—</option><?php foreach ($navegadores as $n): ?><option value="<?= $n['id'] ?>" data-piloto="<?= $n['piloto_id'] ?>"><?= htmlspecialchars($n['nome']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label">Observação</label><input type="text" name="observacao" id="pObs" class="form-control"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Salvar</button></div>
  </form>
</div></div></div>

<!-- Modal editar gasto -->
<div class="modal fade" id="modalGasto" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Editar gasto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="acao" value="edit_gasto"><input type="hidden" name="aba" value="gastos"><input type="hidden" name="gasto_id" id="gId">
    <div class="modal-body"><div class="row g-2">
      <div class="col-12"><label class="form-label small mb-1">Descrição *</label><input type="text" name="descricao" id="gDesc" class="form-control" required></div>
      <div class="col-6"><label class="form-label small mb-1">Valor (R$) *</label><input type="text" name="valor" id="gValor" class="form-control" inputmode="decimal" required></div>
      <div class="col-6"><label class="form-label small mb-1">Data</label><input type="date" name="data" id="gData" class="form-control"></div>
      <div class="col-6"><label class="form-label small mb-1">Categoria</label><select name="categoria_id" id="gCat" class="form-select"><option value="">—</option><?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-1">Fornecedor / local</label><input type="text" name="fornecedor" id="gForn" class="form-control"></div>
      <div class="col-12"><label class="form-label small mb-1">Cobrar de um cliente?</label>
        <select name="cliente_id" id="gCli" class="form-select"><option value="">Não — custo da equipe</option><?php foreach ($participantes as $p): ?><option value="<?= $p['cliente_id'] ?>"><?= htmlspecialchars($p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="alert alert-info small py-2 mb-0 d-none" id="gAvisoCC"><i class="bi bi-lock me-1"></i>Este gasto já foi lançado na conta corrente do cliente, por isso <b>valor e cliente não podem mudar</b> aqui. Para mudar, remova o lançamento na Conta Corrente e depois edite.</div></div>
      <div class="col-12"><label class="form-label small mb-1">Trocar comprovante <span class="text-muted">(opcional — se não escolher, mantém o atual)</span></label><input type="file" name="comprovante" class="form-control form-control-sm" accept="image/*,application/pdf"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar alterações</button></div>
  </form>
</div></div></div>

<!-- Modal staff (lançar / editar) -->
<div class="modal fade" id="modalStaff" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="sTitulo">Lançar pagamento de staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="acao" value="salvar_staff"><input type="hidden" name="aba" value="staff"><input type="hidden" name="staff_id" id="sId">
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label small mb-1">Quem trabalhou</label>
        <select name="usuario_id" id="sUser" class="form-select" onchange="staffPessoa()"><option value="">Freelancer / pessoa de fora (digite o nome)</option>
          <?php foreach ($staff as $p): ?><option value="<?= $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome']) ?>" data-funcao="<?= htmlspecialchars($p['funcao'] ?? '') ?>" data-pix="<?= htmlspecialchars($p['pix_chave'] ? pixFmt($p['pix_tipo'], $p['pix_chave']) : '') ?>"><?= htmlspecialchars($p['nome']) ?> (<?= ucfirst($p['tipo']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label small mb-1">Nome *</label><input type="text" name="nome" id="sNome" class="form-control" maxlength="120" placeholder="Nome do freelancer"></div>
      <div class="col-md-6"><label class="form-label small mb-1">Função</label><input type="text" name="funcao" id="sFuncao" class="form-control" list="funcoesStaff" placeholder="Ex: Mecânico freelancer"></div>
      <div class="col-md-6"><label class="form-label small mb-1">Data (início do trabalho)</label><input type="date" name="data" id="sData" class="form-control"></div>
      <div class="col-4 col-md-2"><label class="form-label small mb-1">Dias</label><input type="number" name="dias" id="sDias" class="form-control" min="0" step="0.5" value="1" oninput="staffCalc()"></div>
      <div class="col-8 col-md-4"><label class="form-label small mb-1">Valor da diária (R$)</label><input type="text" name="valor_diaria" id="sDiaria" class="form-control" inputmode="decimal" placeholder="0,00" oninput="staffCalc()"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Adicional (R$)</label><input type="text" name="adicional" id="sAdic" class="form-control" inputmode="decimal" placeholder="0,00" oninput="staffCalc()"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Adicional é de quê?</label><input type="text" name="adicional_desc" id="sAdicDesc" class="form-control" placeholder="Alimentação, hora extra..."></div>
      <div class="col-12"><div class="p-2 rounded bg-light d-flex justify-content-between align-items-center"><span class="small text-muted">Total a pagar (dias × diária + adicional)</span><span class="fs-5 fw-bold" id="sTotal">R$ 0,00</span></div>
        <div class="alert alert-info small py-2 mt-2 mb-0 d-none" id="sAvisoCC"><i class="bi bi-lock me-1"></i>Já lançado na conta corrente do cliente: <b>valores e cliente não mudam</b> aqui.</div></div>
      <div class="col-md-6"><label class="form-label small mb-1">PIX / dados para pagamento</label><input type="text" name="pix" id="sPix" class="form-control" maxlength="190" placeholder="Chave PIX ou banco/agência/conta"></div>
      <div class="col-md-6"><label class="form-label small mb-1">Cobrar de um cliente? <span class="text-muted">(opcional)</span></label>
        <select name="cliente_id" id="sCli" class="form-select"><option value="">Não — custo da equipe</option><?php foreach ($participantes as $p): ?><option value="<?= $p['cliente_id'] ?>"><?= htmlspecialchars($p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label small mb-1">Observação</label><input type="text" name="observacao" id="sObs" class="form-control" maxlength="255"></div>
      <div class="col-12" id="sBlocoPago">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="ja_pago" value="1" id="sJaPago" onchange="document.getElementById('sPagoCampos').classList.toggle('d-none',!this.checked)"><label class="form-check-label" for="sJaPago">Já paguei esta pessoa</label></div>
        <div class="row g-2 mt-1 d-none" id="sPagoCampos">
          <div class="col-md-4"><label class="form-label small mb-1">Pago em</label><input type="date" name="pago_em" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-4"><label class="form-label small mb-1">Forma</label><select name="forma" class="form-select form-select-sm"><?php foreach (FORMAS_PAGAMENTO as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small mb-1">Comprovante</label><input type="file" name="comprovante" class="form-control form-control-sm" accept="image/*,application/pdf"></div>
        </div>
      </div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning"><i class="bi bi-save me-1"></i>Salvar</button></div>
  </form>
</div></div></div>
<datalist id="funcoesStaff"><?php foreach (EQUIPE_FUNCOES as $f): ?><option value="<?= $f ?>"><?php endforeach; ?><option value="Mecânico freelancer"></datalist>

<!-- Modal pagar staff -->
<div class="modal fade" id="modalPagarStaff" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cash-stack me-2 text-success"></i>Registrar pagamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="acao" value="pagar_staff"><input type="hidden" name="aba" value="staff"><input type="hidden" name="staff_id" id="pgId">
    <div class="modal-body">
      <div class="p-2 rounded bg-light mb-3"><div class="fw-semibold" id="pgNome"></div><div class="fs-4 fw-bold text-success" id="pgValor"></div><div class="small text-muted" id="pgPix"></div></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-1">Pago em</label><input type="date" name="pago_em" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Forma</label><select name="forma" class="form-select"><?php foreach (FORMAS_PAGAMENTO as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-1">Comprovante (foto ou PDF)</label><input type="file" name="comprovante" class="form-control" accept="image/*,application/pdf"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success"><i class="bi bi-check2 me-1"></i>Confirmar pagamento</button></div>
  </form>
</div></div></div>

<?php fotosModalGaleria(); ?>
<script>
const VEIC=<?= json_encode($veicPorCliente, JSON_UNESCAPED_UNICODE) ?>;
function carregarVeiculos(sel){
  const c=document.getElementById('pCliente').value, s=document.getElementById('pVeiculo');
  s.innerHTML='<option value="">—</option>'+(VEIC[c]||[]).map(v=>`<option value="${v.id}">${v.nome.replace(/</g,'&lt;')}</option>`).join('');
  if(sel) s.value=sel; else if((VEIC[c]||[]).length===1) s.value=VEIC[c][0].id;
  // sugere o navegador vinculado ao piloto
  const nav=document.getElementById('pNav'); if(!sel && !nav.value){ const o=[...nav.options].find(o=>o.dataset.piloto==c); if(o) nav.value=o.value; }
}
function editarParticipante(p){
  document.getElementById('partTitulo').textContent=p?'Editar carro na corrida':'Vincular carro';
  document.getElementById('pId').value=p?p.id:''; document.getElementById('pCliente').value=p?p.cliente_id:'';
  document.getElementById('pNav').value=p&&p.navegador_id?p.navegador_id:'';
  carregarVeiculos(p?p.veiculo_id:null);
  document.getElementById('pNum').value=p?(p.numero_carro||''):''; document.getElementById('pCat').value=p?(p.categoria||''):''; document.getElementById('pObs').value=p?(p.observacao||''):'';
  new bootstrap.Modal(document.getElementById('modalPart')).show();
}
function editarEvento(e){
  document.getElementById('tituloEvento').textContent='Editar evento';
  evId.value=e.id; evTitulo.value=e.titulo; evData.value=e.data; evTipo.value=e.tipo; evIni.value=(e.hora_inicio||'').slice(0,5); evFim.value=(e.hora_fim||'').slice(0,5); evLocal.value=e.local||''; evMaps.value=e.maps_url||''; evObs.value=e.observacao||'';
  evTitulo.focus(); window.scrollTo({top:document.getElementById('formEvento').getBoundingClientRect().top+scrollY-120,behavior:'smooth'});
}
const $id=s=>document.getElementById(s);
const brNum=v=>parseFloat(String(v||'').replace(/\./g,'').replace(',','.'))||0;
const brR=v=>'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
function editarGasto(g){
  $id('gId').value=g.id; $id('gDesc').value=g.descricao; $id('gValor').value=g.valor; $id('gData').value=g.data||''; $id('gCat').value=g.categoria_id||''; $id('gForn').value=g.fornecedor||''; $id('gCli').value=g.cliente_id||'';
  $id('gValor').readOnly=!!g.cc; $id('gCli').disabled=!!g.cc; $id('gAvisoCC').classList.toggle('d-none',!g.cc);
  new bootstrap.Modal($id('modalGasto')).show();
}
function staffCalc(){ const d=parseFloat(String($id('sDias').value||'0').replace(',','.'))||0; $id('sTotal').textContent=brR(d*brNum($id('sDiaria').value)+brNum($id('sAdic').value)); }
function staffPessoa(){ const o=$id('sUser').selectedOptions[0]; if(o&&o.value){ $id('sNome').value=o.dataset.nome; if(!$id('sFuncao').value) $id('sFuncao').value=o.dataset.funcao||''; if(!$id('sPix').value) $id('sPix').value=o.dataset.pix||''; } }
function editarStaff(s){
  $id('sTitulo').textContent=s?'Editar pagamento de staff':'Lançar pagamento de staff';
  $id('sId').value=s?s.id:''; $id('sUser').value=s?s.usuario_id:''; $id('sNome').value=s?s.nome:''; $id('sFuncao').value=s?s.funcao:''; $id('sData').value=s?s.data:'<?= $rally['data_inicio'] ?: date('Y-m-d') ?>';
  $id('sDias').value=s?s.dias:1; $id('sDiaria').value=s?s.valor_diaria:''; $id('sAdic').value=s?s.adicional:''; $id('sAdicDesc').value=s?s.adicional_desc:'';
  $id('sPix').value=s?s.pix:''; $id('sCli').value=s?s.cliente_id:''; $id('sObs').value=s?s.observacao:'';
  const cc=!!(s&&s.cc); ['sDias','sDiaria','sAdic'].forEach(i=>$id(i).readOnly=cc); $id('sCli').disabled=cc; $id('sAvisoCC').classList.toggle('d-none',!cc);
  $id('sBlocoPago').classList.toggle('d-none',!!s); $id('sJaPago').checked=false; $id('sPagoCampos').classList.add('d-none');
  staffCalc(); new bootstrap.Modal($id('modalStaff')).show();
}
function pagarStaff(s){
  $id('pgId').value=s.id; $id('pgNome').textContent=s.nome+(s.funcao?' · '+s.funcao:''); $id('pgValor').textContent=s.total;
  $id('pgPix').textContent=s.pix?('PIX / dados: '+s.pix):'Sem PIX informado neste lançamento';
  new bootstrap.Modal($id('modalPagarStaff')).show();
}
function novoEvento(){ document.getElementById('tituloEvento').textContent='Novo evento'; document.getElementById('formEvento').reset(); evId.value=''; }
</script>
<?php require_once '../includes/footer.php'; ?>
