<?php
// includes/corrida_financeiro.php – v14: resultado financeiro de uma corrida. USO EXCLUSIVO DO ADMIN.
// ENTRADAS = dinheiro que a corrida traz para a equipe:
//   • OS dos carros (itens aprovados — o que o cliente paga)
//   • gastos e staff marcados como "cobrar do cliente" (repasse)
//   • OS de navegador cobradas do piloto
// SAÍDAS = dinheiro que a equipe gastou para fazer a corrida:
//   • gastos lançados, reembolsos aprovados da equipe, pagamento de staff, diárias da folha ligadas à corrida,
//     custo das peças usadas nas OS (preço de custo) e OS de navegador que a equipe assumiu
// LUCRO = ENTRADAS − SAÍDAS

const CF_ENTRADAS = [
    'os'      => ['OS dos carros', 'bi-clipboard-check', '#0d6efd', 'Serviços e peças aprovados nas ordens de serviço dos carros desta corrida. É o que cada cliente paga para a equipe.'],
    'repasse' => ['Repasses cobrados do cliente', 'bi-arrow-left-right', '#6f42c1', 'Gastos e pagamentos de staff que você marcou como "cobrar do cliente". Entram como saída (você pagou) e como entrada (o cliente devolve).'],
    'nav'     => ['OS de navegador (cobrado do piloto)', 'bi-compass', '#fd7e14', 'Pedidos dos navegadores que serão cobrados do piloto.'],
];
const CF_SAIDAS = [
    'gasto'      => ['Gastos da corrida', 'bi-cash-coin', '#dc3545', 'Tudo o que foi lançado na aba Gastos: combustível, hospedagem, alimentação, pedágio, frete...'],
    'reembolso'  => ['Reembolsos da equipe', 'bi-wallet2', '#d63384', 'O que a equipe pagou do bolso e você aprovou para devolver no PIX.'],
    'staff'      => ['Pagamento de staff', 'bi-person-badge', '#e8590c', 'Freelancers, mecânicos e apoio pagos para trabalhar nesta corrida (aba Pagamento de staff).'],
    'diaria'     => ['Diárias da folha', 'bi-calendar-check', '#a61e4d', 'Diárias lançadas na Folha de pagamento ligadas a esta corrida.'],
    'custo_os'   => ['Custo das peças das OS', 'bi-box-seam', '#795548', 'Quanto custaram para a equipe as peças e materiais usados nas OS (o "valor de custo" de cada item).'],
    'nav_equipe' => ['OS de navegador pagas pela equipe', 'bi-compass', '#6c757d', 'Pedidos de navegador que a equipe decidiu assumir (não cobra do piloto).'],
];

