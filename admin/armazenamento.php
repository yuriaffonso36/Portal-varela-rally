<?php
// admin/armazenamento.php – Uso de disco, otimização de fotos e limpeza (v8)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/fotos.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Armazenamento';
$sucesso = $erro = '';
@set_time_limit(120);

// ── Arquivos referenciados no banco ──
function arquivosReferenciados(PDO $pdo): array {
    $ref = [];
    $add = function($p) use (&$ref) { if ($p) $ref[ltrim($p,'/')] = true; };
    $q = function($sql) use ($pdo) { try { return $pdo->query($sql)->fetchAll(PDO::FETCH_NUM); } catch (PDOException $e) { return []; } };
    foreach ($q("SELECT caminho,thumb FROM fotos") as [$a,$b]) { $add($a); $add($b); }
    foreach ($q("SELECT foto FROM estoque WHERE foto IS NOT NULL AND foto<>''") as [$a]) $add('estoque/'.$a);
    foreach ($q("SELECT foto_item FROM os_itens WHERE foto_item IS NOT NULL AND foto_item<>''") as [$a]) $add('estoque/'.$a);
    foreach ($q("SELECT arquivo FROM comprovantes") as [$a]) $add('comprovantes/'.$a);
    foreach ($q("SELECT comprovante_arquivo FROM conta_corrente WHERE comprovante_arquivo IS NOT NULL") as [$a]) $add('comprovantes/'.$a);
    foreach ($q("SELECT comprovante FROM nav_os_itens WHERE comprovante IS NOT NULL") as [$a]) $add('nav_comprovantes/'.$a);
    foreach ($q("SELECT foto_capa,foto_capa_thumb FROM veiculos") as [$a,$b]) { $add($a); $add($b); }
    foreach ($q("SELECT caminho,thumb FROM rally_fotos") as [$a,$b]) { $add($a); $add($b); }
    foreach ($q("SELECT comprovante FROM rally_gastos WHERE comprovante IS NOT NULL") as [$a]) $add($a);
    return $ref;
}
function listarOrfaos(PDO $pdo): array {
    $ref = arquivosReferenciados($pdo);
    $orf = [];
    if (!is_dir(UPLOAD_DIR)) return $orf;
    $base = rtrim(str_replace('\\','/',realpath(UPLOAD_DIR)),'/').'/';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr(str_replace('\\','/',$f->getRealPath()), strlen($base));
        if ($rel === '.htaccess' || str_starts_with($rel,'_orfaos/') || basename($rel) === 'index.html') continue;
        if (isset($ref[$rel])) continue;
        // miniatura cujo original está referenciado
        if (str_contains($rel,'/thumbs/') && isset($ref[str_replace('/thumbs/','/',$rel)])) continue;
        $orf[] = ['rel'=>$rel,'bytes'=>$f->getSize(),'mtime'=>$f->getMTime()];
    }
    return $orf;
}

