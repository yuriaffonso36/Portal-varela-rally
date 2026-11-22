<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
$u = usuarioLogado();
$nomeCurto = explode(' ', $u['nome'])[0];

$menuCliente = [
    'Dashboard'         => '/cliente/index.php',
    'Ordens de Serviço' => '/cliente/ordens.php',
    'Meus carros'       => '/cliente/carros.php',
    'Corridas'          => '/cliente/corridas.php',
    'Conta Corrente'    => '/cliente/conta.php',
    'Fotos'             => '/cliente/fotos.php',
];
$menuMecanico = [
    'Dashboard'     => '/mecanico/index.php',
    'Minhas Ordens' => '/mecanico/ordens.php',
    'Corridas'      => '/mecanico/corridas.php',
    'Reembolsos'    => '/reembolsos.php',
];
$menuAdmin = [
    'Dashboard'         => '/admin/index.php',
    'Aprovações'        => '/admin/aprovacoes.php',
    'Clientes'          => '/admin/clientes.php',
    'Ordens de Serviço' => '/admin/ordens.php',
    'Corridas'          => '/admin/corridas.php',
    // Fase 3: menus agrupados (abrem uma lista)
    'Financeiro' => [
        'Conta Corrente (clientes)' => '/admin/lancamento.php',
        'Oficina — gastos'          => '/admin/oficina.php',
        'Folha de pagamento'        => '/admin/folha.php',
        'Pagamento de reembolsos'   => '/admin/reembolsos.php',
    ],
    'Pessoas' => [
        'Mecânicos'                 => '/admin/mecanicos.php',
        'Navegadores'               => '/admin/navegadores.php',
        'Equipe (motoristas, apoio)'=> '/admin/equipe.php',
    ],
    'Estoque'           => '/estoque/index.php',
    'Armazenamento'     => '/admin/armazenamento.php',
];
$menuEquipe = [
    'Corridas'   => '/equipe/index.php',
    'Reembolsos' => '/reembolsos.php',
];
$menuEstoque = [
    'Estoque'   => '/estoque/index.php',
    'Mapa'      => '/estoque/mapa.php',
    'Histórico' => '/estoque/itens.php',
    'Reembolsos'=> '/reembolsos.php',
];
$menuNavegador = [
    'Minhas OS' => '/navegador/index.php',
];

$menu = match($area) {
    'cliente'   => $menuCliente,
    'mecanico'  => $menuMecanico,
    'admin'     => $menuAdmin,
    'estoque'   => $menuEstoque,
    'navegador' => $menuNavegador,
    'equipe'    => $menuEquipe,
    default     => $menuAdmin,
};

$corArea = [
    'cliente'   => '#0d6efd',
    'mecanico'  => '#198754',
    'admin'     => '#dc3545',
    'estoque'   => '#6f42c1',
    'navegador' => '#fd7e14',
    'equipe'    => '#20c997',
];
$cor = $corArea[$area] ?? '#dc3545';

$labelArea = [
    'cliente'   => 'Cliente',
    'mecanico'  => 'Mecânico',
    'admin'     => 'Admin',
    'estoque'   => 'Estoque',
    'navegador' => 'Navegador',
    'equipe'    => 'Equipe',
];

$paginaAtual = '/' . ltrim($_SERVER['PHP_SELF'], '/');
// Subpáginas acendem o item de menu correspondente
$menuPai = [
    '/admin/editar_os.php'=>'/admin/ordens.php', '/admin/ver_os.php'=>'/admin/ordens.php',
    '/admin/ver_corrida.php'=>'/admin/corridas.php', '/admin/aprovar_nav_os.php'=>'/admin/navegadores.php',
    '/admin/veiculo.php'=>'/admin/clientes.php',
    '/cliente/ver_os.php'=>'/cliente/ordens.php', '/cliente/ver_corrida.php'=>'/cliente/corridas.php',
    '/cliente/aprovar_nav_os.php'=>'/cliente/ordens.php',
    '/mecanico/ver_os.php'=>'/mecanico/ordens.php', '/mecanico/ver_corrida.php'=>'/mecanico/corridas.php',
    '/equipe/ver_corrida.php'=>'/equipe/index.php',
];
$paginaMenu = $menuPai[$paginaAtual] ?? $paginaAtual;


