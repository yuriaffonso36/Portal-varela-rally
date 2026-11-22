<?php
// includes/corridas.php – Funções comuns das páginas de corrida (v8)
require_once __DIR__ . '/fotos.php';

const RALLY_STATUS = [
    'planejada'    => ['Planejada',    'bg-secondary'],
    'confirmada'   => ['Confirmada',   'bg-primary'],
    'em_andamento' => ['Em andamento', 'bg-warning text-dark'],
    'finalizada'   => ['Finalizada',   'bg-success'],
    'cancelada'    => ['Cancelada',    'bg-dark'],
];

const PROG_TIPOS = [
    'deslocamento' => ['Deslocamento',            '#6c757d', 'bi-truck'],
    'vistoria'     => ['Vistoria técnica',        '#0d6efd', 'bi-clipboard-check'],
    'briefing'     => ['Briefing',                '#6f42c1', 'bi-megaphone'],
    'especial'     => ['Especial / Etapa',        '#dc3545', 'bi-flag-fill'],
    'assistencia'  => ['Assistência / Parque',    '#fd7e14', 'bi-tools'],
    'hospedagem'   => ['Hospedagem',              '#20c997', 'bi-house'],
    'encontro'     => ['Ponto de encontro',       '#0dcaf0', 'bi-geo-alt-fill'],
    'refeicao'     => ['Refeição',                '#198754', 'bi-cup-hot'],
    'premiacao'    => ['Premiação',               '#e8a317', 'bi-trophy'],
    'outro'        => ['Outro',                   '#adb5bd', 'bi-circle'],
];

const EQUIPE_FUNCOES = ['Chefe de equipe','Chefe de mecânica','Mecânico','Eletricista','Logística','Motorista de apoio','Cozinha','Fotógrafo','Apoio geral'];

function rallyBadge(string $status): string {
    [$l, $c] = RALLY_STATUS[$status] ?? [$status, 'bg-secondary'];
    return '<span class="badge ' . $c . '">' . $l . '</span>';
}

function rallyPeriodo(array $r): string {
    if (empty($r['data_inicio'])) return 'Data a definir';
    $i = date('d/m/Y', strtotime($r['data_inicio']));
    if (empty($r['data_fim']) || $r['data_fim'] === $r['data_inicio']) return $i;
    return date('d/m', strtotime($r['data_inicio'])) . ' a ' . date('d/m/Y', strtotime($r['data_fim']));
}

/** Dias até o início (negativo = já começou), ou null. */
function rallyDiasPara(array $r): ?int {
    if (empty($r['data_inicio'])) return null;
    return (int)floor((strtotime($r['data_inicio']) - strtotime(date('Y-m-d'))) / 86400);
}

function rallyParticipantes(PDO $pdo, int $rallyId, ?int $soCliente = null): array {
    $sql = "SELECT p.*, c.nome AS cliente_nome, c.telefone AS cliente_tel, n.nome AS navegador_nome,
                   v.marca, v.modelo, v.placa, v.ano, v.foto_capa, v.foto_capa_thumb
            FROM rally_participantes p
            JOIN usuarios c ON c.id=p.cliente_id
            LEFT JOIN usuarios n ON n.id=p.navegador_id
            LEFT JOIN veiculos v ON v.id=p.veiculo_id
            WHERE p.rally_id=?" . ($soCliente ? " AND p.cliente_id=?" : "") . "
            ORDER BY (p.numero_carro IS NULL), CAST(p.numero_carro AS UNSIGNED), c.nome";
    $s = $pdo->prepare($sql); $s->execute($soCliente ? [$rallyId, $soCliente] : [$rallyId]);
    return $s->fetchAll();
}

function rallyEquipe(PDO $pdo, int $rallyId): array {
    $s = $pdo->prepare("SELECT e.*, u.nome, u.tipo, u.telefone FROM rally_equipe e JOIN usuarios u ON u.id=e.usuario_id WHERE e.rally_id=? ORDER BY FIELD(e.funcao,'Chefe de equipe','Chefe de mecânica')=0, FIELD(e.funcao,'Chefe de equipe','Chefe de mecânica'), u.nome");
    $s->execute([$rallyId]); return $s->fetchAll();
}

function rallyProgramacao(PDO $pdo, int $rallyId): array {
    $s = $pdo->prepare("SELECT * FROM rally_programacao WHERE rally_id=? ORDER BY data, (hora_inicio IS NULL), hora_inicio, id");
    $s->execute([$rallyId]); return $s->fetchAll();
}

function diaSemana(string $data): string {
    $d = ['domingo','segunda','terça','quarta','quinta','sexta','sábado'];
    return $d[(int)date('w', strtotime($data))];
}

function iniciais(string $nome): string {
    $p = preg_split('/\s+/', trim($nome));
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr(count($p) > 1 ? end($p) : '', 0, 1));
}

