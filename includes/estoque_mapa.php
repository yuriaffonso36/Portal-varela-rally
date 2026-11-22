<?php
// includes/estoque_mapa.php – Fase 4: localização física das peças (estante + prateleira) e referência BRP
require_once __DIR__ . '/fotos.php';

const BRP_CATALOGO_URL = 'https://can-am-shop.brp.com/off-road/ca/en/electronic-parts-catalog.html';

function estEstantes(PDO $pdo, bool $soAtivas = true): array {
    return $pdo->query("SELECT * FROM estoque_estantes" . ($soAtivas ? " WHERE ativo=1" : "") . " ORDER BY ordem, codigo")->fetchAll();
}

/** "A3" (+ posição, se houver) ou null quando a peça não tem lugar definido. */
function estLocal(array $item, array $estantesPorId): ?string {
    $e = $estantesPorId[$item['estante_id'] ?? 0] ?? null;
    if (!$e) return null;
    return $e['codigo'] . ($item['prateleira'] ? $item['prateleira'] : '') . (!empty($item['posicao']) ? '·' . $item['posicao'] : '');
}

function estLocalBadge(array $item, array $estantesPorId): string {
    $l = estLocal($item, $estantesPorId);
    if (!$l) return '<span class="badge bg-light text-muted border" title="Sem lugar definido"><i class="bi bi-geo"></i> —</span>';
    $e = $estantesPorId[$item['estante_id']];
    return '<a href="' . BASE_URL . '/estoque/mapa.php?estante=' . (int)$e['id'] . '&prat=' . (int)$item['prateleira'] . '" class="badge text-decoration-none" style="background:' . htmlspecialchars($e['cor']) . '" title="Estante ' . htmlspecialchars($e['codigo'] . ($e['nome'] ? ' – ' . $e['nome'] : '')) . ', prateleira ' . (int)$item['prateleira'] . '"><i class="bi bi-geo-alt-fill me-1"></i>' . htmlspecialchars($l) . '</a>';
}

/** Campos de localização + referência BRP para os formulários do estoque. */
function estCamposLocal(array $estantes, ?array $item = null): string {
    $sel = (int)($item['estante_id'] ?? 0); $prat = (int)($item['prateleira'] ?? 0);
    $h = '<div class="col-md-3"><label class="form-label"><i class="bi bi-geo-alt me-1 text-danger"></i>Estante</label><select name="estante_id" class="form-select js-estante" onchange="estPrateleiras(this)">';
    $h .= '<option value="" data-n="0">— Sem lugar —</option>';
    foreach ($estantes as $e) $h .= '<option value="' . $e['id'] . '" data-n="' . (int)$e['prateleiras'] . '"' . ($sel === (int)$e['id'] ? ' selected' : '') . '>' . htmlspecialchars($e['codigo'] . ($e['nome'] ? ' – ' . $e['nome'] : '')) . '</option>';
    $h .= '</select></div>';
    $h .= '<div class="col-md-2"><label class="form-label">Prateleira</label><select name="prateleira" class="form-select js-prateleira" data-atual="' . $prat . '"><option value="">—</option></select></div>';
    $h .= '<div class="col-md-2"><label class="form-label">Posição <span class="text-muted small">(opc.)</span></label><input type="text" name="posicao" class="form-control" maxlength="20" placeholder="ex.: caixa 2" value="' . htmlspecialchars($item['posicao'] ?? '') . '"></div>';
    $h .= '<div class="col-md-5"><label class="form-label">Part number BRP <span class="text-muted small">(opc.)</span></label><input type="text" name="part_number" class="form-control" maxlength="40" placeholder="ex.: 705401937" value="' . htmlspecialchars($item['part_number'] ?? '') . '"></div>';
    return $h;
}

/** Script dos selects estante → prateleiras (inclua uma vez por página). */
function estScriptLocal(): void { ?>
<script>
function estPrateleiras(sel){
  const n=+(sel.selectedOptions[0]?.dataset.n||0), p=sel.closest('form').querySelector('.js-prateleira'), atual=+(p.dataset.atual||0);
  p.innerHTML='<option value="">—</option>'; for(let i=1;i<=n;i++){const o=document.createElement('option');o.value=i;o.textContent=i+(i===1?' (topo)':i===n?' (base)':'');if(i===atual)o.selected=true;p.appendChild(o);}
}
document.querySelectorAll('.js-estante').forEach(estPrateleiras);
</script>
<?php }

/** Salva a imagem de referência (desenho/foto do catálogo) da peça. Retorna [caminho|null, erro|null]. */
function estSalvarImagemRef(array $file): array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    $r = fotoSalvarUpload($file, 'estoque_ref', 'ref');
    if (isset($r['erro'])) return [null, $r['erro']];
    return [$r['caminho'], null];
}

/** Botões de referência BRP (catálogo oficial com o código copiado + busca do código). */
function estBotoesBrp(?string $pn): string {
    $pn = trim((string)$pn); if ($pn === '') return '';
    $js = htmlspecialchars('navigator.clipboard&&navigator.clipboard.writeText(' . json_encode($pn) . ')', ENT_QUOTES);
    return '<a href="' . BRP_CATALOGO_URL . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark py-0" onclick="' . $js . '" title="Abre o catálogo oficial Can-Am (o código ' . htmlspecialchars($pn) . ' fica copiado)"><i class="bi bi-journal-text me-1"></i>Catálogo BRP</a> '
         . '<a href="https://www.google.com/search?q=' . rawurlencode('BRP ' . $pn) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0" title="Buscar o código na web"><i class="bi bi-search"></i></a>';
}
