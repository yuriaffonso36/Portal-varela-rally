<?php
// includes/aprovacoes.php – Fase 2: regras de aprovação usadas pela Central, pela OS, pelo navegador e pelos reembolsos
require_once __DIR__ . '/fotos.php';

/** Registra no histórico da Central. */
function aprovLog(PDO $pdo, string $tipo, int $refId, string $acao, string $resumo, ?float $valor, ?int $solicitanteId, ?int $adminId, ?string $motivo = null): void {
    $pdo->prepare("INSERT INTO aprovacoes_log (tipo,ref_id,acao,resumo,valor,solicitante_id,admin_id,motivo) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tipo, $refId, $acao, mb_substr($resumo, 0, 255), $valor, $solicitanteId, $adminId, $motivo ? mb_substr($motivo, 0, 255) : null]);
}

/** Quantidade pendente por categoria (menu, dashboard e abas da Central). */
function aprovContagens(PDO $pdo): array {
    $q = fn($sql) => (int)$pdo->query($sql)->fetchColumn();
    $c = [
        'fotos'       => $q("SELECT COUNT(*) FROM fotos WHERE aprovada=0"),
        'comprovantes'=> $q("SELECT COUNT(*) FROM comprovantes WHERE status='pendente'"),
        'pedidos'     => $q("SELECT COUNT(*) FROM os_itens oi JOIN ordens_servico os ON os.id=oi.ordem_servico_id WHERE oi.status_aprovacao='pendente' AND os.status<>'cancelada'"),
        'navegador'   => $q("SELECT COUNT(*) FROM nav_os WHERE status='encerrada' AND ok_admin=0"),
        'reembolsos'  => $q("SELECT COUNT(*) FROM reembolsos WHERE status='pendente'"),
    ];
    $c['total'] = array_sum($c);
    return $c;
}

/** Valor total da OS: só itens aprovados. */
function osRecalcTotal(PDO $pdo, int $osId): void {
    $sv = $pdo->prepare("SELECT COALESCE(SUM(valor*quantidade),0) FROM os_itens WHERE ordem_servico_id=? AND valor IS NOT NULL AND status_aprovacao='aprovado'");
    $sv->execute([$osId]);
    $pdo->prepare("UPDATE ordens_servico SET valor_total=? WHERE id=?")->execute([$sv->fetchColumn(), $osId]);
}

function aprovNum($v): ?float {
    $v = trim((string)$v); if ($v === '') return null;
    $f = (float)str_replace(['.', ','], ['', '.'], $v); return $f > 0 ? $f : null;
}

/* ───────────── Itens da OS (pedidos do cliente e itens do mecânico) ───────────── */

function aprovItemBuscar(PDO $pdo, int $itemId): ?array {
    $s = $pdo->prepare("SELECT oi.*, os.numero_os, os.titulo AS os_titulo, os.cliente_id, os.status AS os_status FROM os_itens oi JOIN ordens_servico os ON os.id=oi.ordem_servico_id WHERE oi.id=?");
    $s->execute([$itemId]); return $s->fetch() ?: null;
}

/**
 * Aprova um item pendente. Pedido do cliente vira também um afazer do mecânico (lista oficial de trabalhos).
 * $valor/$custo opcionais (null mantém o que já existe).
 */
function aprovItemAprovar(PDO $pdo, int $itemId, int $adminId, ?float $valor = null, ?float $custo = null, ?int $categoriaId = null): ?string {
    $it = aprovItemBuscar($pdo, $itemId);
    if (!$it || $it['status_aprovacao'] !== 'pendente') return 'Item não está mais pendente.';
    $pdo->prepare("UPDATE os_itens SET status_aprovacao='aprovado', motivo_recusa=NULL, analisado_por=?, analisado_em=NOW(),
                   valor=COALESCE(?,valor), valor_custo=COALESCE(?,valor_custo), categoria_id=COALESCE(?,categoria_id) WHERE id=?")
        ->execute([$adminId, $valor, $custo, $categoriaId, $itemId]);
    if ($it['origem'] === 'cliente') {
        $o = $pdo->prepare("SELECT COALESCE(MAX(ordem),0)+10 FROM os_afazeres WHERE ordem_servico_id=?"); $o->execute([$it['ordem_servico_id']]);
        $pdo->prepare("INSERT INTO os_afazeres (ordem_servico_id,descricao,ordem,criado_por) VALUES (?,?,?,?)")
            ->execute([$it['ordem_servico_id'], mb_substr('Pedido do cliente: ' . $it['descricao'], 0, 255), (int)$o->fetchColumn(), $adminId]);
    }
    osRecalcTotal($pdo, (int)$it['ordem_servico_id']);
    $tipo = $it['origem'] === 'cliente' ? 'pedido_cliente' : 'item_mecanico';
    aprovLog($pdo, $tipo, $itemId, 'aprovado', 'OS ' . $it['numero_os'] . ' – ' . $it['descricao'], $valor ?? ($it['valor'] !== null ? (float)$it['valor'] : null), $it['solicitado_por'] ? (int)$it['solicitado_por'] : null, $adminId);
    return null;
}

function aprovItemRecusar(PDO $pdo, int $itemId, int $adminId, string $motivo): ?string {
    $it = aprovItemBuscar($pdo, $itemId);
    if (!$it || $it['status_aprovacao'] !== 'pendente') return 'Item não está mais pendente.';
    $motivo = trim($motivo) ?: 'Recusado pelo administrador';
    $pdo->prepare("UPDATE os_itens SET status_aprovacao='recusado', motivo_recusa=?, analisado_por=?, analisado_em=NOW() WHERE id=?")->execute([mb_substr($motivo, 0, 255), $adminId, $itemId]);
    osRecalcTotal($pdo, (int)$it['ordem_servico_id']);
    aprovLog($pdo, $it['origem'] === 'cliente' ? 'pedido_cliente' : 'item_mecanico', $itemId, 'recusado', 'OS ' . $it['numero_os'] . ' – ' . $it['descricao'], null, $it['solicitado_por'] ? (int)$it['solicitado_por'] : null, $adminId, $motivo);
    return null;
}

/* ───────────── OS do navegador ───────────── */

/**
 * Conclui a OS do navegador quando as aprovações necessárias existem.
 *  - Cobrar de PILOTO: OK do admin + OK do piloto → débito na conta corrente do piloto.
 *  - Cobrar de EQUIPE: só OK do admin → vira custo da equipe na corrida (nada vai para a CC do cliente).
 * Retorna 'piloto', 'equipe' ou null (ainda falta aprovação).
 */
function navOsTentarConcluir(PDO $pdo, int $navId, ?int $adminId): ?string {
    $s = $pdo->prepare("SELECT n.*, u.nome AS navegador_nome FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id WHERE n.id=?");
    $s->execute([$navId]); $n = $s->fetch();
    if (!$n || $n['status'] !== 'encerrada') return null;
    if (!$adminId) $adminId = (int)$pdo->query("SELECT id FROM usuarios WHERE tipo='admin' ORDER BY id LIMIT 1")->fetchColumn();

    if (($n['cobrar_de'] ?? 'piloto') === 'equipe') {
        if (!$n['ok_admin']) return null;
        $pdo->prepare("UPDATE nav_os SET status='concluida', aprovado_em=NOW(), cc_id=NULL WHERE id=?")->execute([$navId]);
        return 'equipe';
    }
    if (!$n['ok_admin'] || !$n['ok_cliente']) return null;
    $pdo->prepare("INSERT INTO conta_corrente (cliente_id,descricao,valor,tipo,admin_id) VALUES (?,?,?,'debito',?)")
        ->execute([$n['piloto_id'], 'OS Navegador: ' . $n['titulo'] . ' (' . $n['navegador_nome'] . ')', $n['valor_total'], $adminId]);
    $pdo->prepare("UPDATE nav_os SET status='concluida', aprovado_em=NOW(), cc_id=? WHERE id=?")->execute([$pdo->lastInsertId(), $navId]);
    return 'piloto';
}

/** OK do admin na OS do navegador (com histórico). Retorna mensagem. */
function navOsOkAdmin(PDO $pdo, int $navId, int $adminId): string {
    $s = $pdo->prepare("SELECT n.*, u.nome AS navegador_nome FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id WHERE n.id=? AND n.status='encerrada'");
    $s->execute([$navId]); $n = $s->fetch();
    if (!$n) return 'OS do navegador não está aguardando aprovação.';
    $pdo->prepare("UPDATE nav_os SET ok_admin=1 WHERE id=?")->execute([$navId]);
    aprovLog($pdo, 'navegador', $navId, 'aprovado', $n['titulo'] . ' (' . $n['navegador_nome'] . ') · cobrar de ' . (($n['cobrar_de'] ?? 'piloto') === 'equipe' ? 'equipe' : 'piloto'), (float)$n['valor_total'], (int)$n['navegador_id'], $adminId);
    $r = navOsTentarConcluir($pdo, $navId, $adminId);
    if ($r === 'piloto') return 'Aprovada! Débito lançado na conta corrente do piloto.';
    if ($r === 'equipe') return 'Aprovada! Lançada como custo da equipe.';
    return 'Aprovação do admin registrada. Falta o OK do piloto.';
}

/* ───────────── Reembolsos ───────────── */

/** Salva comprovante (foto reduzida ou PDF). Retorna [caminho|null, erro|null]. */
function reembolsoSalvarArquivo(array $file, string $prefixo): array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) return [null, 'Falha no envio do comprovante.'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $dir = UPLOAD_DIR . 'reembolsos/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nome = preg_replace('/[^a-z0-9_]/i', '', $prefixo) . '_' . date('ymd') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        return move_uploaded_file($file['tmp_name'], $dir . $nome) ? ['reembolsos/' . $nome, null] : [null, 'Não foi possível gravar o PDF.'];
    }
    $r = fotoSalvarUpload($file, 'reembolsos', $prefixo);
    if (isset($r['erro'])) return [null, $r['erro']];
    fotoApagar($r['thumb']);
    return [$r['caminho'], null];
}

