<?php
// perfil.php – v9: Meu perfil — contato, dados para pagamento (PIX) e troca de senha. Vale para todos os tipos de login.
require_once 'config.php';
require_once 'includes/auth.php';
requireLogin();
$u = usuarioLogado();
$area = $u['tipo']; $titulo = 'Meu perfil';
$sucesso = $erro = '';

$carregar = function () use ($pdo, $u) { $s = $pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $s->execute([$u['id']]); return $s->fetch(); };
$eu = $carregar();
if (!$eu) { header('Location: /logout.php'); exit; }
$mostraPix = $eu['tipo'] !== 'cliente'; // PIX é para reembolsos e pagamentos da equipe

$tiposPix = ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'email' => 'E-mail', 'telefone' => 'Telefone', 'aleatoria' => 'Chave aleatória'];

function pixNormalizar(string $tipo, string $chave): array {
    $chave = trim($chave);
    switch ($tipo) {
        case 'cpf':  $d = preg_replace('/\D/', '', $chave); return strlen($d) === 11 ? [$d, null] : [null, 'CPF deve ter 11 números.'];
        case 'cnpj': $d = preg_replace('/\D/', '', $chave); return strlen($d) === 14 ? [$d, null] : [null, 'CNPJ deve ter 14 números.'];
        case 'email': $e = strtolower($chave); return filter_var($e, FILTER_VALIDATE_EMAIL) ? [$e, null] : [null, 'E-mail da chave PIX inválido.'];
        case 'telefone':
            $d = preg_replace('/\D/', '', $chave);
            if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
            return (strlen($d) >= 12 && strlen($d) <= 13) ? ['+' . $d, null] : [null, 'Telefone da chave PIX inválido (use DDD + número).'];
        case 'aleatoria':
            $a = strtolower($chave);
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $a) ? [$a, null] : [null, 'Chave aleatória inválida (formato 0000aaaa-00aa-00aa-00aa-000000aaaaaa).'];
    }
    return [null, 'Escolha o tipo da chave PIX.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'dados') {
        $tel = trim($_POST['telefone'] ?? ''); $email = trim($_POST['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $erro = 'E-mail inválido.';
        $pixTipo = null; $pixChave = null; $banco = $eu['dados_bancarios'];
        if (!$erro && $mostraPix) {
            $banco = trim($_POST['dados_bancarios'] ?? '') ?: null;
            if (trim($_POST['pix_chave'] ?? '') !== '') {
                $pixTipo = $_POST['pix_tipo'] ?? '';
                [$pixChave, $e] = pixNormalizar($pixTipo, $_POST['pix_chave']);
                if ($e) $erro = $e;
            }
        } elseif (!$mostraPix) { $pixTipo = $eu['pix_tipo']; $pixChave = $eu['pix_chave']; }
        if (!$erro) {
            try {
                $pdo->prepare("UPDATE usuarios SET telefone=?, email=?, pix_tipo=?, pix_chave=?, dados_bancarios=? WHERE id=?")
                    ->execute([$tel, $email ?: null, $pixChave ? $pixTipo : null, $pixChave, $banco, $u['id']]);
                $_SESSION['usuario_email'] = $email;
                $sucesso = 'Dados salvos!';
            } catch (PDOException $e) { $erro = str_contains($e->getMessage(), 'Duplicate') ? 'Este e-mail já está em uso por outro usuário.' : 'Erro ao salvar: ' . $e->getMessage(); }
        }
    }
    elseif ($acao === 'senha') {
        $atual = $_POST['senha_atual'] ?? ''; $nova = $_POST['senha_nova'] ?? ''; $conf = $_POST['senha_conf'] ?? '';
        if (!password_verify($atual, $eu['senha'])) $erro = 'A senha atual não confere.';
        elseif (mb_strlen($nova) < 6) $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
        elseif ($nova !== $conf) $erro = 'A confirmação não é igual à nova senha.';
        else { $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]); $sucesso = 'Senha alterada! Use a nova senha no próximo login.'; }
    }
    if (!$erro) { $_SESSION['flash_perfil'] = $sucesso; header('Location: perfil.php'); exit; }
}
if (!empty($_SESSION['flash_perfil'])) { $sucesso = $_SESSION['flash_perfil']; unset($_SESSION['flash_perfil']); }
$eu = $carregar();

