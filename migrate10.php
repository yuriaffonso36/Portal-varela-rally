<?php
// migrate10.php – Fase 2: aprovação de itens (cliente/mecânico), "Cobrar de" no navegador, reembolsos, histórico de aprovações
// Rode UMA vez logado como admin: https://varelarally.com.br/migrate10.php — depois apague do servidor. Pode rodar de novo sem problema.
require_once 'config.php';
require_once 'includes/auth.php';
requireRole('admin');

$log = [];
function m10Col(PDO $pdo, string $t, string $c) {
    $s = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return $s->fetchColumn();
}
function m10Passo(PDO $pdo, array &$log, string $desc, string $sql): bool {
    try { $n = $pdo->exec($sql); $log[] = ['ok', $desc . ($n ? " ($n linha(s))" : '')]; return true; }
    catch (PDOException $e) { $log[] = ['erro', $desc . ' — ' . $e->getMessage()]; return false; }
}
function m10AddCol(PDO $pdo, array &$log, string $t, string $c, string $def): bool {
    if (m10Col($pdo, $t, $c) !== false) { $log[] = ['pulo', "$t.$c já existe"]; return false; }
    return m10Passo($pdo, $log, "Coluna $t.$c", "ALTER TABLE `$t` ADD COLUMN `$c` $def");
}

// 1) Itens da OS: origem 'cliente' + status de aprovação
$tipoOrigem = m10Col($pdo, 'os_itens', 'origem');
if ($tipoOrigem && stripos($tipoOrigem, "'cliente'") === false && stripos($tipoOrigem, 'enum(') === 0) {
    $novo = substr($tipoOrigem, 0, -1) . ",'cliente')";
    m10Passo($pdo, $log, 'os_itens.origem aceita "cliente"', "ALTER TABLE os_itens MODIFY origem $novo NOT NULL DEFAULT 'admin'");
} else $log[] = ['pulo', 'os_itens.origem já aceita cliente (ou não é ENUM): ' . $tipoOrigem];

if (m10AddCol($pdo, $log, 'os_itens', 'status_aprovacao', "ENUM('aprovado','pendente','recusado') NOT NULL DEFAULT 'aprovado'")) {
    // Itens do mecânico ainda sem valor em OS abertas passam a esperar aprovação
    m10Passo($pdo, $log, 'Itens do mecânico sem valor em OS abertas → pendentes',
        "UPDATE os_itens oi JOIN ordens_servico os ON os.id=oi.ordem_servico_id SET oi.status_aprovacao='pendente'
         WHERE oi.origem='mecanico' AND oi.valor IS NULL AND os.status NOT IN ('concluida','cancelada')");
}
m10AddCol($pdo, $log, 'os_itens', 'motivo_recusa', "VARCHAR(255) NULL DEFAULT NULL");
m10AddCol($pdo, $log, 'os_itens', 'solicitado_por', "INT UNSIGNED NULL DEFAULT NULL");
m10AddCol($pdo, $log, 'os_itens', 'analisado_por', "INT UNSIGNED NULL DEFAULT NULL");
m10AddCol($pdo, $log, 'os_itens', 'analisado_em', "DATETIME NULL DEFAULT NULL");

// 2) OS do navegador: a quem cobrar
m10AddCol($pdo, $log, 'nav_os', 'cobrar_de', "ENUM('piloto','equipe') NOT NULL DEFAULT 'piloto'");

// 3) Reembolsos da equipe
m10Passo($pdo, $log, 'Tabela reembolsos', "CREATE TABLE IF NOT EXISTS reembolsos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    rally_id INT UNSIGNED NULL DEFAULT NULL,
    categoria_id INT UNSIGNED NULL DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_despesa DATE NOT NULL,
    comprovante VARCHAR(255) NULL DEFAULT NULL,
    sem_comprovante TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
    motivo_recusa VARCHAR(255) NULL DEFAULT NULL,
    analisado_por INT UNSIGNED NULL DEFAULT NULL,
    analisado_em DATETIME NULL DEFAULT NULL,
    pago TINYINT(1) NOT NULL DEFAULT 0,
    pago_em DATETIME NULL DEFAULT NULL,
    pago_por INT UNSIGNED NULL DEFAULT NULL,
    comprovante_pagamento VARCHAR(255) NULL DEFAULT NULL,
    gasto_id INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reemb_usuario (usuario_id, status),
    KEY idx_reemb_rally (rally_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
m10AddCol($pdo, $log, 'rally_gastos', 'reembolso_id', "INT UNSIGNED NULL DEFAULT NULL");

// 4) Histórico de aprovações
m10Passo($pdo, $log, 'Tabela aprovacoes_log', "CREATE TABLE IF NOT EXISTS aprovacoes_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    ref_id INT UNSIGNED NOT NULL,
    acao ENUM('aprovado','recusado','pago') NOT NULL,
    resumo VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) NULL DEFAULT NULL,
    solicitante_id INT UNSIGNED NULL DEFAULT NULL,
    admin_id INT UNSIGNED NULL DEFAULT NULL,
    motivo VARCHAR(255) NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aplog_data (criado_em),
    KEY idx_aplog_tipo (tipo, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 5) Pasta de comprovantes de reembolso
$dir = UPLOAD_DIR . 'reembolsos/';
if (!is_dir($dir)) {
    if (@mkdir($dir, 0755, true)) $log[] = ['ok', 'Pasta uploads/reembolsos criada'];
    else $log[] = ['erro', 'Não consegui criar uploads/reembolsos — crie pelo gerenciador de arquivos'];
}
else $log[] = ['pulo', 'Pasta uploads/reembolsos já existe'];
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>migrate10 – Varela Rally</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f4f6f9}li{margin:4px 0}.ok{color:#198754}.erro{color:#dc3545;font-weight:600}.pulo{color:#888}.box{background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}</style></head><body>
<h2>migrate10 — Fase 2</h2>
<div class="box"><ul>
<?php foreach ($log as [$t, $msg]): ?><li class="<?= $t ?>"><?= $t === 'ok' ? '✔' : ($t === 'erro' ? '✖' : '–') ?> <?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
</ul></div>
<p><strong>Pronto.</strong> Apague o <code>migrate10.php</code> do servidor. <a href="admin/aprovacoes.php">Abrir a Central de Aprovações →</a></p>
</body></html>
