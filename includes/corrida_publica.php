<?php
// includes/corrida_publica.php – Telas de corrida para cliente e mecânico (somente leitura, v8)
// Uso: defina $papel ('cliente'|'mecanico') e $u antes de incluir. Para a página de detalhe, defina $rallyId.
require_once __DIR__ . '/corridas.php';

/** IDs das corridas que o usuário pode ver. */
function corridasDoUsuario(PDO $pdo, string $papel, int $uid): array {
    if ($papel === 'cliente')
        $s = $pdo->prepare("SELECT rally_id FROM rally_participantes WHERE cliente_id=? UNION SELECT rally_id FROM ordens_servico WHERE cliente_id=? AND rally_id IS NOT NULL");
    else
        $s = $pdo->prepare("SELECT rally_id FROM rally_equipe WHERE usuario_id=? UNION SELECT rally_id FROM ordens_servico WHERE mecanico_id=? AND rally_id IS NOT NULL");
    $s->execute([$uid, $uid]);
    return array_map('intval', array_column($s->fetchAll(), 'rally_id'));
}

function renderListaCorridas(PDO $pdo, string $papel, int $uid): void {
    $ids = corridasDoUsuario($pdo, $papel, $uid);
    echo '<div class="d-flex justify-content-between align-items-center mb-4"><h5 class="fw-bold mb-0"><i class="bi bi-flag-fill me-2 text-danger"></i>' . ($papel === 'cliente' ? 'Minhas corridas' : 'Corridas em que estou escalado') . '</h5></div>';
    if (!$ids) { echo '<div class="card border-0 shadow-sm text-center py-5 text-muted"><i class="bi bi-flag fs-1 d-block mb-2"></i>' . ($papel === 'cliente' ? 'Você ainda não está inscrito em nenhuma corrida.' : 'Você ainda não foi escalado para nenhuma corrida.') . '</div>'; return; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM rally_participantes p WHERE p.rally_id=r.id) AS n_carros FROM rallys r WHERE r.id IN ($ph) AND r.ativo=1 ORDER BY (r.data_fim IS NOT NULL AND r.data_fim < CURDATE()), r.data_inicio");
    $s->execute($ids); $lista = $s->fetchAll();
    $proximas = array_filter($lista, fn($r) => !$r['data_fim'] || $r['data_fim'] >= date('Y-m-d'));
    $passadas = array_filter($lista, fn($r) => $r['data_fim'] && $r['data_fim'] < date('Y-m-d'));
    foreach (['Próximas' => $proximas, 'Anteriores' => $passadas] as $tit => $grupo) {
        if (!$grupo) continue;
        echo '<div class="text-uppercase small fw-bold text-muted mb-2 mt-3">' . $tit . '</div><div class="row g-3">';
        foreach ($grupo as $r) {
            $dias = rallyDiasPara($r);
            $info = '';
            if ($papel === 'cliente') { $p = $pdo->prepare("SELECT numero_carro FROM rally_participantes WHERE rally_id=? AND cliente_id=? LIMIT 1"); $p->execute([$r['id'], $uid]); $n = $p->fetchColumn(); if ($n) $info = 'Carro #' . htmlspecialchars($n); }
            else { $p = $pdo->prepare("SELECT funcao FROM rally_equipe WHERE rally_id=? AND usuario_id=?"); $p->execute([$r['id'], $uid]); $info = htmlspecialchars((string)$p->fetchColumn()); }
            echo '<div class="col-md-6 col-xl-4"><a href="ver_corrida.php?id=' . $r['id'] . '" class="text-decoration-none"><div class="rally-hero h-100 card-link" style="padding:18px">';
            echo '<div class="d-flex justify-content-between position-relative" style="z-index:1"><div>' . rallyBadge($r['status'] ?? 'planejada');
            echo '<div class="fw-bold fs-5 mt-1">' . htmlspecialchars($r['nome']) . '</div><div class="small opacity-75">' . htmlspecialchars($r['local'] ?: '') . '</div><div class="small opacity-75">' . rallyPeriodo($r) . '</div>';
            if ($info) echo '<div class="small mt-2"><i class="bi bi-person-badge me-1"></i>' . $info . '</div>';
            echo '</div>';
            if ($dias !== null && $dias > 0) echo '<div class="text-end"><div class="contagem">' . $dias . '</div><div class="small opacity-75">dias</div></div>';
            echo '</div></div></a></div>';
        }
        echo '</div>';
    }
}

