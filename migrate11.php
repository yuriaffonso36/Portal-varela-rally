<?php
// migrate11.php – Fase 3: Oficina (despesas, gastos fixos, categorias), folha de pagamento, diárias e logins de equipe
// Rode UMA vez logado como admin: https://varelarally.com.br/migrate11.php — depois apague do servidor. Pode rodar de novo sem problema.
require_once 'config.php';
require_once 'includes/auth.php';
requireRole('admin');

$log = [];
function m11Col(PDO $pdo, string $t, string $c) {
    $s = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return $s->fetchColumn();
}
function m11Passo(PDO $pdo, array &$log, string $desc, string $sql, array $par = []): bool {
    try { if ($par) { $s = $pdo->prepare($sql); $s->execute($par); $n = $s->rowCount(); } else $n = $pdo->exec($sql);
          $log[] = ['ok', $desc . ($n ? " ($n)" : '')]; return true; }
    catch (PDOException $e) { $log[] = ['erro', $desc . ' — ' . $e->getMessage()]; return false; }
}
function m11AddCol(PDO $pdo, array &$log, string $t, string $c, string $def): bool {
    if (m11Col($pdo, $t, $c) !== false) { $log[] = ['pulo', "$t.$c já existe"]; return false; }
    return m11Passo($pdo, $log, "Coluna $t.$c", "ALTER TABLE `$t` ADD COLUMN `$c` $def");
}

// 1) Tipo de login "equipe" (motoristas, apoio...) + dados de folha em todos os usuários
$tipo = m11Col($pdo, 'usuarios', 'tipo');
if ($tipo && stripos($tipo, "'equipe'") === false && stripos($tipo, 'enum(') === 0) {
    m11Passo($pdo, $log, 'usuarios.tipo aceita "equipe"', "ALTER TABLE usuarios MODIFY tipo " . substr($tipo, 0, -1) . ",'equipe') NOT NULL");
} else $log[] = ['pulo', 'usuarios.tipo já aceita equipe: ' . $tipo];
m11AddCol($pdo, $log, 'usuarios', 'funcao', "VARCHAR(60) NULL DEFAULT NULL");
m11AddCol($pdo, $log, 'usuarios', 'na_folha', "TINYINT(1) NOT NULL DEFAULT 0");
m11AddCol($pdo, $log, 'usuarios', 'salario_mensal', "DECIMAL(10,2) NULL DEFAULT NULL");
m11AddCol($pdo, $log, 'usuarios', 'valor_diaria', "DECIMAL(10,2) NULL DEFAULT NULL");
m11AddCol($pdo, $log, 'usuarios', 'sem_acesso', "TINYINT(1) NOT NULL DEFAULT 0");

// 2) Despesas da oficina
m11Passo($pdo, $log, 'Tabela despesa_categorias', "CREATE TABLE IF NOT EXISTS despesa_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    cor VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!(int)$pdo->query("SELECT COUNT(*) FROM despesa_categorias")->fetchColumn()) {
    $cats = [['Aluguel','#6f42c1'],['Energia','#fd7e14'],['Água','#0dcaf0'],['Internet e telefone','#0d6efd'],['Ferramentas','#495057'],
             ['Materiais de consumo','#20c997'],['Peças para a oficina','#198754'],['Limpeza','#6610f2'],['Alimentação','#ffc107'],['Combustível','#dc3545'],
             ['Impostos e taxas','#343a40'],['Manutenção do prédio','#795548'],['Folha de pagamento','#d63384'],['Reembolsos','#e83e8c'],['Outros','#adb5bd']];
    $ins = $pdo->prepare("INSERT INTO despesa_categorias (nome,cor) VALUES (?,?)");
    foreach ($cats as $c) $ins->execute($c);
    $log[] = ['ok', count($cats) . ' categorias padrão cadastradas'];
} else $log[] = ['pulo', 'Categorias já existem'];

