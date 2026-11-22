<?php
// includes/veiculos.php – v9: quilometragem e histórico de cada veículo

function kmFmt($km): string {
    return ($km === null || $km === '') ? '—' : number_format((int)$km, 0, ',', '.') . ' km';
}

/** "1.400", "1400 km" → 1400 · vazio → null */
function kmParse($v): ?int {
    $v = preg_replace('/\D/', '', (string)$v);
    return $v === '' ? null : (int)$v;
}

/** Recalcula veiculos.km_atual a partir da leitura mais recente. */
function veiculoRecalcularKm(PDO $pdo, int $veiculoId): void {
    $s = $pdo->prepare("SELECT km FROM veiculo_km WHERE veiculo_id=? ORDER BY criado_em DESC, id DESC LIMIT 1");
    $s->execute([$veiculoId]); $km = $s->fetchColumn();
    if ($km !== false) $pdo->prepare("UPDATE veiculos SET km_atual=? WHERE id=?")->execute([(int)$km, $veiculoId]);
}

/**
 * Registra uma leitura de km.
 * - Com $osId: uma leitura por OS (informar de novo corrige a mesma) e grava ordens_servico.km_entrada.
 * - Se a km for menor que a leitura anterior do carro e não houver $confirmado, não grava e devolve o aviso.
 * Retorna ['ok'=>bool, 'aviso'=>?string, 'pedir_confirmacao'=>bool]
 */
function veiculoRegistrarKm(PDO $pdo, int $veiculoId, int $km, ?int $osId, int $usuarioId, string $origem = 'os', bool $confirmado = false, ?string $obs = null): array {
    $v = $pdo->prepare("SELECT id, km_atual FROM veiculos WHERE id=?"); $v->execute([$veiculoId]); $v = $v->fetch();
    if (!$v) return ['ok' => false, 'aviso' => 'Veículo não encontrado.', 'pedir_confirmacao' => false];

    // Leitura anterior: a mais recente que não seja desta mesma OS
    $a = $pdo->prepare("SELECT km FROM veiculo_km WHERE veiculo_id=? AND (ordem_servico_id IS NULL OR ordem_servico_id<>?) ORDER BY criado_em DESC, id DESC LIMIT 1");
    $a->execute([$veiculoId, $osId ?? 0]); $anterior = $a->fetchColumn();
    if ($anterior === false) $anterior = $v['km_atual'];

    if ($anterior !== null && $km < (int)$anterior && !$confirmado) {
        return ['ok' => false, 'pedir_confirmacao' => true,
                'aviso' => 'A km informada (' . kmFmt($km) . ') é menor que a última registrada no carro (' . kmFmt($anterior) . '). Confira o painel; se estiver certo (ex.: troca de painel), marque a confirmação e salve de novo.'];
    }

    $existente = null;
    if ($osId) {
        $e = $pdo->prepare("SELECT id FROM veiculo_km WHERE ordem_servico_id=? AND veiculo_id=? ORDER BY id DESC LIMIT 1");
        $e->execute([$osId, $veiculoId]); $existente = $e->fetchColumn() ?: null;
    }
    if ($existente) {
        $pdo->prepare("UPDATE veiculo_km SET km=?, registrado_por=?, observacao=COALESCE(?,observacao), atualizado_em=NOW() WHERE id=?")
            ->execute([$km, $usuarioId, $obs, $existente]);
    } else {
        $pdo->prepare("INSERT INTO veiculo_km (veiculo_id, km, ordem_servico_id, origem, observacao, registrado_por) VALUES (?,?,?,?,?,?)")
            ->execute([$veiculoId, $km, $osId, $origem, $obs, $usuarioId]);
    }
    if ($osId) $pdo->prepare("UPDATE ordens_servico SET km_entrada=? WHERE id=?")->execute([$km, $osId]);
    veiculoRecalcularKm($pdo, $veiculoId);
    return ['ok' => true, 'aviso' => null, 'pedir_confirmacao' => false];
}

/** OS trocou de carro: tira a leitura do carro antigo e recalcula. */
function veiculoDesvincularKmOS(PDO $pdo, int $osId, ?int $novoVeiculoId): void {
    $s = $pdo->prepare("SELECT DISTINCT veiculo_id FROM veiculo_km WHERE ordem_servico_id=? AND veiculo_id<>?");
    $s->execute([$osId, $novoVeiculoId ?? 0]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $pdo->prepare("DELETE FROM veiculo_km WHERE ordem_servico_id=? AND veiculo_id=?")->execute([$osId, $vid]);
        veiculoRecalcularKm($pdo, (int)$vid);
    }
}

/** Leituras de km do veículo (mais recentes primeiro). */
function veiculoLeiturasKm(PDO $pdo, int $veiculoId): array {
    $s = $pdo->prepare("SELECT k.*, u.nome AS registrado_nome, os.numero_os, os.titulo AS os_titulo
                        FROM veiculo_km k LEFT JOIN usuarios u ON u.id=k.registrado_por LEFT JOIN ordens_servico os ON os.id=k.ordem_servico_id
                        WHERE k.veiculo_id=? ORDER BY k.criado_em DESC, k.id DESC");
    $s->execute([$veiculoId]); return $s->fetchAll();
}

/** Todas as OS do veículo (mais recentes primeiro). */
function veiculoOrdens(PDO $pdo, int $veiculoId): array {
    $s = $pdo->prepare("SELECT os.*, r.nome AS rally_nome, um.nome AS mecanico_nome,
                               (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
                               (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos
                        FROM ordens_servico os LEFT JOIN rallys r ON r.id=os.rally_id LEFT JOIN usuarios um ON um.id=os.mecanico_id
                        WHERE os.veiculo_id=? ORDER BY os.criado_em DESC, os.id DESC");
    $s->execute([$veiculoId]); return $s->fetchAll();
}

/** Texto do Local/Rally da OS. */
function osLocalLabel(array $os): string {
    if (($os['local'] ?? 'oficina') === 'rally' && !empty($os['rally_nome'])) return $os['rally_nome'];
    return 'Oficina';
}

/** Valor do select Local/Rally: 'oficina' ou o id da corrida → [local, rally_id] */
function osLocalDoPost($valor): array {
    $id = (int)$valor;
    return $id > 0 ? ['rally', $id] : ['oficina', null];
}

/** <select> Local/Rally (Oficina + corridas ativas). */
function osSelectLocal(array $rallys, ?array $os = null, string $name = 'local_rally'): string {
    $atual = ($os && ($os['local'] ?? '') === 'rally') ? (int)$os['rally_id'] : 0;
    $h = '<select name="' . $name . '" class="form-select"><option value="oficina"' . (!$atual ? ' selected' : '') . '>🔧 Oficina</option>';
    if ($rallys) {
        $h .= '<optgroup label="Rally / Corrida">';
        foreach ($rallys as $r) $h .= '<option value="' . (int)$r['id'] . '"' . ($atual === (int)$r['id'] ? ' selected' : '') . '>🏁 ' . htmlspecialchars($r['nome']) . '</option>';
        $h .= '</optgroup>';
    }
    return $h . '</select>';
}