// ── Otimização em lotes ──
function otimizarLote(PDO $pdo, int $adminId, int $limite = 15): array {
    $feitos = 0; $economia = 0; $msgs = [];
    // 1) Fotos de OS sem miniatura
    $rows = $pdo->query("SELECT id,ordem_servico_id,caminho FROM fotos WHERE thumb IS NULL ORDER BY id LIMIT $limite")->fetchAll();
    foreach ($rows as $r) {
        $orig = UPLOAD_DIR . $r['caminho'];
        if (!is_file($orig)) { $pdo->prepare("UPDATE fotos SET thumb='' WHERE id=?")->execute([$r['id']]); $msgs[] = "Foto #{$r['id']}: arquivo não encontrado"; continue; }
        $antes = filesize($orig);
        $res = fotoSalvarArquivo($orig, 'os/'.$r['ordem_servico_id'], 'os'.$r['ordem_servico_id']);
        if (isset($res['erro'])) { $pdo->prepare("UPDATE fotos SET thumb='' WHERE id=?")->execute([$r['id']]); $msgs[] = "Foto #{$r['id']}: ".$res['erro']; continue; }
        $pdo->prepare("UPDATE fotos SET caminho=?,thumb=?,tamanho=? WHERE id=?")->execute([$res['caminho'],$res['thumb']??'',$res['tamanho'],$r['id']]);
        @unlink($orig);
        $economia += max(0, $antes - $res['tamanho']); $feitos++;
    }
    $limite -= count($rows);
    // 2) Foto antiga gravada direto no item (foto_item) → vira foto da OS vinculada ao item
    if ($limite > 0) {
        $rows = $pdo->query("SELECT id,ordem_servico_id,descricao,foto_item FROM os_itens WHERE foto_item IS NOT NULL AND foto_item<>'' LIMIT $limite")->fetchAll();
        foreach ($rows as $r) {
            $orig = UPLOAD_DIR.'estoque/'.$r['foto_item'];
            $usadaEstoque = $pdo->prepare("SELECT COUNT(*) FROM estoque WHERE foto=?"); $usadaEstoque->execute([$r['foto_item']]); $usadaEstoque = (bool)$usadaEstoque->fetchColumn();
            if (!$usadaEstoque && is_file($orig)) {
                $antes = filesize($orig);
                $res = fotoSalvarArquivo($orig, 'os/'.$r['ordem_servico_id'], 'os'.$r['ordem_servico_id']);
                if (!isset($res['erro'])) {
                    $pdo->prepare("INSERT INTO fotos (ordem_servico_id,caminho,thumb,tamanho,descricao,enviado_por,aprovada) VALUES (?,?,?,?,?,?,1)")
                        ->execute([$r['ordem_servico_id'],$res['caminho'],$res['thumb'],$res['tamanho'],$r['descricao'],$adminId]);
                    $pdo->prepare("INSERT IGNORE INTO os_item_fotos (item_id,foto_id) VALUES (?,?)")->execute([$r['id'],$pdo->lastInsertId()]);
                    @unlink($orig); $economia += max(0,$antes-$res['tamanho']);
                }
            }
            $pdo->prepare("UPDATE os_itens SET foto_item=NULL WHERE id=?")->execute([$r['id']]);
            $feitos++;
        }
        $limite -= count($rows);
    }
    // 3) Fotos do estoque ainda não otimizadas
    if ($limite > 0) {
        $rows = $pdo->query("SELECT id,foto FROM estoque WHERE foto IS NOT NULL AND foto<>'' AND foto NOT LIKE 'est\\_%' LIMIT $limite")->fetchAll();
        foreach ($rows as $r) {
            $orig = UPLOAD_DIR.'estoque/'.$r['foto'];
            if (!is_file($orig)) { $pdo->prepare("UPDATE estoque SET foto=NULL WHERE id=?")->execute([$r['id']]); continue; }
            $antes = filesize($orig);
            $res = fotoSalvarArquivo($orig, 'estoque', 'est');
            if (isset($res['erro'])) { $msgs[] = "Estoque #{$r['id']}: ".$res['erro']; $pdo->prepare("UPDATE estoque SET foto=NULL WHERE id=?")->execute([$r['id']]); continue; }
            $pdo->prepare("UPDATE estoque SET foto=? WHERE id=?")->execute([basename($res['caminho']),$r['id']]);
            $ainda = $pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE foto_item=?"); $ainda->execute([$r['foto']]);
            if (!$ainda->fetchColumn()) @unlink($orig);
            $economia += max(0,$antes-$res['tamanho']); $feitos++;
        }
    }
    return [$feitos, $economia, $msgs];
}
function pendentesOtimizacao(PDO $pdo): int {
    $n = 0;
    try { $n += (int)$pdo->query("SELECT COUNT(*) FROM fotos WHERE thumb IS NULL")->fetchColumn(); } catch (PDOException $e) {}
    try { $n += (int)$pdo->query("SELECT COUNT(*) FROM os_itens WHERE foto_item IS NOT NULL AND foto_item<>''")->fetchColumn(); } catch (PDOException $e) {}
    try { $n += (int)$pdo->query("SELECT COUNT(*) FROM estoque WHERE foto IS NOT NULL AND foto<>'' AND foto NOT LIKE 'est\\_%'")->fetchColumn(); } catch (PDOException $e) {}
    return $n;
}