// Fase 2: contador de pendências no menu do admin
$menuBadge = [];
if ($area === 'admin' && isset($pdo)) {
    try {
        require_once __DIR__ . '/aprovacoes.php';
        $apHdr = aprovContagens($pdo);
        if ($apHdr['total']) $menuBadge['/admin/aprovacoes.php'] = $apHdr['total'];
    } catch (Throwable $e) { /* banco ainda sem a migração da Fase 2 */ }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo??'Varela Rally') ?> – Varela Rally</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
  .navbar-varela{background:#111;border-bottom:2px solid <?= $cor ?>;box-shadow:0 2px 14px rgba(0,0,0,.45);padding:0 16px}
  .navbar-logo{height:46px;width:auto;object-fit:contain;border-radius:6px}
  .navbar-varela .nav-link{color:#aaa !important;font-size:.87rem;font-weight:500;padding:8px 12px !important;border-bottom:2px solid transparent;transition:color .15s,border-color .15s}
  .navbar-varela .nav-link:hover{color:#fff !important}
  .navbar-varela .nav-link.active{color:#fff !important;border-bottom-color:<?= $cor ?>;font-weight:600}
  .badge-area{font-size:.68rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;padding:3px 8px;border-radius:4px;background:<?= $cor ?>;color:#fff}
  .user-name{color:#777;font-size:.83rem}
  .user-name strong{color:#ccc}
  .btn-sair{border:1px solid #2a2a2a;color:#888;font-size:.8rem;padding:5px 12px;border-radius:6px;background:transparent;transition:all .18s;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
  .btn-sair:hover{border-color:<?= $cor ?>;color:#fff;background:rgba(255,255,255,.04)}
  .navbar-toggler{border-color:#333}
  .navbar-toggler-icon{filter:invert(1) brightness(.7)}
  body{background:#f4f6f9}
  .tr-link{cursor:pointer}
  .tr-link:hover td{background:rgba(0,0,0,.03)}
  .card-link{cursor:pointer;transition:transform .15s,box-shadow .15s}
  .card-link:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)!important}
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-varela">
  <div class="container-fluid">
    <a class="navbar-brand p-1" href="<?= BASE_URL . current($menu) ?>">
      <img src="<?= BASE_URL ?>/assets/img/logovarela.png" alt="Varela Rally" class="navbar-logo">
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php foreach($menu as $label=>$href): ?>
        <?php if (is_array($href)): $ativoGrupo = in_array($paginaMenu, $href, true); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $ativoGrupo?'active':'' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= htmlspecialchars($label) ?></a>
          <ul class="dropdown-menu">
            <?php foreach($href as $subLabel=>$subHref): ?>
            <li><a class="dropdown-item <?= $paginaMenu===$subHref?'active':'' ?>" href="<?= BASE_URL.$subHref ?>"><?= htmlspecialchars($subLabel) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
        <?php else: ?>
        <li class="nav-item">
          <a class="nav-link <?= $paginaMenu===$href?'active':'' ?>" href="<?= BASE_URL.$href ?>">
            <?= htmlspecialchars($label) ?><?php if (!empty($menuBadge[$href])): ?> <span class="badge rounded-pill bg-danger"><?= $menuBadge[$href] ?></span><?php endif; ?>
          </a>
        </li>
        <?php endif; ?>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex align-items-center gap-3 py-2 py-lg-0">
        <span class="badge-area"><?= $labelArea[$area]??$area ?></span>
        <a href="<?= BASE_URL ?>/perfil.php" class="user-name text-decoration-none" title="Meu perfil: contato, PIX e senha">Olá, <strong><?= htmlspecialchars($nomeCurto) ?></strong> <i class="bi bi-person-gear ms-1"></i></a>
        <a href="<?= BASE_URL ?>/logout.php" class="btn-sair">
          <i class="bi bi-box-arrow-right"></i> Sair
        </a>
      </div>
    </div>
  </div>
</nav>
<div class="container-fluid py-4 px-4">
<?php if (!empty($_SESSION['flash_limite'])): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['flash_limite']) ?></div><?php unset($_SESSION['flash_limite']); endif; ?>
