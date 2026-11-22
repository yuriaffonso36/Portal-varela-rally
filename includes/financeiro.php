<?php
// includes/financeiro.php – Fase 3: funções da Oficina (despesas, gastos fixos) e da folha de pagamento
require_once __DIR__ . '/aprovacoes.php';

const FORMAS_PAGAMENTO = ['pix' => 'PIX', 'dinheiro' => 'Dinheiro', 'cartao' => 'Cartão', 'boleto' => 'Boleto', 'transferencia' => 'Transferência', 'outro' => 'Outro'];
const MESES_PT = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** Competência AAAA-MM vinda da URL (ou o mês atual). */
function finComp(?string $c): string { return ($c && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $c)) ? $c : date('Y-m'); }
function finCompLabel(string $c): string { [$a, $m] = explode('-', $c); return MESES_PT[(int)$m] . '/' . $a; }
function finCompMais(string $c, int $n): string { return date('Y-m', strtotime($c . '-01 ' . ($n >= 0 ? '+' : '') . $n . ' month')); }
function finR($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function finData(?string $d): ?string { return ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null; }

/** Salva comprovante (foto reduzida ou PDF) em uploads/$subdir. Retorna [caminho|null, erro|null]. */
function finSalvarArquivo(array $file, string $subdir, string $prefixo): array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) return [null, 'Falha no envio do comprovante.'];
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
        $dir = UPLOAD_DIR . $subdir . '/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nome = preg_replace('/[^a-z0-9_]/i', '', $prefixo) . '_' . date('ymd') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        return move_uploaded_file($file['tmp_name'], $dir . $nome) ? [$subdir . '/' . $nome, null] : [null, 'Não foi possível gravar o PDF.'];
    }
    $r = fotoSalvarUpload($file, $subdir, $prefixo);
    if (isset($r['erro'])) return [null, $r['erro']];
    fotoApagar($r['thumb']);
    return [$r['caminho'], null];
}

function finCategoriaId(PDO $pdo, string $nome): ?int {
    $s = $pdo->prepare("SELECT id FROM despesa_categorias WHERE nome=? ORDER BY ativo DESC, id LIMIT 1"); $s->execute([$nome]);
    return ($id = $s->fetchColumn()) ? (int)$id : null;
}

function finCategorias(PDO $pdo, bool $soAtivas = true): array {
    return $pdo->query("SELECT * FROM despesa_categorias" . ($soAtivas ? " WHERE ativo=1" : "") . " ORDER BY nome")->fetchAll();
}

/** Lança uma despesa da oficina. Retorna o id. */
function finLancarDespesa(PDO $pdo, array $d): int {
    $pdo->prepare("INSERT INTO despesas (categoria_id,descricao,valor,data,forma_pagamento,fornecedor,comprovante,origem,fixa_id,competencia,ref_id,lancado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$d['categoria_id'] ?? null, mb_substr($d['descricao'], 0, 255), $d['valor'], $d['data'] ?? date('Y-m-d'),
                   isset(FORMAS_PAGAMENTO[$d['forma_pagamento'] ?? '']) ? $d['forma_pagamento'] : 'pix',
                   $d['fornecedor'] ?? null, $d['comprovante'] ?? null, $d['origem'] ?? 'manual', $d['fixa_id'] ?? null,
                   $d['competencia'] ?? null, $d['ref_id'] ?? null, $d['lancado_por'] ?? null]);
    return (int)$pdo->lastInsertId();
}