/** Cronograma em linha do tempo. $editavel mostra botões (admin). */
function renderProgramacao(array $eventos, bool $editavel = false): void {
    if (!$eventos) { echo '<p class="text-muted text-center py-4 mb-0"><i class="bi bi-calendar-x d-block fs-3 mb-1"></i>Programação ainda não divulgada.</p>'; return; }
    $porDia = [];
    foreach ($eventos as $e) $porDia[$e['data']][] = $e;
    $hoje = date('Y-m-d');
    foreach ($porDia as $dia => $lista) {
        echo '<div class="timeline-dia">';
        echo '<div class="dia">' . date('d/m', strtotime($dia)) . ' · ' . diaSemana($dia) . ($dia === $hoje ? ' <span class="badge bg-danger ms-1">hoje</span>' : '') . '</div>';
        foreach ($lista as $e) {
            [$tl, $cor, $ic] = PROG_TIPOS[$e['tipo']] ?? PROG_TIPOS['outro'];
            $hora = $e['hora_inicio'] ? substr($e['hora_inicio'], 0, 5) . ($e['hora_fim'] ? '–' . substr($e['hora_fim'], 0, 5) : '') : '—';
            echo '<div class="timeline-ev" style="--cor:' . $cor . '">';
            echo '<div class="hora">' . $hora . '</div><div class="flex-fill">';
            echo '<div class="tit">' . htmlspecialchars($e['titulo']) . ' <span class="badge ms-1" style="background:' . $cor . ';font-size:.62rem"><i class="bi ' . $ic . ' me-1"></i>' . $tl . '</span></div>';
            if ($e['local']) echo '<div class="small text-muted"><i class="bi bi-geo-alt me-1"></i>' . htmlspecialchars($e['local']) . '</div>';
            // Fase 4: abrir no Google Maps / traçar rota (link colado pelo admin ou busca pelo endereço)
            if ($mapa = mapsLinks($e)) {
                echo '<div class="mt-1 d-flex gap-1 flex-wrap">';
                echo '<a href="' . htmlspecialchars($mapa['abrir']) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-map me-1"></i>Abrir no Maps</a>';
                echo '<a href="' . htmlspecialchars($mapa['rota']) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-sign-turn-right me-1"></i>Rota</a>';
                echo '</div>';
            }
            if ($e['observacao']) echo '<div class="small">' . nl2br(htmlspecialchars($e['observacao'])) . '</div>';
            echo '</div>';
            if ($editavel) {
                echo '<div class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" onclick=\'editarEvento(' . htmlspecialchars(json_encode($e, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')\'><i class="bi bi-pencil"></i></button> ';
                echo '<form method="post" class="d-inline"><input type="hidden" name="acao" value="del_evento"><input type="hidden" name="evento_id" value="' . $e['id'] . '"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover este evento?"><i class="bi bi-trash3"></i></button></form></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

/**
 * Fase 4: links do Google Maps para um ponto do cronograma (sem chave de API).
 * Com link colado: abre o link; a rota usa o endereço (ou o próprio link, se não houver endereço).
 * Só com endereço: busca e rota pelo texto. Sem nenhum: null.
 */
function mapsLinks(array $e): ?array {
    $url = trim($e['maps_url'] ?? ''); $local = trim($e['local'] ?? '');
    if (!$url && !$local) return null;
    $destino = $local;
    if ($url && preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) $destino = $m[1] . ',' . $m[2];          // link com coordenadas
    elseif ($url && preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) $destino = $m[1] . ',' . $m[2];
    return [
        'abrir' => $url ?: 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($local),
        'rota'  => $destino ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($destino) : $url,
    ];
}

/** Card de participante (carro). */
function renderParticipante(array $p, bool $mostrarContato = false): void {
    $full = $p['foto_capa'] ? fotoUrl($p['foto_capa']) : null;
    $th = $p['foto_capa'] ? fotoThumbUrl($p['foto_capa_thumb'], $p['foto_capa']) : null; ?>
    <div class="participante-card h-100">
      <div class="foto">
        <?php if ($th): ?><img src="<?= $th ?>" data-galeria="carros" data-src="<?= $full ?>" data-legenda="<?= htmlspecialchars(($p['numero_carro'] ? '#'.$p['numero_carro'].' · ' : '').$p['cliente_nome']) ?>" style="cursor:pointer">
        <?php else: ?><div class="h-100 d-flex align-items-center justify-content-center text-secondary"><i class="bi bi-car-front fs-1"></i></div><?php endif; ?>
        <?php if ($p['numero_carro']): ?><span class="num">#<?= htmlspecialchars($p['numero_carro']) ?></span><?php endif; ?>
      </div>
      <div class="p-2 small">
        <div class="fw-bold"><?= htmlspecialchars($p['cliente_nome']) ?></div>
        <div class="text-muted"><?= htmlspecialchars(trim(($p['marca'] ?? '').' '.($p['modelo'] ?? ''))) ?: 'Veículo a definir' ?></div>
        <?php if ($p['navegador_nome']): ?><div><i class="bi bi-compass me-1 text-warning"></i><?= htmlspecialchars($p['navegador_nome']) ?></div><?php endif; ?>
        <?php if ($p['categoria']): ?><span class="badge bg-light text-dark border mt-1"><?= htmlspecialchars($p['categoria']) ?></span><?php endif; ?>
        <?php if ($mostrarContato && $p['cliente_tel']): ?><div class="text-muted mt-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($p['cliente_tel']) ?></div><?php endif; ?>
      </div>
    </div>
<?php }

/** Bloco de informações práticas (hospedagem, logística, observações). */
function renderInfoPratica(array $r): void {
    $blocos = [['hospedagem','bi-house','Hospedagem'],['logistica','bi-truck','Logística / deslocamento'],['observacoes','bi-info-circle','Observações']];
    $tem = false;
    foreach ($blocos as [$c, $ic, $t]) {
        if (empty($r[$c])) continue; $tem = true;
        echo '<div class="mb-3"><div class="small fw-bold text-muted text-uppercase mb-1"><i class="bi ' . $ic . ' me-1"></i>' . $t . '</div><div class="small">' . nl2br(htmlspecialchars($r[$c])) . '</div></div>';
    }
    if (!$tem) echo '<p class="text-muted small mb-0">Sem informações de hospedagem ou logística ainda.</p>';
}