/** Monta entradas, saídas, lucro, resultado por cliente e extrato de uma corrida. */
function corridaFinanceiro(PDO $pdo, int $rallyId): array {
    $E = []; $S = [];
    $q = function (string $sql) use ($pdo, $rallyId) { $s = $pdo->prepare($sql); $s->execute([$rallyId]); return $s->fetchAll(); };
    $dia = fn($d) => $d ? substr((string)$d, 0, 10) : null;

    // 1) OS dos carros: valor aprovado = entrada; custo das peças = saída (custo direto daquele cliente)
    foreach ($q("SELECT os.id, os.numero_os, os.titulo, os.status, os.criado_em, os.cliente_id, uc.nome AS cliente, os.valor_total,
                    (SELECT COALESCE(SUM(COALESCE(oi.valor_custo,0)*oi.quantidade),0) FROM os_itens oi WHERE oi.ordem_servico_id=os.id AND oi.status_aprovacao='aprovado') AS custo,
                    (SELECT COUNT(*) FROM os_itens oi WHERE oi.ordem_servico_id=os.id AND oi.status_aprovacao='pendente') AS pend
                 FROM ordens_servico os JOIN usuarios uc ON uc.id=os.cliente_id
                 WHERE os.rally_id=? AND os.status<>'cancelada' ORDER BY os.criado_em") as $o) {
        $ref = 'OS ' . ($o['numero_os'] ?: $o['id']);
        if ((float)$o['valor_total'] > 0)
            $E[] = ['tipo' => 'os', 'data' => $dia($o['criado_em']), 'descricao' => $ref . ' – ' . $o['titulo'], 'quem' => $o['cliente'], 'cliente_id' => (int)$o['cliente_id'],
                    'valor' => (float)$o['valor_total'], 'status' => ($o['pend'] ? $o['pend'] . ' item(ns) aguardando aprovação (ainda fora do total)' : ''), 'link' => 'ver_os.php?id=' . $o['id']];
        if ((float)$o['custo'] > 0)
            $S[] = ['tipo' => 'custo_os', 'data' => $dia($o['criado_em']), 'descricao' => 'Peças/materiais – ' . $ref . ' – ' . $o['titulo'], 'quem' => $o['cliente'], 'cliente_id' => (int)$o['cliente_id'],
                    'valor' => (float)$o['custo'], 'status' => '', 'categoria' => 'Peças das OS', 'cor' => '#795548', 'link' => 'ver_os.php?id=' . $o['id']];
    }

    // 2) OS de navegador: cobrada do piloto = entrada; assumida pela equipe = saída
    foreach ($q("SELECT n.id, n.titulo, n.status, n.cobrar_de, n.criado_em, n.piloto_id, p.nome AS piloto, u.nome AS navegador,
                    (SELECT COALESCE(SUM(ni.valor),0) FROM nav_os_itens ni WHERE ni.nav_os_id=n.id) AS total
                 FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id JOIN usuarios p ON p.id=n.piloto_id WHERE n.rally_id=?") as $n) {
        if ((float)$n['total'] <= 0) continue;
        $st = ['aberta' => 'Aberta', 'encerrada' => 'Aguardando aprovação', 'concluida' => 'Aprovada'][$n['status']] ?? $n['status'];
        $linha = ['data' => $dia($n['criado_em']), 'descricao' => 'Navegador ' . $n['navegador'] . ' – ' . $n['titulo'], 'valor' => (float)$n['total'], 'status' => $st, 'link' => 'aprovar_nav_os.php?id=' . $n['id']];
        if ($n['cobrar_de'] === 'equipe') $S[] = $linha + ['tipo' => 'nav_equipe', 'quem' => $n['navegador'], 'cliente_id' => null, 'categoria' => 'Navegadores', 'cor' => '#6c757d'];
        else $E[] = $linha + ['tipo' => 'nav', 'quem' => $n['piloto'], 'cliente_id' => (int)$n['piloto_id']];
    }

    // 3) Gastos (inclui reembolsos aprovados). Gasto com cliente também entra como repasse (entrada)
    foreach ($q("SELECT g.*, c.nome AS cat_nome, c.cor AS cat_cor, u.nome AS cliente_nome FROM rally_gastos g
                 LEFT JOIN rally_categorias c ON c.id=g.categoria_id LEFT JOIN usuarios u ON u.id=g.cliente_id WHERE g.rally_id=? ORDER BY g.data, g.id") as $g) {
        $tipo = !empty($g['reembolso_id']) ? 'reembolso' : 'gasto';
        $S[] = ['tipo' => $tipo, 'data' => $g['data'], 'descricao' => $g['descricao'], 'quem' => $g['fornecedor'] ?: ($tipo === 'reembolso' ? 'Equipe' : ''),
                'cliente_id' => $g['cliente_id'] ? (int)$g['cliente_id'] : null, 'valor' => (float)$g['valor'], 'status' => '',
                'categoria' => $g['cat_nome'] ?: 'Sem categoria', 'cor' => $g['cat_cor'] ?: '#adb5bd', 'link' => null];
        if ($g['cliente_id'])
            $E[] = ['tipo' => 'repasse', 'data' => $g['data'], 'descricao' => 'Repasse do gasto: ' . $g['descricao'], 'quem' => $g['cliente_nome'], 'cliente_id' => (int)$g['cliente_id'],
                    'valor' => (float)$g['valor'], 'status' => $g['cc_id'] ? 'Lançado na conta do cliente' : 'Falta lançar na conta do cliente', 'link' => null, 'pendente' => !$g['cc_id']];
    }

    // 4) Pagamento de staff (tabela da v14 — se a migração ainda não rodou, ignora)
    try {
        foreach ($q("SELECT s.*, u.nome AS cliente_nome FROM rally_staff s LEFT JOIN usuarios u ON u.id=s.cliente_id WHERE s.rally_id=? ORDER BY s.data, s.id") as $s) {
            $S[] = ['tipo' => 'staff', 'data' => $s['data'], 'descricao' => trim(($s['funcao'] ? $s['funcao'] . ' – ' : '') . $s['nome']), 'quem' => $s['nome'],
                    'cliente_id' => $s['cliente_id'] ? (int)$s['cliente_id'] : null, 'valor' => (float)$s['total'],
                    'status' => $s['pago'] ? 'Pago' . ($s['pago_em'] ? ' em ' . date('d/m', strtotime($s['pago_em'])) : '') : 'A pagar',
                    'categoria' => 'Staff', 'cor' => '#e8590c', 'link' => null, 'apagar' => !$s['pago']];
            if ($s['cliente_id'])
                $E[] = ['tipo' => 'repasse', 'data' => $s['data'], 'descricao' => 'Repasse do staff: ' . $s['nome'], 'quem' => $s['cliente_nome'], 'cliente_id' => (int)$s['cliente_id'],
                        'valor' => (float)$s['total'], 'status' => $s['cc_id'] ? 'Lançado na conta do cliente' : 'Falta lançar na conta do cliente', 'link' => null, 'pendente' => !$s['cc_id']];
        }
    } catch (PDOException $e) { /* migrate13 ainda não rodou */ }

    // 5) Diárias da folha ligadas à corrida
    foreach ($q("SELECT d.*, u.nome FROM folha_diarias d JOIN usuarios u ON u.id=d.usuario_id WHERE d.rally_id=? ORDER BY d.data") as $d) {
        $qtd = (float)$d['quantidade'];
        $S[] = ['tipo' => 'diaria', 'data' => $d['data'], 'descricao' => 'Diária – ' . $d['nome'] . ($qtd != 1 ? ' (' . rtrim(rtrim(number_format($qtd, 1, ',', ''), '0'), ',') . ' dias)' : ''),
                'quem' => $d['nome'], 'cliente_id' => null, 'valor' => $qtd * (float)$d['valor_unit'], 'status' => 'Paga na folha do mês',
                'categoria' => 'Diárias (folha)', 'cor' => '#a61e4d', 'link' => 'folha.php'];
    }

    // Totais por tipo e por categoria
    $totE = array_sum(array_column($E, 'valor')); $totS = array_sum(array_column($S, 'valor'));
    $porTipoE = array_fill_keys(array_keys(CF_ENTRADAS), 0.0); foreach ($E as $x) $porTipoE[$x['tipo']] += $x['valor'];
    $porTipoS = array_fill_keys(array_keys(CF_SAIDAS), 0.0);   foreach ($S as $x) $porTipoS[$x['tipo']] += $x['valor'];
    $porCat = [];
    foreach ($S as $x) { $k = $x['categoria']; $porCat[$k] ??= ['nome' => $k, 'cor' => $x['cor'], 'total' => 0.0, 'n' => 0]; $porCat[$k]['total'] += $x['valor']; $porCat[$k]['n']++; }
    uasort($porCat, fn($a, $b) => $b['total'] <=> $a['total']);

    // Por cliente: entradas, custos diretos (peças das OS + gastos/staff cobrados dele) e rateio dos custos comuns
    $cli = [];
    foreach ($q("SELECT p.cliente_id, c.nome, p.numero_carro FROM rally_participantes p JOIN usuarios c ON c.id=p.cliente_id WHERE p.rally_id=? ORDER BY c.nome") as $p)
        $cli[(int)$p['cliente_id']] ??= ['nome' => $p['nome'], 'carro' => $p['numero_carro'], 'entradas' => 0.0, 'diretos' => 0.0];
    foreach ($E as $x) if ($x['cliente_id']) {
        $cli[$x['cliente_id']] ??= ['nome' => $x['quem'], 'carro' => null, 'entradas' => 0.0, 'diretos' => 0.0];
        $cli[$x['cliente_id']]['entradas'] += $x['valor'];
    }
    $comum = 0.0;
    foreach ($S as $x) {
        if ($x['cliente_id'] && isset($cli[$x['cliente_id']])) $cli[$x['cliente_id']]['diretos'] += $x['valor'];
        else $comum += $x['valor'];
    }
    $nCli = count($cli); $rateio = $nCli ? $comum / $nCli : 0.0;
    foreach ($cli as &$c) { $c['direto'] = $c['entradas'] - $c['diretos']; $c['rateio'] = $rateio; $c['final'] = $c['direto'] - $rateio; } unset($c);

    // Extrato: tudo em ordem de data, com saldo acumulado
    $ext = [];
    foreach ($E as $x) $ext[] = $x + ['lado' => 'E'];
    foreach ($S as $x) $ext[] = $x + ['lado' => 'S'];
    usort($ext, fn($a, $b) => [($a['data'] ?? '9999-99-99'), $a['lado']] <=> [($b['data'] ?? '9999-99-99'), $b['lado']]);
    $saldo = 0.0;
    foreach ($ext as &$x) { $saldo += $x['lado'] === 'E' ? $x['valor'] : -$x['valor']; $x['saldo'] = $saldo; } unset($x);

    // Pendências (o que ainda falta acertar)
    $aReceber = 0.0; foreach ($E as $x) if (!empty($x['pendente'])) $aReceber += $x['valor'];
    $aPagarStaff = 0.0; foreach ($S as $x) if (!empty($x['apagar'])) $aPagarStaff += $x['valor'];
    $r = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='aprovado' AND pago=0 THEN valor END),0) AS apagar,
                               COALESCE(SUM(CASE WHEN status='pendente' THEN valor END),0) AS pend FROM reembolsos WHERE rally_id=?");
    $r->execute([$rallyId]); $r = $r->fetch();

    return ['entradas' => $E, 'saidas' => $S, 'totE' => $totE, 'totS' => $totS, 'lucro' => $totE - $totS,
            'margem' => $totE > 0 ? ($totE - $totS) / $totE * 100 : null,
            'porTipoE' => $porTipoE, 'porTipoS' => $porTipoS, 'porCategoria' => $porCat,
            'porCliente' => $cli, 'custoComum' => $comum, 'rateio' => $rateio, 'extrato' => $ext,
            'aReceber' => $aReceber, 'aPagarStaff' => $aPagarStaff, 'aPagarReemb' => (float)$r['apagar'], 'reembPendente' => (float)$r['pend']];
}