m11Passo($pdo, $log, 'Tabela despesas_fixas', "CREATE TABLE IF NOT EXISTS despesas_fixas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    descricao VARCHAR(120) NOT NULL,
    categoria_id INT UNSIGNED NULL DEFAULT NULL,
    valor DECIMAL(10,2) NOT NULL,
    dia_vencimento TINYINT UNSIGNED NOT NULL DEFAULT 10,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

m11Passo($pdo, $log, 'Tabela despesas', "CREATE TABLE IF NOT EXISTS despesas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT UNSIGNED NULL DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data DATE NOT NULL,
    forma_pagamento ENUM('pix','dinheiro','cartao','boleto','transferencia','outro') NOT NULL DEFAULT 'pix',
    fornecedor VARCHAR(120) NULL DEFAULT NULL,
    comprovante VARCHAR(255) NULL DEFAULT NULL,
    origem ENUM('manual','fixa','folha','reembolso') NOT NULL DEFAULT 'manual',
    fixa_id INT UNSIGNED NULL DEFAULT NULL,
    competencia CHAR(7) NULL DEFAULT NULL,
    ref_id INT UNSIGNED NULL DEFAULT NULL,
    lancado_por INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_desp_data (data),
    KEY idx_desp_fixa (fixa_id, competencia),
    KEY idx_desp_origem (origem, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 3) Folha de pagamento e diárias
m11Passo($pdo, $log, 'Tabela folha_diarias', "CREATE TABLE IF NOT EXISTS folha_diarias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    rally_id INT UNSIGNED NULL DEFAULT NULL,
    data DATE NOT NULL,
    quantidade DECIMAL(4,1) NOT NULL DEFAULT 1.0,
    valor_unit DECIMAL(10,2) NOT NULL,
    observacao VARCHAR(255) NULL DEFAULT NULL,
    lancado_por INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dia_usuario (usuario_id, data),
    KEY idx_dia_rally (rally_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

m11Passo($pdo, $log, 'Tabela folha_pagamentos', "CREATE TABLE IF NOT EXISTS folha_pagamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    competencia CHAR(7) NOT NULL,
    salario DECIMAL(10,2) NOT NULL DEFAULT 0,
    diarias_qtd DECIMAL(5,1) NOT NULL DEFAULT 0,
    diarias_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    diarias_corrida DECIMAL(10,2) NOT NULL DEFAULT 0,
    adicionais DECIMAL(10,2) NOT NULL DEFAULT 0,
    descontos DECIMAL(10,2) NOT NULL DEFAULT 0,
    observacao VARCHAR(255) NULL DEFAULT NULL,
    liquido DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('aberto','pago') NOT NULL DEFAULT 'aberto',
    pago_em DATETIME NULL DEFAULT NULL,
    pago_por INT UNSIGNED NULL DEFAULT NULL,
    comprovante VARCHAR(255) NULL DEFAULT NULL,
    despesa_id INT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_folha (usuario_id, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 4) Reembolsos de oficina já aprovados → despesas da oficina (uma vez)
$catReemb = (int)$pdo->query("SELECT id FROM despesa_categorias WHERE nome='Reembolsos' LIMIT 1")->fetchColumn() ?: null;
m11Passo($pdo, $log, 'Reembolsos de oficina já aprovados lançados como despesa',
    "INSERT INTO despesas (categoria_id,descricao,valor,data,forma_pagamento,comprovante,origem,ref_id,lancado_por)
     SELECT ?, CONCAT('Reembolso – ', u.nome, ': ', r.descricao), r.valor, r.data_despesa, 'pix', r.comprovante, 'reembolso', r.id, r.analisado_por
     FROM reembolsos r JOIN usuarios u ON u.id=r.usuario_id
     WHERE r.status='aprovado' AND r.rally_id IS NULL AND NOT EXISTS (SELECT 1 FROM despesas d WHERE d.origem='reembolso' AND d.ref_id=r.id)", [$catReemb]);

foreach (['despesas', 'folha'] as $d) {
    $dir = UPLOAD_DIR . $d . '/';
    if (is_dir($dir)) $log[] = ['pulo', "Pasta uploads/$d já existe"];
    elseif (@mkdir($dir, 0755, true)) $log[] = ['ok', "Pasta uploads/$d criada"];
    else $log[] = ['erro', "Não consegui criar uploads/$d — crie pelo gerenciador de arquivos"];
}
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>migrate11 – Varela Rally</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;background:#f4f6f9}li{margin:4px 0}.ok{color:#198754}.erro{color:#dc3545;font-weight:600}.pulo{color:#888}.box{background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}</style></head><body>
<h2>migrate11 — Fase 3</h2>
<div class="box"><ul>
<?php foreach ($log as [$t, $msg]): ?><li class="<?= $t ?>"><?= $t === 'ok' ? '✔' : ($t === 'erro' ? '✖' : '–') ?> <?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
</ul></div>
<p><strong>Pronto.</strong> Apague o <code>migrate11.php</code> do servidor. <a href="admin/oficina.php">Abrir a Oficina →</a> · <a href="admin/folha.php?aba=pessoas">Configurar a folha →</a></p>
</body></html>