$labelTipo = ['admin'=>'Administrador','cliente'=>'Cliente','mecanico'=>'Mecânico','navegador'=>'Navegador','estoque'=>'Estoque'];
require_once 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-xl-8 col-lg-10">

<h5 class="fw-bold mb-3"><i class="bi bi-person-circle me-2 text-danger"></i>Meu perfil</h5>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body d-flex align-items-center gap-3">
    <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center fw-bold" style="width:56px;height:56px;font-size:1.2rem"><?= htmlspecialchars(mb_strtoupper(mb_substr($eu['nome'], 0, 1))) ?></div>
    <div><div class="fw-bold fs-5"><?= htmlspecialchars($eu['nome']) ?></div>
      <div class="small text-muted"><?= $labelTipo[$eu['tipo']] ?? $eu['tipo'] ?> · login <code><?= htmlspecialchars($eu['username'] ?? '') ?></code></div></div>
  </div>
</div>

<form method="post" class="card border-0 shadow-sm mb-4">
  <input type="hidden" name="acao" value="dados">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-telephone me-2"></i>Contato</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Telefone / WhatsApp</label><input type="text" name="telefone" class="form-control" value="<?= htmlspecialchars($_POST['telefone'] ?? $eu['telefone'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? $eu['email'] ?? '') ?>"></div>
    </div>
    <div class="form-text mt-2">Nome e login são alterados pelo administrador.</div>
  </div>
  <?php if ($mostraPix): ?>
  <div class="card-header bg-white fw-semibold border-top border-bottom"><i class="bi bi-cash-coin me-2 text-success"></i>Dados para pagamento <span class="text-muted fw-normal small">(reembolsos e pagamentos da equipe)</span></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Tipo da chave PIX</label>
        <select name="pix_tipo" class="form-select"><option value="">— Selecione —</option>
          <?php $pt = $_POST['pix_tipo'] ?? $eu['pix_tipo']; foreach ($tiposPix as $k => $l): ?><option value="<?= $k ?>" <?= $pt === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-md-8"><label class="form-label">Chave PIX</label><input type="text" name="pix_chave" class="form-control" value="<?= htmlspecialchars($_POST['pix_chave'] ?? $eu['pix_chave'] ?? '') ?>" placeholder="Deixe em branco para remover" autocomplete="off"></div>
      <div class="col-12"><label class="form-label">Outros dados bancários <span class="text-muted small">(opcional)</span></label><input type="text" name="dados_bancarios" class="form-control" maxlength="255" value="<?= htmlspecialchars($_POST['dados_bancarios'] ?? $eu['dados_bancarios'] ?? '') ?>" placeholder="Banco, agência, conta, titular"></div>
    </div>
    <div class="form-text mt-2"><i class="bi bi-shield-lock me-1"></i>Só você e o administrador veem estes dados.</div>
  </div>
  <?php endif; ?>
  <div class="card-footer bg-white"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
</form>

<form method="post" class="card border-0 shadow-sm mb-4" autocomplete="off">
  <input type="hidden" name="acao" value="senha">
  <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-key me-2"></i>Trocar senha</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Senha atual</label><input type="password" name="senha_atual" class="form-control" required autocomplete="current-password"></div>
      <div class="col-md-4"><label class="form-label">Nova senha</label><input type="password" name="senha_nova" class="form-control" required minlength="6" autocomplete="new-password"></div>
      <div class="col-md-4"><label class="form-label">Repita a nova senha</label><input type="password" name="senha_conf" class="form-control" required minlength="6" autocomplete="new-password"></div>
    </div>
  </div>
  <div class="card-footer bg-white"><button class="btn btn-outline-danger"><i class="bi bi-key me-1"></i>Alterar senha</button></div>
</form>

</div></div>
<?php require_once 'includes/footer.php'; ?>
