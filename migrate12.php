<?php
// migrate12.php – Fase 4: Google Maps no cronograma, mapa visual do estoque (estantes/prateleiras) e referência BRP nas peças
// Rode UMA vez logado como admin: https://varelarally.com.br/migrate12.php — depois apague do servidor. Pode rodar de novo sem problema.
require_once 'config.php';
require_once 'includes/auth.php';
requireRole('admin');

$log = [];
function m12Col(PDO $pdo, string $t, string $c) {
    $s = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return $s->fetchColumn();
}
function m12Passo(PDO $pdo, array &$log, string $desc, string $sql): bool {
    try { $n = $pdo->exec($sql); $log[] = ['ok', $desc . ($n ? " ($n)" : '')]; return true; }
    catch (PDOException $e) { $log[] = ['erro', $desc . ' — ' . $e->getMessage()]; return false; }
}
function m12AddCol(PDO $pdo, array &$log, string $t, string $c, string $def): bool {
    if (m12Col($pdo, $t, $c) !== false) { $log[] = ['pulo', "$t.$c já existe"]; return false; }
    return m12Passo($pdo, $log, "Coluna $t.$c", "ALTER TABLE `$t` ADD COLUMN `$c` $def");
}

// 1) Cronograma: link do Google Maps + tipo "Ponto de encontro"
m12AddCol($pdo, $log, 'rally_programacao', 'maps_url', "VARCHAR(500) NULL DEFAULT NULL");
$tipo = m12Col($pdo, 'rally_programacao', 'tipo');
if ($tipo && stripos($tipo, 'enum(') === 0 && stripos($tipo, "'encontro'") === false) {
    m12Passo($pdo, $log, 'Programação aceita o tipo "Ponto de encontro"', "ALTER TABLE rally_programacao MODIFY tipo " . substr($tipo, 0, -1) . ",'encontro') NOT NULL DEFAULT 'outro'");
} else $log[] = ['pulo', 'Tipo da programação: ' . $tipo];

// 2) Estoque: estantes e prateleiras
m12Passo($pdo, $log, 'Tabela estoque_estantes', "CREATE TABLE IF NOT EXISTS estoque_estantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL,
    nome VARCHAR(80) NULL DEFAULT NULL,
    prateleiras TINYINT UNSIGNED NOT NULL DEFAULT 4,
    ordem SMALLINT NOT NULL DEFAULT 0,
    cor VARCHAR(7) NOT NULL DEFAULT '#6f42c1',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_estante_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
m12AddCol($pdo, $log, 'estoque', 'estante_id', "INT UNSIGNED NULL DEFAULT NULL");
m12AddCol($pdo, $log, 'estoque', 'prateleira', "TINYINT UNSIGNED NULL DEFAULT NULL");
m12AddCol($pdo, $log, 'estoque', 'posicao', "VARCHAR(20) NULL DEFAULT NULL");

// 3) Referência BRP
m12AddCol($pdo, $log, 'estoque', 'part_number', "VARCHAR(40) NULL DEFAULT NULL");
m12AddCol($pdo, $log, 'estoque', 'imagem_ref', "VARCHAR(255) NULL DEFAULT NULL");

$dir = UPLOAD_DIR . 'estoque_ref/';
if (is_dir($dir)) $log[] = ['pulo', 'Pasta uploads/estoque_ref já existe'];
elseif (@mkdir($dir, 0755, true)) $log[] = ['ok', 'Pasta uploads/estoque_ref criada'];
else $log[] = ['erro', 'Não consegui criar uploads/estoque_ref — crie pelo gerenciador de arquivos'];
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>migrate12 – Varela Rally</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f4f6f9}li{margin:4px 0}.ok{color:#198754}.erro{color:#dc3545;font-weight:600}.pulo{color:#888}.box{background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}</style></head><body>
<h2>migrate12 — Fase 4</h2>
<div class="box"><ul>
<?php foreach ($log as [$t, $msg]): ?><li class="<?= $t ?>"><?= $t === 'ok' ? '✔' : ($t === 'erro' ? '✖' : '–') ?> <?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
</ul></div>
<p><strong>Pronto.</strong> Apague o <code>migrate12.php</code> do servidor. <a href="estoque/mapa.php">Montar o mapa do estoque →</a></p>
</body></html>