function reembolsoBuscar(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT r.*, u.nome AS pessoa_nome, ra.nome AS rally_nome FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN rallys ra ON ra.id=r.rally_id WHERE r.id=?");
    $s->execute([$id]); return $s->fetch() ?: null;
}

/** Aprova. Se for de uma corrida, entra nos gastos da equipe daquela corrida. */
function reembolsoAprovar(PDO $pdo, int $id, int $adminId): ?string {
    $r = reembolsoBuscar($pdo, $id);
    if (!$r || $r['status'] !== 'pendente') return 'Reembolso não está mais pendente.';
    $pdo->prepare("UPDATE reembolsos SET status='aprovado', motivo_recusa=NULL, analisado_por=?, analisado_em=NOW() WHERE id=?")->execute([$adminId, $id]);
    if ($r['rally_id']) {
        $pdo->prepare("INSERT INTO rally_gastos (rally_id,categoria_id,descricao,valor,data,fornecedor,cliente_id,comprovante,lancado_por,reembolso_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$r['rally_id'], $r['categoria_id'], 'Reembolso – ' . $r['pessoa_nome'] . ': ' . $r['descricao'], $r['valor'], $r['data_despesa'], null, null, $r['comprovante'], $r['usuario_id'], $id]);
        $pdo->prepare("UPDATE reembolsos SET gasto_id=? WHERE id=?")->execute([$pdo->lastInsertId(), $id]);
    } else {
        // Fase 3: reembolso da oficina vira despesa da oficina (categoria Reembolsos)
        try {
            $cat = $pdo->query("SELECT id FROM despesa_categorias WHERE nome='Reembolsos' LIMIT 1")->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO despesas (categoria_id,descricao,valor,data,forma_pagamento,comprovante,origem,ref_id,lancado_por) VALUES (?,?,?,?,'pix',?,'reembolso',?,?)")
                ->execute([$cat, mb_substr('Reembolso – ' . $r['pessoa_nome'] . ': ' . $r['descricao'], 0, 255), $r['valor'], $r['data_despesa'], $r['comprovante'], $id, $adminId]);
        } catch (PDOException $e) { /* banco ainda sem a migração da Fase 3 */ }
    }
    aprovLog($pdo, 'reembolso', $id, 'aprovado', $r['pessoa_nome'] . ': ' . $r['descricao'] . ($r['rally_nome'] ? ' (' . $r['rally_nome'] . ')' : ' (oficina)'), (float)$r['valor'], (int)$r['usuario_id'], $adminId);
    return null;
}

function reembolsoRecusar(PDO $pdo, int $id, int $adminId, string $motivo): ?string {
    $r = reembolsoBuscar($pdo, $id);
    if (!$r || $r['status'] !== 'pendente') return 'Reembolso não está mais pendente.';
    $motivo = trim($motivo) ?: 'Recusado pelo administrador';
    $pdo->prepare("UPDATE reembolsos SET status='recusado', motivo_recusa=?, analisado_por=?, analisado_em=NOW() WHERE id=?")->execute([mb_substr($motivo, 0, 255), $adminId, $id]);
    aprovLog($pdo, 'reembolso', $id, 'recusado', $r['pessoa_nome'] . ': ' . $r['descricao'], (float)$r['valor'], (int)$r['usuario_id'], $adminId, $motivo);
    return null;
}

/**
 * Exclui um reembolso em qualquer situação (pendente, aprovado, recusado ou pago).
 * Remove junto o gasto da corrida e a despesa da oficina que a aprovação criou, e os arquivos que só ele usa.
 */
function reembolsoExcluir(PDO $pdo, int $id, int $adminId, string $motivo = ''): ?string {
    $r = reembolsoBuscar($pdo, $id);
    if (!$r) return 'Reembolso não encontrado.';
    $pdo->prepare("DELETE FROM rally_gastos WHERE reembolso_id=?")->execute([$id]);
    if (!empty($r['gasto_id'])) $pdo->prepare("DELETE FROM rally_gastos WHERE id=?")->execute([(int)$r['gasto_id']]);
    try { $pdo->prepare("DELETE FROM despesas WHERE origem='reembolso' AND ref_id=?")->execute([$id]); } catch (PDOException $e) { /* sem tabela da Fase 3 */ }
    $pdo->prepare("DELETE FROM reembolsos WHERE id=?")->execute([$id]);
    // Arquivos: a nota é só deste reembolso; o comprovante do PIX pode ter pago vários juntos
    fotoApagar($r['comprovante']);
    if ($r['comprovante_pagamento']) {
        $u = $pdo->prepare("SELECT COUNT(*) FROM reembolsos WHERE comprovante_pagamento=?"); $u->execute([$r['comprovante_pagamento']]);
        if (!$u->fetchColumn()) fotoApagar($r['comprovante_pagamento']);
    }
    $sit = $r['status'] === 'aprovado' ? ($r['pago'] ? 'pago' : 'aprovado') : $r['status'];
    aprovLog($pdo, 'reembolso', $id, 'recusado', 'EXCLUÍDO (' . $sit . ') – ' . $r['pessoa_nome'] . ': ' . $r['descricao'], (float)$r['valor'], (int)$r['usuario_id'], $adminId,
        'Excluído pelo administrador' . (trim($motivo) !== '' ? ': ' . trim($motivo) : ''));
    return null;
}

/** Tipos de login que lançam reembolso (equipe). Quando houver o login "equipe", entra aqui. */
const REEMBOLSO_TIPOS = ['mecanico', 'estoque', 'admin', 'equipe'];

function reembolsoStatusBadge(array $r): string {
    if ($r['status'] === 'pendente') return '<span class="badge bg-warning text-dark">Aguardando aprovação</span>';
    if ($r['status'] === 'recusado') return '<span class="badge bg-danger">Recusado</span>';
    return $r['pago'] ? '<span class="badge bg-success">Pago ' . date('d/m', strtotime($r['pago_em'])) . '</span>' : '<span class="badge bg-primary">Aprovado · a pagar</span>';
}

function pixFmt(?string $tipo, ?string $chave): string {
    if (!$chave) return '';
    if ($tipo === 'cpf' && strlen($chave) === 11) return substr($chave, 0, 3) . '.' . substr($chave, 3, 3) . '.' . substr($chave, 6, 3) . '-' . substr($chave, 9);
    if ($tipo === 'cnpj' && strlen($chave) === 14) return substr($chave, 0, 2) . '.' . substr($chave, 2, 3) . '.' . substr($chave, 5, 3) . '/' . substr($chave, 8, 4) . '-' . substr($chave, 12);
    return $chave;
}