/** Gastos fixos ativos com a situação na competência (pago = existe despesa da fixa naquele mês). */
function finFixasDoMes(PDO $pdo, string $comp): array {
    $s = $pdo->prepare("SELECT f.*, c.nome AS cat_nome, c.cor AS cat_cor, d.id AS despesa_id, d.valor AS valor_pago, d.data AS data_pago, d.comprovante AS comp_pago
        FROM despesas_fixas f LEFT JOIN despesa_categorias c ON c.id=f.categoria_id
        LEFT JOIN despesas d ON d.fixa_id=f.id AND d.competencia=? AND d.origem='fixa'
        WHERE f.ativo=1 OR d.id IS NOT NULL ORDER BY f.dia_vencimento, f.descricao");
    $s->execute([$comp]); $lista = $s->fetchAll();
    $ultimoDia = (int)date('t', strtotime($comp . '-01'));
    foreach ($lista as &$f) {
        $f['vencimento'] = $comp . '-' . str_pad((string)min((int)$f['dia_vencimento'], $ultimoDia), 2, '0', STR_PAD_LEFT);
        $f['atrasada'] = !$f['despesa_id'] && $f['vencimento'] < date('Y-m-d');
    }
    return $lista;
}

/* ───────────── Folha ───────────── */

/** Diárias de uma pessoa na competência: [qtd, total, total_em_corrida]. */
function folhaDiarias(PDO $pdo, int $uid, string $comp): array {
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantidade),0) q, COALESCE(SUM(quantidade*valor_unit),0) t, COALESCE(SUM(CASE WHEN rally_id IS NOT NULL THEN quantidade*valor_unit ELSE 0 END),0) c
                        FROM folha_diarias WHERE usuario_id=? AND DATE_FORMAT(data,'%Y-%m')=?");
    $s->execute([$uid, $comp]); $r = $s->fetch();
    return [(float)$r['q'], (float)$r['t'], (float)$r['c']];
}

/** Linha da folha está paga? (bloqueia mexer em diárias daquele mês) */
function folhaPaga(PDO $pdo, int $uid, string $comp): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM folha_pagamentos WHERE usuario_id=? AND competencia=? AND status='pago'"); $s->execute([$uid, $comp]);
    return (bool)$s->fetchColumn();
}

/** Recalcula diárias e líquido de uma linha em aberto. */
function folhaRecalcular(PDO $pdo, int $folhaId): void {
    $s = $pdo->prepare("SELECT * FROM folha_pagamentos WHERE id=? AND status='aberto'"); $s->execute([$folhaId]); $f = $s->fetch();
    if (!$f) return;
    [$q, $t, $c] = folhaDiarias($pdo, (int)$f['usuario_id'], $f['competencia']);
    $liq = (float)$f['salario'] + $t + (float)$f['adicionais'] - (float)$f['descontos'];
    $pdo->prepare("UPDATE folha_pagamentos SET diarias_qtd=?, diarias_total=?, diarias_corrida=?, liquido=? WHERE id=?")->execute([$q, $t, $c, round($liq, 2), $folhaId]);
}

/** Cria as linhas da competência para quem está na folha (não mexe nas que já existem). Retorna quantas criou. */
function folhaGerar(PDO $pdo, string $comp): int {
    $p = $pdo->query("SELECT id, salario_mensal FROM usuarios WHERE na_folha=1 AND ativo=1")->fetchAll(); $n = 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO folha_pagamentos (usuario_id, competencia, salario) VALUES (?,?,?)");
    foreach ($p as $x) { $ins->execute([$x['id'], $comp, (float)($x['salario_mensal'] ?? 0)]); if ($ins->rowCount()) { $n++; folhaRecalcular($pdo, (int)$pdo->lastInsertId()); } }
    // Quem tem diária no mês mas não está na folha também ganha linha (diarista)
    $d = $pdo->prepare("SELECT DISTINCT usuario_id FROM folha_diarias WHERE DATE_FORMAT(data,'%Y-%m')=?"); $d->execute([$comp]);
    foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $uid) { $ins->execute([$uid, $comp, 0]); if ($ins->rowCount()) { $n++; folhaRecalcular($pdo, (int)$pdo->lastInsertId()); } }
    return $n;
}

/**
 * Marca como pago. Vira despesa da oficina (categoria Folha) pelo valor líquido MENOS as diárias de corrida,
 * porque essas já aparecem como custo da equipe na própria corrida.
 */