// ── Ações ──
$rodandoOtimizacao = false; $resultadoLote = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar_cota') {
        $mb = max(100, (int)($_POST['cota_gb'] ?? 20) * 1024);
        configSet($pdo, 'cota_armazenamento_mb', $mb);
        $sucesso = 'Limite de alerta atualizado.';
    } elseif ($acao === 'mover_orfaos') {
        $n = 0; $dest = UPLOAD_DIR.'_orfaos/';
        foreach (listarOrfaos($pdo) as $o) {
            $alvo = $dest.$o['rel']; if (!is_dir(dirname($alvo))) @mkdir(dirname($alvo),0755,true);
            if (@rename(UPLOAD_DIR.$o['rel'], $alvo)) $n++;
        }
        $sucesso = "$n arquivo(s) sem uso movido(s) para uploads/_orfaos. Confira e depois apague a pasta.";
    } elseif ($acao === 'apagar_orfaos') {
        $n = 0; $d = UPLOAD_DIR.'_orfaos';
        if (is_dir($d)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $n++); }
            @rmdir($d);
        }
        $sucesso = "$n arquivo(s) apagado(s) definitivamente.";
    }
}
if (isset($_GET['otimizar'])) {
    $resultadoLote = otimizarLote($pdo, $u['id']);
    $rodandoOtimizacao = pendentesOtimizacao($pdo) > 0 && $resultadoLote[0] > 0;
    $tot = (int)($_GET['tot'] ?? 0) + $resultadoLote[0];
    $eco = (int)($_GET['eco'] ?? 0) + $resultadoLote[1];
    if (!$rodandoOtimizacao) $sucesso = "Otimização concluída: $tot imagem(ns) processada(s), ".bytesFmt($eco)." liberados.";
}

// ── Levantamento ──
$pastasInfo = [
    'os'               => ['Fotos das OS', 'bi-wrench'],
    'estoque'          => ['Estoque / itens', 'bi-box-seam'],
    'veiculos'         => ['Fotos dos carros', 'bi-car-front'],
    'corridas'         => ['Fotos das corridas', 'bi-flag'],
    'comprovantes'     => ['Comprovantes de pagamento', 'bi-receipt'],
    'nav_comprovantes' => ['Comprovantes navegador', 'bi-compass'],
    'corridas_gastos'  => ['Comprovantes de gastos de corrida', 'bi-cash-coin'],
    '_orfaos'          => ['Sem uso (aguardando exclusão)', 'bi-trash3'],
];
$uso = []; $totalBytes = 0; $totalArq = 0;
foreach ($pastasInfo as $p => $_) { [$b,$q] = pastaUso(UPLOAD_DIR.$p); $uso[$p] = [$b,$q]; $totalBytes += $b; $totalArq += $q; }
// Arquivos soltos na raiz de uploads (padrão antigo)
$raizB = 0; $raizQ = 0;
if (is_dir(UPLOAD_DIR)) foreach (new DirectoryIterator(UPLOAD_DIR) as $f) { if ($f->isFile() && $f->getFilename()!=='.htaccess') { $raizB += $f->getSize(); $raizQ++; } }
$uso['_raiz'] = [$raizB,$raizQ]; $pastasInfo['_raiz'] = ['Fotos antigas soltas na raiz','bi-folder2-open'];
$totalBytes += $raizB; $totalArq += $raizQ;

$cotaMb = (int)configGet($pdo, 'cota_armazenamento_mb', 20480);
$cotaBytes = $cotaMb * 1048576;
$pctUso = $cotaBytes ? min(100, $totalBytes / $cotaBytes * 100) : 0;
$livreServidor = @disk_free_space(UPLOAD_DIR);

$nFotos = 0; $mediaFoto = 0;
try { $r = $pdo->query("SELECT COUNT(*), AVG(tamanho) FROM fotos WHERE tamanho IS NOT NULL")->fetch(PDO::FETCH_NUM); $nFotos=(int)$r[0]; $mediaFoto=(float)$r[1]; } catch (PDOException $e) {}
$crescimento = [];
try { $crescimento = $pdo->query("SELECT DATE_FORMAT(criado_em,'%Y-%m') m, COUNT(*) q, COALESCE(SUM(tamanho),0) b FROM fotos GROUP BY m ORDER BY m DESC LIMIT 6")->fetchAll(); } catch (PDOException $e) {}
$mesesAteCota = null;
if ($crescimento) { $mediaMes = array_sum(array_column($crescimento,'b')) / count($crescimento); if ($mediaMes > 0) $mesesAteCota = max(0, ($cotaBytes - $totalBytes) / $mediaMes); }

