<?php
// admin/equipe.php – Fase 3: pessoas da equipe (motoristas, apoio, etc.) — com login ou só para folha/diárias
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/financeiro.php';
requireRole('admin');
$u = usuarioLogado(); $area = 'admin'; $titulo = 'Equipe';
$tiposPix = ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'email' => 'E-mail', 'telefone' => 'Telefone', 'aleatoria' => 'Chave aleatória'];
$funcoes = ['Motorista', 'Apoio', 'Mecânico de corrida', 'Logística', 'Cozinha', 'Fotógrafo / mídia', 'Administrativo'];

function eqUsername(string $v): ?string { $v = strtolower(trim($v)); return preg_match('/^[a-z0-9._-]{3,50}$/', $v) ? $v : null; }
function eqPix(?string $tipo, string $chave): ?string {
    $chave = trim($chave); if ($chave === '') return null;
    if (in_array($tipo, ['cpf', 'cnpj'], true)) return preg_replace('/\D/', '', $chave);
    if ($tipo === 'telefone') { $d = preg_replace('/\D/', '', $chave); return '+' . (strlen($d) <= 11 ? '55' . $d : $d); }
    return $tipo === 'email' || $tipo === 'aleatoria' ? strtolower($chave) : $chave;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? ''; $id = (int)($_POST['id'] ?? 0); $msg = ''; $err = '';
    $nome = trim($_POST['nome'] ?? ''); $funcao = trim($_POST['funcao'] ?? '') ?: null; $tel = trim($_POST['telefone'] ?? '');
    $semAcesso = !empty($_POST['sem_acesso']); $username = eqUsername($_POST['username'] ?? ''); $senha = trim($_POST['senha'] ?? '');
    $pixTipo = isset($tiposPix[$_POST['pix_tipo'] ?? '']) ? $_POST['pix_tipo'] : null; $pixChave = eqPix($pixTipo, $_POST['pix_chave'] ?? '');
    $banco = trim($_POST['dados_bancarios'] ?? '') ?: null;

    if ($acao === 'novo' || $acao === 'editar') {
        if (!$nome) $err = 'Informe o nome.';
        elseif (!$semAcesso && !$username) $err = 'Informe o usuário (login): 3 a 50 caracteres, letras minúsculas, números, ponto, hífen ou _. Ou marque "sem acesso ao sistema".';
        elseif (!$semAcesso && $acao === 'novo' && mb_strlen($senha) < 6) $err = 'A senha precisa ter pelo menos 6 caracteres.';
        if (!$err && !$semAcesso) {
            $d = $pdo->prepare("SELECT nome FROM usuarios WHERE username=? AND id<>?"); $d->execute([$username, $id]);
            if ($o = $d->fetchColumn()) $err = "O usuário '$username' já está em uso por $o.";
        }
    }
    if (!$err) switch ($acao) {
    case 'novo':
        $hash = password_hash($semAcesso ? bin2hex(random_bytes(16)) : $senha, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO usuarios (nome,username,senha,tipo,telefone,funcao,sem_acesso,pix_tipo,pix_chave,dados_bancarios) VALUES (?,?,?,'equipe',?,?,?,?,?,?)")
            ->execute([$nome, $semAcesso ? null : $username, $hash, $tel, $funcao, $semAcesso ? 1 : 0, $pixChave ? $pixTipo : null, $pixChave, $banco]);
        $msg = "$nome cadastrado(a)!" . ($semAcesso ? ' (sem acesso ao sistema)' : " Login: $username"); break;
    case 'editar':
        $atual = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND tipo='equipe'"); $atual->execute([$id]); $atual = $atual->fetch();
        if (!$atual) break;
        if (!$semAcesso && $atual['sem_acesso'] && mb_strlen($senha) < 6) { $err = 'Para dar acesso ao sistema, defina uma senha (mínimo 6 caracteres).'; break; }
        $pdo->prepare("UPDATE usuarios SET nome=?,username=?,telefone=?,funcao=?,sem_acesso=?,pix_tipo=?,pix_chave=?,dados_bancarios=?,ativo=? WHERE id=? AND tipo='equipe'")
            ->execute([$nome, $semAcesso ? null : $username, $tel, $funcao, $semAcesso ? 1 : 0, $pixChave ? $pixTipo : null, $pixChave, $banco, (int)($_POST['ativo'] ?? 1), $id]);
        if ($senha && !$semAcesso) $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        $msg = 'Dados atualizados!'; break;
    case 'ativo':
        $pdo->prepare("UPDATE usuarios SET ativo=1-ativo WHERE id=? AND tipo='equipe'")->execute([$id]); $msg = 'Situação atualizada.'; break;
    }
    $_SESSION['flash_eq'] = [$msg, $err];
    if ($err && $acao === 'editar') { header('Location: equipe.php?editar=' . $id); exit; }
    if ($err) $_SESSION['eq_form'] = $_POST;
    header('Location: equipe.php'); exit;
}
[$sucesso, $erro] = $_SESSION['flash_eq'] ?? ['', '']; unset($_SESSION['flash_eq']);
$form = $_SESSION['eq_form'] ?? []; unset($_SESSION['eq_form']);

$equipe = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM rally_equipe re WHERE re.usuario_id=u.id) AS n_corridas FROM usuarios u WHERE u.tipo='equipe' ORDER BY u.ativo DESC, u.nome")->fetchAll();
$editando = null;
if (!empty($_GET['editar'])) { $e = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND tipo='equipe'"); $e->execute([(int)$_GET['editar']]); $editando = $e->fetch() ?: null; }

$campos = function (array $v, bool $novo) use ($tiposPix, $funcoes) {
    $sa = !empty($v['sem_acesso']); ob_start(); ?>
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label small mb-0">Nome *</label><input type="text" name="nome" class="form-control form-control-sm" required value="<?= htmlspecialchars($v['nome'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Função</label><input type="text" name="funcao" class="form-control form-control-sm" list="listaFuncoes" value="<?= htmlspecialchars($v['funcao'] ?? '') ?>" placeholder="Motorista, apoio..."></div>
      <div class="col-md-3"><label class="form-label small mb-0">Telefone</label><input type="text" name="telefone" class="form-control form-control-sm" value="<?= htmlspecialchars($v['telefone'] ?? '') ?>"></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input js-sem-acesso" type="checkbox" name="sem_acesso" value="1" id="sa<?= $novo ? 'N' : 'E' ?>" <?= $sa ? 'checked' : '' ?>>
        <label class="form-check-label small" for="sa<?= $novo ? 'N' : 'E' ?>">Sem acesso ao sistema (só para folha e diárias)</label></div></div>
      <div class="col-md-4 js-acesso <?= $sa ? 'd-none' : '' ?>"><label class="form-label small mb-0">Usuário (login)</label><input type="text" name="username" class="form-control form-control-sm" value="<?= htmlspecialchars($v['username'] ?? '') ?>" style="text-transform:lowercase" autocomplete="off"></div>
      <div class="col-md-4 js-acesso <?= $sa ? 'd-none' : '' ?>"><label class="form-label small mb-0"><?= $novo ? 'Senha' : 'Nova senha' ?></label><input type="text" name="senha" class="form-control form-control-sm" autocomplete="off" placeholder="<?= $novo ? 'mín. 6 caracteres' : 'deixe em branco para manter' ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Tipo PIX</label><select name="pix_tipo" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach ($tiposPix as $k => $l): ?><option value="<?= $k ?>" <?= ($v['pix_tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label small mb-0">Chave PIX</label><input type="text" name="pix_chave" class="form-control form-control-sm" value="<?= htmlspecialchars($v['pix_chave'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0">Outros dados bancários</label><input type="text" name="dados_bancarios" class="form-control form-control-sm" value="<?= htmlspecialchars($v['dados_bancarios'] ?? '') ?>"></div>
    </div>
    <?php return ob_get_clean();
};

require_once '../includes/header.php';
?>
<datalist id="listaFuncoes"><?php foreach ($funcoes as $f): ?><option value="<?= htmlspecialchars($f) ?>"><?php endforeach; ?></datalist>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-person-badge me-2 text-danger"></i>Equipe <span class="text-muted fw-normal fs-6">motoristas, apoio e outros</span></h5>
  <div class="d-flex gap-2"><a href="folha.php?aba=pessoas" class="btn btn-sm btn-outline-dark"><i class="bi bi-cash-stack me-1"></i>Valores da folha</a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formNovo"><i class="bi bi-person-plus me-1"></i>Nova pessoa</button></div>
</div>
<?php if ($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="collapse mb-4 <?= $form ? 'show' : '' ?>" id="formNovo">
  <div class="card border-0 shadow-sm"><div class="card-body">
    <form method="post"><input type="hidden" name="acao" value="novo"><?= $campos($form, true) ?>
      <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save me-1"></i>Cadastrar</button></form>
    <div class="small text-muted mt-2">Com login, a pessoa entra no portal e vê as corridas em que foi escalada, lança reembolsos e cadastra o próprio PIX.</div>
  </div></div>
</div>

<?php if ($editando): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-primary border-3">
  <div class="card-header bg-white d-flex justify-content-between"><span class="fw-semibold">Editar: <?= htmlspecialchars($editando['nome']) ?></span><a href="equipe.php" class="btn-close"></a></div>
  <div class="card-body">
    <form method="post"><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="<?= $editando['id'] ?>"><input type="hidden" name="ativo" value="<?= (int)$editando['ativo'] ?>">
      <?= $campos($editando, false) ?>
      <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save me-1"></i>Salvar</button></form>
  </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <?php if ($equipe): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Nome</th><th>Função</th><th>Login</th><th>Telefone</th><th class="text-center">Corridas</th><th>Folha</th><th>PIX</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($equipe as $p): ?>
    <tr class="<?= $p['ativo'] ? '' : 'text-muted' ?>">
      <td class="fw-semibold"><a href="?editar=<?= $p['id'] ?>" class="text-decoration-none text-reset"><?= htmlspecialchars($p['nome']) ?></a><?= $p['ativo'] ? '' : ' <span class="badge bg-secondary">inativo</span>' ?></td>
      <td class="small"><?= htmlspecialchars($p['funcao'] ?? '—') ?></td>
      <td class="small"><?= $p['sem_acesso'] ? '<span class="badge bg-light text-dark border">sem acesso</span>' : '<code>' . htmlspecialchars($p['username'] ?? '') . '</code>' ?></td>
      <td class="small"><?= htmlspecialchars($p['telefone'] ?: '—') ?></td>
      <td class="text-center"><span class="badge bg-secondary"><?= (int)$p['n_corridas'] ?></span></td>
      <td class="small"><?= $p['na_folha'] ? finR($p['salario_mensal']) . '/mês' : '' ?><?= $p['valor_diaria'] ? ($p['na_folha'] ? ' · ' : '') . finR($p['valor_diaria']) . '/dia' : '' ?><?= !$p['na_folha'] && !$p['valor_diaria'] ? '—' : '' ?></td>
      <td><?= $p['pix_chave'] ? '<i class="bi bi-check-circle text-success"></i>' : '<span class="small text-warning">sem</span>' ?></td>
      <td class="text-end text-nowrap"><a href="?editar=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-pencil"></i></a>
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm <?= $p['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?> py-0" data-confirm="<?= $p['ativo'] ? 'Desativar' : 'Ativar' ?> <?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>?"><?= $p['ativo'] ? 'Desativar' : 'Ativar' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="text-muted text-center py-4 mb-0">Nenhuma pessoa da equipe cadastrada ainda.</p><?php endif; ?>
</div>
<div class="small text-muted mt-2">Para escalar alguém numa corrida: Corridas → abrir a corrida → aba "Equipe & carros".</div>

<script>
document.querySelectorAll('.js-sem-acesso').forEach(cb => cb.addEventListener('change', () => {
  cb.closest('form').querySelectorAll('.js-acesso').forEach(el => el.classList.toggle('d-none', cb.checked));
}));
</script>
<?php require_once '../includes/footer.php'; ?>
