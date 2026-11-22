<?php
// estoque/ajax_historico.php
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('estoque','admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo '<p class="text-muted text-center">Item inválido.</p>'; exit; }

$stmt = $pdo->prepare("
    SELECT s.*, u.nome AS usuario_nome, os.numero_os AS os_numero, c.nome AS cliente_nome
    FROM estoque_saidas s
    JOIN usuarios u ON u.id=s.usuario_id
    LEFT JOIN ordens_servico os ON os.id=s.ordem_servico_id
    LEFT JOIN usuarios c ON c.id=s.cliente_id
    WHERE s.estoque_id=?
    ORDER BY s.criado_em DESC LIMIT 50");
$stmt->execute([$id]);
$saidas = $stmt->fetchAll();

if (!$saidas) {
    echo '<p class="text-muted text-center py-3">Nenhuma saída registrada para este item.</p>';
    exit;
}

echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">';
echo '<thead><tr><th>Data</th><th class="text-center">Qtd</th><th>OS</th><th>Cliente</th><th>Por</th></tr></thead><tbody>';
foreach ($saidas as $s) {
    $os = $s['ordem_servico_id'] ? "<a href='". BASE_URL ."/admin/editar_os.php?id={$s['ordem_servico_id']}' class='badge bg-dark text-decoration-none'>OS ".htmlspecialchars((string)$s['os_numero'])."</a>" : '—';
    echo "<tr>
        <td class='text-muted'>".date('d/m/Y H:i', strtotime($s['criado_em']))."</td>
        <td class='text-center'><span class='badge bg-danger'>−{$s['quantidade']}</span></td>
        <td>$os</td>
        <td>".htmlspecialchars($s['cliente_nome'] ?? '—')."</td>
        <td class='text-muted'>".htmlspecialchars($s['usuario_nome'])."</td>
    </tr>";
}
echo '</tbody></table></div>';
