<?php
// migrate13.php – v14: Pagamento de staff nas corridas (freelancers, mecânicos e todos que trabalharam na prova)
// Rode UMA vez logado como admin: https://varelarally.com.br/migrate13.php — depois apague do servidor. Pode rodar de novo sem problema.
require_once 'config.php';
require_once 'includes/auth.php';
requireRole('admin');

$log = [];
function m13Passo(PDO $pdo, array &$log, string $desc, string $sql): bool {
    try { $n = $pdo->exec($sql); $log[] = ['ok', $desc . ($n ? " ($n)" : '')]; return true; }
    catch (PDOException $e) { $log[] = ['erro', $desc . ' — ' . $e->getMessage()]; return false; }
}

// 1) Pagamentos de staff por corrida (só o admin vê)
m13Passo($pdo, $log, 'Tabela rally_staff (pagamento de staff)', "CREATE TABLE IF NOT EXISTS rally_staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rally_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL DEFAULT NULL,
    nome VARCHAR(120) NOT NULL,
    funcao VARCHAR(80) NULL DEFAULT NULL,
    data DATE NOT NULL,
    dias DECIMAL(5,1) NOT NULL DEFAULT 1.0,
    valor_diaria DECIMAL(10,2) NOT NULL DEFAULT 0,
    adicional DECIMAL(10,2) NOT NULL DEFAULT 0,
    adicional_desc VARCHAR(120) NULL DEFAULT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    pix VARCHAR(190) NULL DEFAULT NULL,
    cliente_id INT UNSIGNED NULL DEFAULT NULL,
    cc_id INT UNSIGNED NULL DEFAULT NULL,
    pago TINYINT(1) NOT NULL DEFAULT 0,
    pago_em DATE NULL DEFAULT NULL,
    forma VARCHAR(20) NULL DEFAULT NULL,
    comprovante VARCHAR(255) NULL DEFAULT NULL,
    observacao VARCHAR(255) NULL DEFAULT NULL,
    lancado_por INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_rally (rally_id),
    KEY idx_staff_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2) Pasta dos comprovantes
$dir = UPLOAD_DIR . 'corridas_staff/';
if (is_dir($dir)) $log[] = ['pulo', 'Pasta uploads/corridas_staff já existe'];
elseif (@mkdir($dir, 0755, true)) $log[] = ['ok', 'Pasta uploads/corridas_staff criada'];
else $log[] = ['erro', 'Não consegui criar uploads/corridas_staff — crie a pasta pelo gerenciador de arquivos'];
?><!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><title>migrate13</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f4f6f9}li{margin:4px 0}.ok{color:#198754}.erro{color:#dc3545;font-weight:600}.pulo{color:#888}.box{background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}</style></head><body>
<h2>migrate13 — Financeiro da corrida e pagamento de staff</h2>
<div class="box"><ul>
<?php foreach ($log as [$t, $msg]): ?><li class="<?= $t ?>"><?= $t === 'ok' ? '✔' : ($t === 'erro' ? '✖' : '–') ?> <?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
</ul></div>
<p><strong>Pronto.</strong> Apague o <code>migrate13.php</code> do servidor. <a href="admin/corridas.php">Ir para as corridas →</a></p>
</body></html>
