<?php
// index.php – Login por username
session_start();
require_once 'config.php';

if (!empty($_SESSION['usuario_id'])) {
    $dest = [
        'cliente'   => '/cliente/index.php',
        'mecanico'  => '/mecanico/index.php',
        'admin'     => '/admin/index.php',
        'estoque'   => '/estoque/index.php',
        'navegador' => '/navegador/index.php',
    ];
    header('Location: ' . BASE_URL . ($dest[$_SESSION['usuario_tipo']] ?? '/index.php'));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $senha    = $_POST['senha'] ?? '';

    if ($username && $senha) {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND ativo = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha'])) {
            $_SESSION['usuario_id']       = $user['id'];
            $_SESSION['usuario_nome']     = $user['nome'];
            $_SESSION['usuario_tipo']     = $user['tipo'];
            $_SESSION['usuario_email']    = $user['email'];
            $_SESSION['usuario_username'] = $user['username'];

            $dest = [
                'cliente'   => '/cliente/index.php',
                'mecanico'  => '/mecanico/index.php',
                'admin'     => '/admin/index.php',
                'estoque'   => '/estoque/index.php',
                'navegador' => '/navegador/index.php',
                'equipe'    => '/equipe/index.php',
            ];
            header('Location: ' . BASE_URL . ($dest[$user['tipo']] ?? '/index.php'));
            exit;
        } else {
            $erro = 'Usuário ou senha inválidos.';
        }
    } else {
        $erro = 'Preencha todos os campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login – Varela Rally</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{min-height:100vh;background:#090909;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',Tahoma,sans-serif;position:relative;overflow:hidden}
  .bg-grid{position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:44px 44px;z-index:0}
  .bg-glow{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:800px;height:800px;background:radial-gradient(circle,rgba(160,20,20,.13) 0%,transparent 65%);z-index:0;pointer-events:none}
  .login-wrapper{position:relative;z-index:1;width:100%;max-width:400px;padding:16px;display:flex;flex-direction:column;align-items:center}
  .logo-wrap{margin-bottom:20px;text-align:center}
  .logo-wrap img{width:180px;height:180px;object-fit:contain;filter:drop-shadow(0 0 28px rgba(255,255,255,.1)) drop-shadow(0 0 8px rgba(200,30,30,.25));transition:filter .3s}
  .logo-wrap img:hover{filter:drop-shadow(0 0 36px rgba(255,255,255,.18)) drop-shadow(0 0 12px rgba(200,30,30,.35))}
  .login-card{width:100%;background:#111;border:1px solid #1f1f1f;border-top:2px solid #b91c1c;border-radius:14px;padding:34px 30px 28px;box-shadow:0 20px 60px rgba(0,0,0,.75),0 0 0 1px rgba(255,255,255,.03) inset}
  .card-title{color:#f0f0f0;font-size:1.25rem;font-weight:700;letter-spacing:.3px;margin-bottom:3px}
  .card-sub{color:#444;font-size:.82rem;margin-bottom:26px}
  .lbl{display:block;color:#666;font-size:.74rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;margin-bottom:6px}
  .input-group{margin-bottom:18px}
  .input-group-text{background:#181818;border:1px solid #272727;border-right:none;color:#444;padding:10px 13px}
  .form-control{background:#181818;border:1px solid #272727;border-left:none;color:#ddd;padding:10px 13px;font-size:.92rem;transition:border-color .2s,box-shadow .2s}
  .form-control:focus{background:#181818;border-color:#b91c1c;box-shadow:0 0 0 3px rgba(185,28,28,.18);color:#fff;outline:none}
  .form-control::placeholder{color:#333}
  .btn-login{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;margin-top:6px;background:linear-gradient(135deg,#b91c1c 0%,#7f1d1d 100%);border:none;border-radius:8px;color:#fff;font-size:.88rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;cursor:pointer;transition:all .2s}
  .btn-login:hover{background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);box-shadow:0 6px 22px rgba(185,28,28,.4);transform:translateY(-1px)}
  .btn-login:active{transform:translateY(0)}
  .alerta-erro{background:rgba(185,28,28,.1);border:1px solid rgba(185,28,28,.22);border-radius:8px;color:#f87171;font-size:.83rem;padding:10px 14px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
  .login-foot{margin-top:18px;color:#252525;font-size:.74rem;letter-spacing:.4px;text-align:center}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="bg-glow"></div>
<div class="login-wrapper">
  <div class="logo-wrap">
    <img src="<?= BASE_URL ?>/assets/img/logovarela.png" alt="Varela Rally Brazilian Team">
  </div>
  <div class="login-card">
    <div class="card-title">Varela Rally</div>
    <div class="card-sub">Entre com seu usuário e senha</div>
    <?php if($erro): ?>
    <div class="alerta-erro"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <form method="post">
      <div>
        <label class="lbl">Usuário</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" name="username" class="form-control"
                 placeholder="seu_usuario"
                 value="<?= htmlspecialchars($_POST['username']??'') ?>"
                 required autofocus autocomplete="username">
        </div>
      </div>
      <div>
        <label class="lbl">Senha</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="senha" class="form-control"
                 placeholder="••••••••" required autocomplete="current-password">
        </div>
      </div>
      <button type="submit" class="btn-login">
        <i class="bi bi-box-arrow-in-right"></i> Entrar
      </button>
    </form>
  </div>
  <div class="login-foot">
    <i class="bi bi-shield-lock"></i>
    Acesso restrito &middot; Varela Rally Brazilian Team
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
