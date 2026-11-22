<?php
// includes/os_helpers.php – Funções comuns de OS: afazeres, fotos por item, botão de foto (v8)
require_once __DIR__ . '/fotos.php';

/** Afazeres da OS em ordem. */
function osAfazeres(PDO $pdo, int $osId): array {
    $s = $pdo->prepare("SELECT a.*, u.nome AS concluido_nome FROM os_afazeres a LEFT JOIN usuarios u ON u.id=a.concluido_por WHERE a.ordem_servico_id=? ORDER BY a.ordem, a.id");
    $s->execute([$osId]); return $s->fetchAll();
}

/** [total, feitos, percentual] dos afazeres. */
function osProgresso(array $afazeres): array {
    $t = count($afazeres); $f = count(array_filter($afazeres, fn($a) => $a['concluido']));
    return [$t, $f, $t ? (int)round($f / $t * 100) : 0];
}

/** Fotos da OS (todas ou só aprovadas), com itens vinculados. */
function osFotos(PDO $pdo, int $osId, bool $soAprovadas = false): array {
    $s = $pdo->prepare("SELECT f.*, u.nome AS enviado_nome, a.descricao AS afazer_desc
                        FROM fotos f JOIN usuarios u ON u.id=f.enviado_por
                        LEFT JOIN os_afazeres a ON a.id=f.afazer_id
                        WHERE f.ordem_servico_id=?" . ($soAprovadas ? " AND f.aprovada=1" : "") . "
                        ORDER BY f.aprovada ASC, f.criado_em DESC");
    $s->execute([$osId]); $fotos = $s->fetchAll();
    if (!$fotos) return [];
    $ids = array_column($fotos, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $l = $pdo->prepare("SELECT l.foto_id, l.item_id, oi.descricao FROM os_item_fotos l JOIN os_itens oi ON oi.id=l.item_id WHERE l.foto_id IN ($ph)");
    $l->execute($ids);
    $porFoto = [];
    foreach ($l->fetchAll() as $r) $porFoto[$r['foto_id']][] = ['id' => (int)$r['item_id'], 'descricao' => $r['descricao']];
    foreach ($fotos as &$f) $f['itens'] = $porFoto[$f['id']] ?? [];
    return $fotos;
}

/** Mapa item_id => [fotos] a partir da lista de osFotos(). */
function osFotosPorItem(array $fotos): array {
    $m = [];
    foreach ($fotos as $f) foreach ($f['itens'] as $it) $m[$it['id']][] = $f;
    return $m;
}
/** Mapa afazer_id => [fotos]. */
function osFotosPorAfazer(array $fotos): array {
    $m = [];
    foreach ($fotos as $f) if ($f['afazer_id']) $m[$f['afazer_id']][] = $f;
    return $m;
}

/** Lista {src,legenda} para abrirGaleria() em JS. */
function osGaleriaJson(array $fotos, ?string $estoqueFoto = null, string $legendaEstoque = 'Foto do estoque'): string {
    $l = [];
    if ($estoqueFoto) $l[] = ['src' => fotoUrl('estoque/' . $estoqueFoto), 'legenda' => $legendaEstoque];
    foreach ($fotos as $f) $l[] = ['src' => fotoUrl($f['caminho']), 'legenda' => trim(($f['descricao'] ?? '') . ' · ' . date('d/m/Y', strtotime($f['criado_em'])), ' ·')];
    return htmlspecialchars(json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
}

/**
 * Botão de foto da linha do item.
 * $modo: 'ver' (abre galeria; cinza e desabilitado se não houver foto) ou 'gerenciar' (sempre clicável, chama $onclick)
 */
function osBotaoFoto(array $fotosItem, ?string $estoqueFoto, string $titulo, string $modo = 'ver', string $onclick = ''): string {
    $qtd = count($fotosItem) + ($estoqueFoto ? 1 : 0);
    $cls = 'btn-foto' . ($qtd ? ' tem' : '') . ($estoqueFoto && !$fotosItem ? ' estoque' : '');
    $tip = $estoqueFoto ? 'Foto do estoque' . ($fotosItem ? ' + ' . count($fotosItem) . ' foto(s)' : '') : ($qtd ? "$qtd foto(s)" : ($modo === 'ver' ? 'Sem foto' : 'Adicionar / vincular foto'));
    $icone = $qtd ? 'bi-camera-fill' : 'bi-camera';
    $badge = $qtd > 1 ? '<span class="qtd">' . $qtd . '</span>' : '';
    if ($modo === 'ver') {
        if (!$qtd) return '<span class="' . $cls . ' opacity-50" title="Sem foto"><i class="bi bi-camera"></i></span>';
        $js = "abrirGaleria(JSON.parse(this.dataset.g),0," . htmlspecialchars(json_encode($titulo, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ")";
        return '<button type="button" class="' . $cls . '" title="' . htmlspecialchars($tip) . '" data-g="' . osGaleriaJson($fotosItem, $estoqueFoto) . '" onclick="' . $js . '"><i class="bi ' . $icone . '"></i>' . $badge . '</button>';
    }
    return '<button type="button" class="' . $cls . '" title="' . htmlspecialchars($tip) . '" onclick="' . htmlspecialchars($onclick, ENT_QUOTES) . '"><i class="bi ' . $icone . '"></i>' . $badge . '</button>';
}

/** Salva várias fotos enviadas em uma OS. Retorna [qtd_ok, [erros], [ids]]. */
function osSalvarFotos(PDO $pdo, int $osId, array $files, int $usuarioId, bool $aprovada, ?string $descricao = null, ?int $itemId = null, ?int $afazerId = null): array {
    $ok = 0; $erros = []; $ids = [];
    foreach (fotosNormalizarFiles($files) as $f) {
        $r = fotoSalvarUpload($f, 'os/' . $osId, 'os' . $osId);
        if (isset($r['erro'])) { $erros[] = $f['name'] . ': ' . $r['erro']; continue; }
        $pdo->prepare("INSERT INTO fotos (ordem_servico_id,afazer_id,caminho,thumb,tamanho,descricao,enviado_por,aprovada) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$osId, $afazerId, $r['caminho'], $r['thumb'], $r['tamanho'], $descricao ?: null, $usuarioId, $aprovada ? 1 : 0]);
        $fid = (int)$pdo->lastInsertId(); $ids[] = $fid;
        if ($itemId) $pdo->prepare("INSERT IGNORE INTO os_item_fotos (item_id,foto_id) VALUES (?,?)")->execute([$itemId, $fid]);
        $ok++;
    }
    return [$ok, $erros, $ids];
}

/** Apaga foto da OS (arquivo, miniatura e vínculos). */
function osApagarFoto(PDO $pdo, int $fotoId, int $osId): bool {
    $s = $pdo->prepare("SELECT caminho, thumb FROM fotos WHERE id=? AND ordem_servico_id=?");
    $s->execute([$fotoId, $osId]); $f = $s->fetch();
    if (!$f) return false;
    fotoApagar($f['caminho'], $f['thumb']);
    $pdo->prepare("DELETE FROM os_item_fotos WHERE foto_id=?")->execute([$fotoId]);
    $pdo->prepare("DELETE FROM fotos WHERE id=?")->execute([$fotoId]);
    return true;
}

/** Foto 1/4 frontal do veículo (miniatura e original). */
function veiculoCapa(?array $v): array {
    if (!$v || empty($v['foto_capa'])) return [null, null];
    return [fotoUrl($v['foto_capa_thumb'] ?: $v['foto_capa']), fotoUrl($v['foto_capa'])];
}