function renderDetalheCorrida(PDO $pdo, string $papel, int $uid, int $rallyId): void {
    $rs = $pdo->prepare("SELECT * FROM rallys WHERE id=? AND ativo=1"); $rs->execute([$rallyId]); $rally = $rs->fetch();
    if (!$rally || !in_array($rallyId, corridasDoUsuario($pdo, $papel, $uid), true)) {
        echo '<div class="alert alert-warning">Corrida não encontrada.</div><a href="corridas.php">← Voltar</a>'; return;
    }
    $participantes = rallyParticipantes($pdo, $rallyId);
    $meus = array_values(array_filter($participantes, fn($p) => $papel === 'cliente' && $p['cliente_id'] == $uid));
    $equipe = rallyEquipe($pdo, $rallyId);
    $prog = rallyProgramacao($pdo, $rallyId);
    $minhaFuncao = null; foreach ($equipe as $e) if ($e['usuario_id'] == $uid) $minhaFuncao = $e['funcao'];

    if ($papel === 'cliente') {
        $os = $pdo->prepare("SELECT os.*, (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) af_t, (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) af_f FROM ordens_servico os WHERE os.rally_id=? AND os.cliente_id=? ORDER BY os.criado_em");
        $os->execute([$rallyId, $uid]);
        $fotos = $pdo->prepare("SELECT * FROM rally_fotos WHERE rally_id=? AND (participante_id IS NULL OR participante_id IN (SELECT id FROM rally_participantes WHERE cliente_id=?)) ORDER BY criado_em DESC");
        $fotos->execute([$rallyId, $uid]); $fotos = $fotos->fetchAll();
    } else {
        $os = $pdo->prepare("SELECT os.*, c.nome AS cliente_nome, (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) af_t, (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) af_f FROM ordens_servico os JOIN usuarios c ON c.id=os.cliente_id WHERE os.rally_id=? AND os.mecanico_id=? AND os.status NOT IN ('concluida','cancelada') ORDER BY os.criado_em");
        $os->execute([$rallyId, $uid]);
        $fotos = [];
    }
    $minhasOS = $os->fetchAll();
    $dias = rallyDiasPara($rally);
    $statusLabel = ['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
    ?>
    <a href="corridas.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Corridas</a>
    <div class="rally-hero mt-2 mb-4">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative" style="z-index:1">
        <div>
          <div class="mb-1"><?= rallyBadge($rally['status'] ?? 'planejada') ?></div>
          <h4 class="fw-bold mb-1"><?= htmlspecialchars($rally['nome']) ?></h4>
          <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($rally['local'] ?: 'Local a definir') ?> · <i class="bi bi-calendar3 ms-1 me-1"></i><?= rallyPeriodo($rally) ?></div>
          <?php if ($meus): ?><div class="mt-2"><?php foreach ($meus as $m): ?><span class="badge bg-danger me-1 fs-6"><?= $m['numero_carro'] ? '#'.htmlspecialchars($m['numero_carro']) : 'Inscrito' ?></span><?php endforeach; ?></div><?php endif; ?>
          <?php if ($minhaFuncao): ?><div class="mt-2 small"><i class="bi bi-person-badge me-1"></i>Sua função: <strong><?= htmlspecialchars($minhaFuncao) ?></strong></div><?php endif; ?>
        </div>
        <?php if ($dias !== null && $dias > 0): ?><div class="text-end"><div class="contagem"><?= $dias ?></div><div class="small opacity-75">dia(s) para a largada</div></div>
        <?php elseif ($dias !== null && (empty($rally['data_fim']) || $rally['data_fim'] >= date('Y-m-d'))): ?><div class="contagem text-warning">Em prova</div><?php endif; ?>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-calendar-week me-2 text-primary"></i>Programação / cronograma</div>
          <div class="card-body"><?php renderProgramacao($prog); ?></div>
        </div>
        <?php if ($participantes): ?>
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-car-front me-2 text-danger"></i>Carros da equipe <span class="badge bg-secondary ms-1"><?= count($participantes) ?></span></div>
          <div class="card-body"><div class="row g-3"><?php foreach ($participantes as $p): ?><div class="col-6 col-md-4"><?php renderParticipante($p); ?></div><?php endforeach; ?></div></div>
        </div>
        <?php endif; ?>
        <?php if ($fotos): ?>
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-images me-2 text-danger"></i>Fotos da corrida</div>
          <div class="card-body"><div class="thumb-grid"><?php foreach ($fotos as $f): ?><div class="th"><img src="<?= fotoThumbUrl($f['thumb'], $f['caminho']) ?>" data-galeria="rally" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($f['descricao'] ?? '') ?>"></div><?php endforeach; ?></div></div>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-lg-5">
        <?php if ($minhasOS): ?>
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-clipboard-check me-2 text-warning"></i><?= $papel === 'cliente' ? 'Preparação do meu carro' : 'Minhas OS desta corrida' ?></div>
          <ul class="list-group list-group-flush small">
            <?php foreach ($minhasOS as $o): $pc = $o['af_t'] ? round($o['af_f'] / $o['af_t'] * 100) : null; ?>
            <a href="ver_os.php?id=<?= $o['id'] ?>" class="list-group-item list-group-item-action">
              <div class="d-flex justify-content-between"><span><span class="badge bg-dark me-1"><?= htmlspecialchars($o['numero_os'] ?? '') ?></span><?= htmlspecialchars($o['titulo']) ?></span><span class="badge badge-<?= $o['status'] ?>"><?= $statusLabel[$o['status']] ?></span></div>
              <?php if ($papel === 'mecanico'): ?><div class="text-muted"><?= htmlspecialchars($o['cliente_nome']) ?></div><?php endif; ?>
              <?php if ($pc !== null): ?><div class="d-flex align-items-center gap-2 mt-1"><div class="progresso-os flex-fill"><div style="width:<?= $pc ?>%"></div></div><span class="text-muted"><?= $o['af_f'] ?>/<?= $o['af_t'] ?></span></div><?php endif; ?>
            </a>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-people me-2 text-primary"></i>Equipe</div>
          <div class="card-body">
            <?php if ($equipe): foreach ($equipe as $e): ?>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="pessoa-chip m-0"><span class="av"><?= iniciais($e['nome']) ?></span><?= htmlspecialchars($e['nome']) ?></span>
              <span class="small text-muted"><?= htmlspecialchars($e['funcao'] ?? '') ?><?php if ($papel === 'mecanico' && $e['telefone']): ?> · <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $e['telefone'])) ?>"><?= htmlspecialchars($e['telefone']) ?></a><?php endif; ?></span>
            </div>
            <?php endforeach; else: ?><p class="text-muted small mb-0">Equipe ainda não definida.</p><?php endif; ?>
          </div>
        </div>
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-info-circle me-2"></i>Informações práticas</div>
          <div class="card-body"><?php renderInfoPratica($rally); ?></div>
        </div>
      </div>
    </div>
    <?php fotosModalGaleria();
}
