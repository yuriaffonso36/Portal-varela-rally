<?php
// migrate9.php – v9 Fase 1: Local/Rally, quilometragem dos veículos, dados de pagamento (PIX)
// Rode UMA vez logado como admin: https://varelarally.com.br/migrate9.php  — depois apague o arquivo do servidor.
// É seguro rodar de novo: cada passo verifica se já foi aplicado.
require_once 'config.php';
require_once 'includes/auth.php';
requireRole('admin');

$log = [];
function m9ColExiste(PDO $pdo, string $t, string $c): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return (bool)$s->fetchColumn();
}
function m9Passo(PDO $pdo, array &$log, string $desc, string $sql): bool {
    try { $pdo->exec($sql); $log[] = ['ok', $desc]; return true; }
    catch (PDOException $e) { $log[] = ['erro', $desc . ' — ' . $e->getMessage()]; return false; }
}
function m9AddCol(PDO $pdo, array &$log, string $t, string $c, string $def): bool {
    if (m9ColExiste($pdo, $t, $c)) { $log[] = ['pulo', "$t.$c já existe"]; return false; }
    return m9Passo($pdo, $log, "Coluna $t.$c", "ALTER TABLE `$t` ADD COLUMN `$c` $def");
}

// 1) OS: Local/Rally e km de entrada
if (m9AddCol($pdo, $log, 'ordens_servico', 'local', "ENUM('oficina','rally') NOT NULL DEFAULT 'oficina' AFTER rally_id")) {
    m9Passo($pdo, $log, 'OS antigas com corrida marcadas como Local = Rally', "UPDATE ordens_servico SET local='rally' WHERE rally_id IS NOT NULL");
}
m9AddCol($pdo, $log, 'ordens_servico', 'km_entrada', "INT UNSIGNED NULL DEFAULT NULL AFTER veiculo_id");

// 2) Veículos: km atual e chassi
m9AddCol($pdo, $log, 'veiculos', 'km_atual', "INT UNSIGNED NULL DEFAULT NULL");
m9AddCol($pdo, $log, 'veiculos', 'chassi', "VARCHAR(40) NULL DEFAULT NULL");

// 3) Histórico de quilometragem
m9Passo($pdo, $log, 'Tabela veiculo_km', "CREATE TABLE IF NOT EXISTS veiculo_km (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    veiculo_id INT UNSIGNED NOT NULL,
    km INT UNSIGNED NOT NULL,
    ordem_servico_id INT UNSIGNED NULL DEFAULT NULL,
    origem ENUM('cadastro','os','manual') NOT NULL DEFAULT 'os',
    observacao VARCHAR(255) NULL DEFAULT NULL,
    registrado_por INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL,
    KEY idx_vkm_veiculo (veiculo_id, criado_em),
    KEY idx_vkm_os (ordem_servico_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 4) Dados de pagamento em todos os usuários
m9AddCol($pdo, $log, 'usuarios', 'pix_tipo', "ENUM('cpf','cnpj','email','telefone','aleatoria') NULL DEFAULT NULL");
m9AddCol($pdo, $log, 'usuarios', 'pix_chave', "VARCHAR(140) NULL DEFAULT NULL");
m9AddCol($pdo, $log, 'usuarios', 'dados_bancarios', "VARCHAR(255) NULL DEFAULT NULL");

// 5) username: aceita NULL (clientes antigos ficaram sem) e troca '' por NULL para não colidir no UNIQUE
$col = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='username'")->fetch();
if ($col && $col['IS_NULLABLE'] === 'NO') {
    m9Passo($pdo, $log, 'usuarios.username passa a aceitar vazio (NULL)', "ALTER TABLE usuarios MODIFY username {$col['COLUMN_TYPE']} NULL DEFAULT NULL");
}
m9Passo($pdo, $log, "username vazio ('') vira NULL", "UPDATE usuarios SET username=NULL WHERE username=''");

$semUser = $pdo->query("SELECT id, nome, tipo FROM usuarios WHERE username IS NULL ORDER BY tipo, nome")->fetchAll();
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>migrate9 – Varela Rally</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f4f6f9}li{margin:4px 0}.ok{color:#198754}.erro{color:#dc3545;font-weight:600}.pulo{color:#888}.box{background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}</style></head><body>
<h2>migrate9 — v9 Fase 1</h2>
<div class="box"><ul>
<?php foreach ($log as [$t, $msg]): ?><li class="<?= $t ?>"><?= $t === 'ok' ? '✔' : ($t === 'erro' ? '✖' : '–') ?> <?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
</ul></div>
<div class="box"><strong>Usuários sem username (não conseguem fazer login):</strong>
<?php if ($semUser): ?><ul><?php foreach ($semUser as $s): ?><li>#<?= $s['id'] ?> — <?= htmlspecialchars($s['nome']) ?> (<?= $s['tipo'] ?>)</li><?php endforeach; ?></ul>
<p>Defina o username de cada um em <a href="admin/clientes.php">Admin → Clientes</a> (editar).</p>
<?php else: ?> nenhum. ✔<?php endif; ?></div>
<p><strong>Pronto.</strong> Apague o <code>migrate9.php</code> do servidor.</p>
</body></html>