$pendOtim = pendentesOtimizacao($pdo);
$orfaos = listarOrfaos($pdo);
$orfaosBytes = array_sum(array_column($orfaos,'bytes'));

$limites = [
    'Upload máx. por arquivo' => ini_get('upload_max_filesize'),
    'Tamanho máx. do envio'   => ini_get('post_max_size'),
    'Arquivos por envio'      => ini_get('max_file_uploads'),
    'Biblioteca de imagem (GD)' => fotosGdDisponivel() ? 'Disponível' : 'Indisponível — fotos gravadas sem redução',
    'Correção de rotação (EXIF)' => function_exists('exif_read_data') ? 'Disponível' : 'Indisponível',
];

require_once '../includes/header.php';
?>
<?php if ($rodandoOtimizacao): ?>
<script>setTimeout(()=>{location.href='armazenamento.php?otimizar=1&tot=<?= $tot ?>&eco=<?= $eco ?>';},600);</script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="bi bi-hdd-stack me-2 text-danger"></i>Armazenamento</h5>
  <span class="text-muted small"><?= number_format($totalArq,0,',','.') ?> arquivos em <code>uploads/</code></span>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if($rodandoOtimizacao): ?>
<div class="alert alert-info d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm"></span>
  Otimizando… <?= $tot ?> processada(s), <?= bytesFmt($eco) ?> liberados até agora. Faltam <?= $pendOtim ?>. Não feche a página.</div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-end mb-2">
          <div><div class="text-muted small">Espaço usado pelo portal</div>
            <div class="fs-2 fw-bold"><?= bytesFmt($totalBytes) ?> <span class="fs-6 text-muted fw-normal">de <?= bytesFmt($cotaBytes) ?> (limite de alerta)</span></div></div>
          <div class="fs-4 fw-bold <?= $pctUso>85?'text-danger':($pctUso>60?'text-warning':'text-success') ?>"><?= number_format($pctUso,1,',','.') ?>%</div>
        </div>
        <div class="progress mb-3" style="height:12px"><div class="progress-bar <?= $pctUso>85?'bg-danger':($pctUso>60?'bg-warning':'bg-success') ?>" style="width:<?= max(1,$pctUso) ?>%"></div></div>
        <div class="row g-3 small">
          <div class="col-sm-4"><div class="text-muted">Fotos de OS otimizadas</div><div class="fw-semibold"><?= $nFotos ?> · média <?= bytesFmt($mediaFoto) ?></div></div>
          <div class="col-sm-4"><div class="text-muted">Previsão até o limite</div><div class="fw-semibold"><?= $mesesAteCota===null?'—':($mesesAteCota>120?'mais de 10 anos':number_format($mesesAteCota,0,',','.').' meses') ?></div></div>
          <div class="col-sm-4"><div class="text-muted">Livre no servidor (informado)</div><div class="fw-semibold"><?= $livreServidor!==false?bytesFmt($livreServidor):'não informado' ?></div></div>
        </div>
        <form method="post" class="d-flex gap-2 align-items-end mt-3">
          <input type="hidden" name="acao" value="salvar_cota">
          <div><label class="form-label small mb-1">Limite de alerta (GB)</label>
            <input type="number" name="cota_gb" min="1" class="form-control form-control-sm" style="width:110px" value="<?= round($cotaMb/1024) ?>"></div>
          <button class="btn btn-outline-secondary btn-sm">Salvar</button>
        </form>
        <div class="form-text">A hospedagem da Locaweb anuncia espaço ilimitado, mas sujeito a uso justo, e o backup tem limite próprio. Use este limite como alerta: o dashboard avisa ao passar de 85%.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-magic me-2 text-primary"></i>Otimizar fotos antigas</div>
      <div class="card-body small">
        <p class="mb-2">Reduz fotos enviadas antes da v8 (até 1920px, JPEG), cria miniaturas e organiza tudo em <code>uploads/os/&lt;OS&gt;/</code>. Fotos antigas gravadas direto no item viram fotos da OS ligadas ao item.</p>
        <div class="d-flex align-items-center justify-content-between">
          <span><strong class="fs-5"><?= $pendOtim ?></strong> imagem(ns) para otimizar</span>
          <a href="armazenamento.php?otimizar=1" class="btn btn-primary btn-sm <?= $pendOtim?'':'disabled' ?>"><i class="bi bi-play-fill me-1"></i>Otimizar agora</a>
        </div>
        <?php if($resultadoLote && $resultadoLote[2]): ?>
        <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:.75rem"><?= implode('<br>',array_map('htmlspecialchars',array_slice($resultadoLote[2],0,5))) ?></div>
        <?php endif; ?>
        <hr>
        <div class="fw-semibold mb-1">Limites do servidor</div>
        <table class="table table-sm mb-0">
          <?php foreach($limites as $k=>$v): ?><tr><td class="text-muted"><?= $k ?></td><td class="text-end fw-semibold"><?= htmlspecialchars((string)$v) ?></td></tr><?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom">Uso por pasta</div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle small">
          <thead><tr><th>Pasta</th><th class="text-end">Arquivos</th><th class="text-end">Tamanho</th><th style="width:30%"></th></tr></thead>
          <tbody>
          <?php $maxB = max(1, max(array_column($uso,0)));
          foreach($pastasInfo as $p=>[$lbl,$ic]): [$b,$q]=$uso[$p]; if(!$q && in_array($p,['_orfaos','_raiz'])) continue; ?>
          <tr>
            <td><i class="bi <?= $ic ?> me-2 text-muted"></i><?= $lbl ?> <code class="ms-1 text-muted" style="font-size:.7rem"><?= $p==='_raiz'?'uploads/':'uploads/'.$p ?></code></td>
            <td class="text-end"><?= number_format($q,0,',','.') ?></td>
            <td class="text-end fw-semibold"><?= bytesFmt($b) ?></td>
            <td><div class="progress" style="height:6px"><div class="progress-bar bg-secondary" style="width:<?= $b/$maxB*100 ?>%"></div></div></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if($crescimento): ?>
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-semibold border-bottom">Fotos de OS por mês</div>
      <div class="card-body p-0"><table class="table table-sm mb-0 small">
        <thead><tr><th>Mês</th><th class="text-end">Fotos</th><th class="text-end">Espaço</th></tr></thead>
        <?php foreach($crescimento as $c): ?><tr><td><?= date('m/Y',strtotime($c['m'].'-01')) ?></td><td class="text-end"><?= $c['q'] ?></td><td class="text-end"><?= bytesFmt($c['b']) ?></td></tr><?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between">
        <span><i class="bi bi-trash3 me-2 text-danger"></i>Arquivos sem uso</span>
        <span class="badge bg-secondary"><?= count($orfaos) ?> · <?= bytesFmt($orfaosBytes) ?></span>
      </div>
      <div class="card-body small">
        <p class="text-muted">Arquivos em <code>uploads/</code> que nenhum registro do banco usa (fotos de itens excluídos, comprovantes rejeitados, etc.).</p>
        <?php if($orfaos): ?>
        <ul class="list-unstyled mb-3" style="max-height:180px;overflow:auto">
          <?php foreach(array_slice($orfaos,0,40) as $o): ?><li class="text-truncate"><code style="font-size:.7rem"><?= htmlspecialchars($o['rel']) ?></code> <span class="text-muted">· <?= bytesFmt($o['bytes']) ?></span></li><?php endforeach; ?>
        </ul>
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="mover_orfaos">
          <button class="btn btn-outline-warning btn-sm" data-confirm="Mover os arquivos sem uso para uploads/_orfaos?"><i class="bi bi-folder-symlink me-1"></i>Mover para _orfaos</button></form>
        <?php else: ?><div class="text-success"><i class="bi bi-check-circle me-1"></i>Nenhum arquivo sem uso.</div><?php endif; ?>
        <?php if($uso['_orfaos'][1]): ?>
        <form method="post" class="d-inline ms-1"><input type="hidden" name="acao" value="apagar_orfaos">
          <button class="btn btn-danger btn-sm" data-confirm="Apagar DEFINITIVAMENTE <?= $uso['_orfaos'][1] ?> arquivo(s) de uploads/_orfaos?"><i class="bi bi-trash3 me-1"></i>Esvaziar _orfaos (<?= bytesFmt($uso['_orfaos'][0]) ?>)</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
