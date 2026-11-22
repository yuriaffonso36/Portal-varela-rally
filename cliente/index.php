<?php
// cliente/index.php – Dashboard com cards e linhas clicáveis
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
requireRole('cliente');
$u = usuarioLogado();
$area = 'cliente'; $titulo = 'Dashboard';

$stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='credito' THEN valor ELSE -valor END),0) FROM conta_corrente WHERE cliente_id=?");
$stmt->execute([$u['id']]); $saldo=$stmt->fetchColumn();

$stmt=$pdo->prepare("SELECT COUNT(*) FROM ordens_servico WHERE cliente_id=? AND status NOT IN ('concluida','cancelada')");
$stmt->execute([$u['id']]); $osAbertas=$stmt->fetchColumn();

$navOsPendentes=$pdo->prepare("SELECT n.*,u.nome AS navegador_nome FROM nav_os n JOIN usuarios u ON u.id=n.navegador_id WHERE n.piloto_id=? AND n.status='encerrada' AND n.ok_cliente=0 AND n.cobrar_de='piloto' ORDER BY n.atualizado_em DESC");
$navOsPendentes->execute([$u['id']]); $navOsPendentes=$navOsPendentes->fetchAll();

$stmt=$pdo->prepare("SELECT os.*,v.marca,v.modelo,
    (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id) AS af_total,
    (SELECT COUNT(*) FROM os_afazeres a WHERE a.ordem_servico_id=os.id AND a.concluido=1) AS af_feitos
    FROM ordens_servico os LEFT JOIN veiculos v ON v.id=os.veiculo_id WHERE os.cliente_id=? ORDER BY os.atualizado_em DESC LIMIT 5");
$stmt->execute([$u['id']]); $ultimasOS=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT f.*,os.titulo AS os_titulo FROM fotos f JOIN ordens_servico os ON os.id=f.ordem_servico_id WHERE os.cliente_id=? AND f.aprovada=1 ORDER BY f.criado_em DESC LIMIT 4");
$stmt->execute([$u['id']]); $ultimasFotos=$stmt->fetchAll();

// Carro principal e próxima corrida
$veic=$pdo->prepare("SELECT * FROM veiculos WHERE cliente_id=? ORDER BY (foto_capa IS NULL), id LIMIT 1");$veic->execute([$u['id']]);$veic=$veic->fetch();
$prox=$pdo->prepare("SELECT r.*, p.numero_carro FROM rally_participantes p JOIN rallys r ON r.id=p.rally_id
    WHERE p.cliente_id=? AND r.ativo=1 AND (r.data_fim IS NULL OR r.data_fim>=CURDATE()) ORDER BY r.data_inicio LIMIT 1");
$prox->execute([$u['id']]);$prox=$prox->fetch();
$proxEventos=[];
if($prox){$pe=$pdo->prepare("SELECT * FROM rally_programacao WHERE rally_id=? AND data>=CURDATE() ORDER BY data,hora_inicio LIMIT 3");$pe->execute([$prox['id']]);$proxEventos=$pe->fetchAll();}

$statusLabel=['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
require_once '../includes/header.php';
?>

<?php if($navOsPendentes): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-compass fs-4"></i>
  <div>
    <strong><?= count($navOsPendentes) ?> OS de navegador aguardando sua aprovação!</strong>
    <div class="small mt-1">
      <?php foreach($navOsPendentes as $nos): ?>
      <a href="<?= BASE_URL ?>/cliente/aprovar_nav_os.php?id=<?= $nos['id'] ?>" class="btn btn-warning btn-sm me-2 mt-1">
        <i class="bi bi-eye me-1"></i><?= htmlspecialchars($nos['titulo']) ?> – R$ <?= number_format($nos['valor_total'],2,',','.') ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Cards superiores — inteiros clicáveis -->
<div class="row g-3 mb-4">

  <!-- Saldo → Conta Corrente -->
  <div class="col-6 col-md-3">
    <div class="card card-stat card-link p-3 border-0 shadow-sm h-100"
         onclick="location.href='<?= BASE_URL ?>/cliente/conta.php'">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box" style="background:#e8f4fd"><i class="bi bi-wallet2 text-primary fs-4"></i></div>
        <div>
          <div class="text-muted small">Saldo</div>
          <div class="fw-bold fs-5 <?= $saldo>=0?'saldo-positivo':'saldo-negativo' ?>">
            R$ <?= number_format(abs($saldo),2,',','.') ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- OS em aberto → Ordens de Serviço -->
  <div class="col-6 col-md-3">
    <div class="card card-stat card-link p-3 border-0 shadow-sm h-100"
         onclick="location.href='<?= BASE_URL ?>/cliente/ordens.php'">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box" style="background:#fff3cd"><i class="bi bi-clipboard-check text-warning fs-4"></i></div>
        <div>
          <div class="text-muted small">OS em aberto</div>
          <div class="fw-bold fs-5"><?= $osAbertas ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- OS Navegador → aprovações -->
  <div class="col-6 col-md-3">
    <div class="card card-stat card-link p-3 border-0 shadow-sm h-100"
         onclick="location.href='<?= BASE_URL ?>/cliente/ordens.php'">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box" style="background:#fde8d8"><i class="bi bi-compass text-warning fs-4"></i></div>
        <div>
          <div class="text-muted small">OS Navegador</div>
          <div class="fw-bold fs-5"><?= count($navOsPendentes) ?> pendente(s)</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Fotos → Galeria -->
  <div class="col-6 col-md-3">
    <div class="card card-stat card-link p-3 border-0 shadow-sm h-100"
         onclick="location.href='<?= BASE_URL ?>/cliente/fotos.php'">
      <div class="d-flex align-items-center gap-3">
        <div class="icon-box" style="background:#f8d7da"><i class="bi bi-camera text-danger fs-4"></i></div>
        <div>
          <div class="text-muted small">Fotos</div>
          <div class="fw-bold fs-5 text-dark">Ver Galeria</div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php [$capaT,$capaF]=veiculoCapa($veic?:null); ?>
<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <?php if($veic): ?>
    <div class="carro-capa <?= $capaF?'':'vazia' ?> shadow-sm" style="aspect-ratio:auto;height:250px">
      <?php if($capaF): ?><img src="<?= $capaF ?>"><?php else: ?><i class="bi bi-car-front fs-1 mb-2"></i><a href="<?= BASE_URL ?>/cliente/fotos.php#meu-carro" class="btn btn-sm btn-outline-light">Adicionar foto 1/4 frontal</a><?php endif; ?>
      <div class="info"><div class="fw-bold fs-5"><?= htmlspecialchars($veic['marca'].' '.$veic['modelo']) ?></div><div class="small opacity-75"><?= htmlspecialchars(trim($veic['ano'].' · '.$veic['cor'],' ·')) ?></div></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-6">
    <div class="rally-hero h-100 card-link" onclick="location.href='<?= BASE_URL ?>/cliente/<?= $prox?'ver_corrida.php?id='.$prox['id']:'corridas.php' ?>'">
      <?php if($prox): $dias=$prox['data_inicio']?(int)floor((strtotime($prox['data_inicio'])-strtotime(date('Y-m-d')))/86400):null; ?>
      <div class="rally-status opacity-75 mb-1"><i class="bi bi-flag-fill me-1"></i>Próxima corrida<?= $prox['numero_carro']?' · carro #'.htmlspecialchars($prox['numero_carro']):'' ?></div>
      <div class="fs-4 fw-bold"><?= htmlspecialchars($prox['nome']) ?></div>
      <div class="small opacity-75 mb-3"><?= htmlspecialchars($prox['local']??'') ?><?= $prox['data_inicio']?' · '.date('d/m',strtotime($prox['data_inicio'])).($prox['data_fim']?' a '.date('d/m',strtotime($prox['data_fim'])):''):'' ?></div>
      <?php if($dias!==null&&$dias>=0): ?><div class="contagem"><?= $dias===0?'É hoje!':$dias.' dia'.($dias>1?'s':'') ?></div><?php endif; ?>
      <?php foreach($proxEventos as $ev): ?><div class="small mt-1 opacity-75"><i class="bi bi-clock me-1"></i><?= date('d/m',strtotime($ev['data'])) ?> <?= $ev['hora_inicio']?substr($ev['hora_inicio'],0,5):'' ?> · <?= htmlspecialchars($ev['titulo']) ?></div><?php endforeach; ?>
      <?php else: ?>
      <div class="rally-status opacity-75 mb-1"><i class="bi bi-flag me-1"></i>Corridas</div>
      <div class="fs-5 fw-bold">Nenhuma corrida agendada</div>
      <div class="small opacity-75">Veja o histórico em Corridas.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Últimas OS — linhas clicáveis -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-list me-2 text-primary"></i>Últimas Ordens de Serviço</h6>
        <a href="<?= BASE_URL ?>/cliente/ordens.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
      </div>
      <div class="card-body p-0">
        <?php if($ultimasOS): ?>
        <table class="table table-hover mb-0">
          <thead><tr><th>Nº</th><th>Serviço</th><th>Andamento</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach($ultimasOS as $os): ?>
          <tr class="tr-link" onclick="location.href='<?= BASE_URL ?>/cliente/ver_os.php?id=<?= $os['id'] ?>'">
            <td class="small text-muted"><?= htmlspecialchars($os['numero_os']??$os['numero_cliente']) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($os['titulo']) ?></td>
            <td class="small" style="min-width:110px"><?php if($os['af_total']): $pc=round($os['af_feitos']/$os['af_total']*100); ?><div class="d-flex align-items-center gap-2"><div class="progresso-os flex-fill"><div style="width:<?= $pc ?>%"></div></div><span class="text-muted"><?= $pc ?>%</span></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td><span class="badge badge-<?= $os['status'] ?>"><?= $statusLabel[$os['status']] ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted text-center py-4">Nenhuma OS encontrada.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fotos recentes -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-images me-2 text-danger"></i>Fotos Recentes</h6>
        <a href="<?= BASE_URL ?>/cliente/fotos.php" class="btn btn-sm btn-outline-danger">Ver galeria</a>
      </div>
      <div class="card-body">
        <?php if($ultimasFotos): ?>
        <div class="row g-2">
          <?php foreach($ultimasFotos as $f): ?>
          <div class="col-6 foto-card">
            <img src="<?= fotoThumbUrl($f['thumb']??null,$f['caminho']) ?>"
                 class="foto-thumb w-100"
                 data-src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($f['caminho']) ?>"
                 data-desc="<?= htmlspecialchars($f['descricao']??$f['os_titulo']) ?>">
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-3">Nenhuma foto ainda.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="modal fade" id="modalFoto" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 bg-black">
      <div class="modal-header border-0 bg-black"><h6 class="modal-title text-white" id="modalFotoDesc"></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body p-1 text-center"><img id="modalFotoImg" src="" class="img-fluid" style="max-height:80vh"></div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
