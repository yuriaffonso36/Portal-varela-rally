<?php
// ============================================================
//  CONFIGURAÇÕES DO BANCO DE DADOS (EXEMPLO)
//  Copie este arquivo para config.php e preencha com os dados
//  reais da Locaweb. O config.php NÃO vai para o GitHub.
// ============================================================
define('DB_HOST', 'SEU_HOST.mysql.dbaas.com.br');
define('DB_NAME', 'NOME_DO_BANCO');
define('DB_USER', 'USUARIO_DO_BANCO');
define('DB_PASS', 'SENHA_DO_BANCO');
define('BASE_URL', 'https://varelarally.com.br/'); // URL do seu site
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');

// Conexão PDO
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("<h2 style='font-family:sans-serif;color:red'>Erro de conexão com o banco de dados.<br><small>" . $e->getMessage() . "</small></h2>");
}