function folhaPagar(PDO $pdo, int $folhaId, int $adminId, ?string $comprovante, string $forma = 'pix'): ?string {
    folhaRecalcular($pdo, $folhaId);
    $s = $pdo->prepare("SELECT f.*, u.nome FROM folha_pagamentos f JOIN usuarios u ON u.id=f.usuario_id WHERE f.id=? AND f.status='aberto'"); $s->execute([$folhaId]); $f = $s->fetch();
    if (!$f) return 'Esta linha já foi paga.';
    if ((float)$f['liquido'] <= 0) return 'Valor líquido zerado. Confira salário, diárias e descontos.';
    $despId = null; $valorOficina = round((float)$f['liquido'] - (float)$f['diarias_corrida'], 2);
    if ($valorOficina > 0) {
        $despId = finLancarDespesa($pdo, ['categoria_id' => finCategoriaId($pdo, 'Folha de pagamento'), 'descricao' => 'Folha ' . finCompLabel($f['competencia']) . ' – ' . $f['nome'],
            'valor' => $valorOficina, 'data' => date('Y-m-d'), 'forma_pagamento' => $forma, 'comprovante' => $comprovante, 'origem' => 'folha',
            'competencia' => $f['competencia'], 'ref_id' => $folhaId, 'lancado_por' => $adminId]);
    }
    $pdo->prepare("UPDATE folha_pagamentos SET status='pago', pago_em=NOW(), pago_por=?, comprovante=?, despesa_id=? WHERE id=?")->execute([$adminId, $comprovante, $despId, $folhaId]);
    aprovLog($pdo, 'folha', $folhaId, 'pago', 'Folha ' . finCompLabel($f['competencia']) . ' – ' . $f['nome'], (float)$f['liquido'], (int)$f['usuario_id'], $adminId);
    return null;
}

/** Totais mensais (últimos $n meses até $comp): oficina × corridas. */
function finComparativo(PDO $pdo, string $comp, int $n = 12): array {
    $ini = finCompMais($comp, -($n - 1)) . '-01'; $fim = date('Y-m-t', strtotime($comp . '-01'));
    $meses = []; for ($i = $n - 1; $i >= 0; $i--) $meses[finCompMais($comp, -$i)] = ['oficina' => 0, 'corridas' => 0];
    $q = function (string $sql) use ($pdo, $ini, $fim) { $s = $pdo->prepare($sql); $s->execute([$ini, $fim]); return $s->fetchAll(); };
    foreach ($q("SELECT DATE_FORMAT(data,'%Y-%m') m, SUM(valor) v FROM despesas WHERE data BETWEEN ? AND ? GROUP BY m") as $r) if (isset($meses[$r['m']])) $meses[$r['m']]['oficina'] += (float)$r['v'];
    // Corridas: gastos da equipe (inclui reembolsos aprovados) + diárias de corrida + OS de navegador cobradas da equipe + staff (v14)
    foreach ($q("SELECT DATE_FORMAT(data,'%Y-%m') m, SUM(valor) v FROM rally_gastos WHERE data BETWEEN ? AND ? GROUP BY m") as $r) if (isset($meses[$r['m']])) $meses[$r['m']]['corridas'] += (float)$r['v'];
    foreach ($q("SELECT DATE_FORMAT(data,'%Y-%m') m, SUM(quantidade*valor_unit) v FROM folha_diarias WHERE rally_id IS NOT NULL AND data BETWEEN ? AND ? GROUP BY m") as $r) if (isset($meses[$r['m']])) $meses[$r['m']]['corridas'] += (float)$r['v'];
    foreach ($q("SELECT DATE_FORMAT(aprovado_em,'%Y-%m') m, SUM(valor_total) v FROM nav_os WHERE cobrar_de='equipe' AND status='concluida' AND DATE(aprovado_em) BETWEEN ? AND ? GROUP BY m") as $r) if (isset($meses[$r['m']])) $meses[$r['m']]['corridas'] += (float)$r['v'];
    // v14: pagamento de staff das corridas
    try { foreach ($q("SELECT DATE_FORMAT(data,'%Y-%m') m, SUM(total) v FROM rally_staff WHERE data BETWEEN ? AND ? GROUP BY m") as $r) if (isset($meses[$r['m']])) $meses[$r['m']]['corridas'] += (float)$r['v']; }
    catch (PDOException $e) { /* migrate13 ainda não rodou */ }
    return $meses;
}
