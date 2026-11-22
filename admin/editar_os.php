<?php
// admin/editar_os.php – v9: + Local/Rally e km de entrada (v8: afazeres, itens com valor, foto em todos os itens)
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/os_helpers.php';
require_once '../includes/veiculos.php';
require_once '../includes/aprovacoes.php';
requireRole('admin');
$u = usuarioLogado();
$area = 'admin'; $titulo = 'Editar OS';

$osId=(int)($_GET['id']??0);
if(!$osId){header('Location: ordens.php');exit;}

$stmtOS=$pdo->prepare("SELECT os.*,uc.nome AS cliente_nome,v.marca,v.modelo,v.placa,v.foto_capa,v.foto_capa_thumb,v.km_atual,r.nome AS rally_nome FROM ordens_servico os JOIN usuarios uc ON uc.id=os.cliente_id LEFT JOIN veiculos v ON v.id=os.veiculo_id LEFT JOIN rallys r ON r.id=os.rally_id WHERE os.id=?");
$stmtOS->execute([$osId]);$os=$stmtOS->fetch();
if(!$os){header('Location: ordens.php');exit;}

$sucesso=$erro=''; $pedirConfKm=false;
$num = fn($v)=>(float)str_replace(['.',','],['','.'],$v??'0');

function recalcTotal($pdo,$osId){
    $sv=$pdo->prepare("SELECT COALESCE(SUM(valor*quantidade),0) FROM os_itens WHERE ordem_servico_id=? AND valor IS NOT NULL AND status_aprovacao='aprovado'");
    $sv->execute([$osId]);
    $pdo->prepare("UPDATE ordens_servico SET valor_total=? WHERE id=?")->execute([$sv->fetchColumn(),$osId]);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $acao=$_POST['acao']??'';

    // ───── AFAZERES ─────
    if($acao==='add_afazer'){
        $linhas=array_filter(array_map('trim',preg_split('/\r?\n/',$_POST['descricao']??'')));
        $mx=$pdo->prepare("SELECT COALESCE(MAX(ordem),0) FROM os_afazeres WHERE ordem_servico_id=?");$mx->execute([$osId]);$ord=(int)$mx->fetchColumn();
        foreach($linhas as $l){ $ord+=10;
            $pdo->prepare("INSERT INTO os_afazeres (ordem_servico_id,descricao,ordem,criado_por) VALUES (?,?,?,?)")->execute([$osId,mb_substr($l,0,255),$ord,$u['id']]); }
        $sucesso=count($linhas)>1?count($linhas).' afazeres adicionados!':'Afazer adicionado!';
    }
    elseif($acao==='edit_afazer'){
        $pdo->prepare("UPDATE os_afazeres SET descricao=?,observacao=? WHERE id=? AND ordem_servico_id=?")
            ->execute([trim($_POST['descricao']??''),trim($_POST['observacao']??'')?:null,(int)$_POST['afazer_id'],$osId]);
        $sucesso='Afazer atualizado!';
    }
    elseif($acao==='toggle_afazer'){
        $aid=(int)$_POST['afazer_id'];
        $pdo->prepare("UPDATE os_afazeres SET concluido=1-concluido, concluido_por=IF(concluido=1,?,NULL), concluido_em=IF(concluido=1,NOW(),NULL) WHERE id=? AND ordem_servico_id=?")->execute([$u['id'],$aid,$osId]);
    }
    elseif($acao==='del_afazer'){
        $aid=(int)$_POST['afazer_id'];
        $pdo->prepare("UPDATE fotos SET afazer_id=NULL WHERE afazer_id=? AND ordem_servico_id=?")->execute([$aid,$osId]);
        $pdo->prepare("DELETE FROM os_afazeres WHERE id=? AND ordem_servico_id=?")->execute([$aid,$osId]);
        $sucesso='Afazer removido.';
    }
    elseif($acao==='mover_afazer'){
        $lista=osAfazeres($pdo,$osId); $ids=array_column($lista,'id'); $aid=(int)$_POST['afazer_id'];
        $i=array_search($aid,$ids); $j=$i+((($_POST['dir']??'')==='up')?-1:1);
        if($i!==false&&isset($ids[$j])){ [$ids[$i],$ids[$j]]=[$ids[$j],$ids[$i]];
            foreach($ids as $k=>$id) $pdo->prepare("UPDATE os_afazeres SET ordem=? WHERE id=?")->execute([($k+1)*10,$id]); }
    }

    // ───── ITENS COM VALOR ─────
    elseif($acao==='add_item'){
        $desc=trim($_POST['descricao']??''); $valor=$num($_POST['valor']??''); $vcusto=$num($_POST['valor_custo']??'');
        $qtd=max(1,(int)($_POST['quantidade']??1)); $catId=(int)($_POST['categoria_id']??0)?:null; $estoqueId=(int)($_POST['estoque_id']??0)?:null;
        if(!$desc){$erro='Informe a descrição.';}
        else{
            if($estoqueId){
                $pdo->prepare("UPDATE estoque SET quantidade=quantidade-? WHERE id=? AND quantidade>=?")->execute([$qtd,$estoqueId,$qtd]);
                $pdo->prepare("INSERT INTO estoque_saidas (estoque_id,quantidade,ordem_servico_id,cliente_id,usuario_id) VALUES (?,?,?,?,?)")->execute([$estoqueId,$qtd,$osId,$os['cliente_id'],$u['id']]);
                if(!$vcusto){$ec=$pdo->prepare("SELECT valor_custo FROM estoque WHERE id=?");$ec->execute([$estoqueId]);$vcusto=(float)$ec->fetchColumn();}
            }
            $pdo->prepare("INSERT INTO os_itens (ordem_servico_id,descricao,categoria_id,valor,valor_custo,quantidade,estoque_id,origem) VALUES (?,?,?,?,?,?,?,'admin')")
                ->execute([$osId,$desc,$catId,$valor>0?$valor:null,$vcusto>0?$vcusto:null,$qtd,$estoqueId]);
            $itemId=(int)$pdo->lastInsertId();
            [$nf,$errs]=osSalvarFotos($pdo,$osId,$_FILES['fotos_item']??[],$u['id'],true,$desc,$itemId);
            recalcTotal($pdo,$osId);
            $sucesso='Item adicionado!'.($nf?" ($nf foto(s))":''); if($errs)$erro=implode(' ',$errs);
        }
    }
    elseif($acao==='editar_item'){
        $itemId=(int)($_POST['item_id']??0); $desc=trim($_POST['descricao']??'');
        $valor=$num($_POST['valor']??''); $vcusto=$num($_POST['valor_custo']??''); $qtd=max(1,(int)($_POST['quantidade']??1)); $catId=(int)($_POST['categoria_id']??0)?:null;
        $ia=$pdo->prepare("SELECT estoque_id,quantidade FROM os_itens WHERE id=? AND ordem_servico_id=?");$ia->execute([$itemId,$osId]);$ia=$ia->fetch();
        if($ia&&$ia['estoque_id']&&$ia['quantidade']!=$qtd){
            $pdo->prepare("UPDATE estoque SET quantidade=GREATEST(0,quantidade-?) WHERE id=?")->execute([$qtd-$ia['quantidade'],$ia['estoque_id']]);
        }
        $pdo->prepare("UPDATE os_itens SET descricao=?,categoria_id=?,valor=?,valor_custo=?,quantidade=? WHERE id=? AND ordem_servico_id=?")
            ->execute([$desc,$catId,$valor>0?$valor:null,$vcusto>0?$vcusto:null,$qtd,$itemId,$osId]);
        recalcTotal($pdo,$osId); $sucesso='Item atualizado!';
    }
    elseif($acao==='item_aprovar'){
        $e=aprovItemAprovar($pdo,(int)($_POST['item_id']??0),$u['id'],aprovNum($_POST['valor']??''),aprovNum($_POST['valor_custo']??''));
        $e?$erro=$e:$sucesso='Item aprovado!';
    }
    elseif($acao==='item_recusar'){
        $e=aprovItemRecusar($pdo,(int)($_POST['item_id']??0),$u['id'],$_POST['motivo']??'');
        $e?$erro=$e:$sucesso='Item recusado.';
    }
    elseif($acao==='set_valor'){
        $itemId=(int)($_POST['item_id']??0); $valor=$num($_POST['valor']??''); $vcusto=$num($_POST['valor_custo']??'');
        $pdo->prepare("UPDATE os_itens SET valor=?,valor_custo=? WHERE id=? AND ordem_servico_id=?")->execute([$valor>0?$valor:null,$vcusto>0?$vcusto:null,$itemId,$osId]);
        recalcTotal($pdo,$osId); $sucesso='Valores definidos!';
    }
    elseif($acao==='del_item'){
        $itemId=(int)($_POST['item_id']??0);
        $d=$pdo->prepare("SELECT estoque_id,quantidade FROM os_itens WHERE id=? AND ordem_servico_id=?");$d->execute([$itemId,$osId]);$d=$d->fetch();
        if($d&&$d['estoque_id'])$pdo->prepare("UPDATE estoque SET quantidade=quantidade+? WHERE id=?")->execute([$d['quantidade'],$d['estoque_id']]);
        $pdo->prepare("DELETE FROM os_item_fotos WHERE item_id=?")->execute([$itemId]);
        $pdo->prepare("DELETE FROM os_itens WHERE id=? AND ordem_servico_id=?")->execute([$itemId,$osId]);
        recalcTotal($pdo,$osId); $sucesso='Item removido. As fotos continuam na OS.';
    }
    elseif($acao==='salvar_os'){
        [$local,$rallyId]=osLocalDoPost($_POST['local_rally']??'oficina');
        $veiculoId=(int)($_POST['veiculo_id']??0)?:null;
        if($veiculoId){$c=$pdo->prepare("SELECT COUNT(*) FROM veiculos WHERE id=? AND cliente_id=?");$c->execute([$veiculoId,$os['cliente_id']]);if(!$c->fetchColumn())$veiculoId=null;}
        $pdo->prepare("UPDATE ordens_servico SET titulo=?,status=?,mecanico_id=?,veiculo_id=?,rally_id=?,local=? WHERE id=?")
            ->execute([trim($_POST['titulo']??''),$_POST['status']??$os['status'],(int)($_POST['mecanico_id']??0)?:null,$veiculoId,$rallyId,$local,$osId]);
        // Trocou de carro: a leitura de km sai do carro antigo
        if((int)$os['veiculo_id']!==(int)$veiculoId){
            veiculoDesvincularKmOS($pdo,$osId,$veiculoId);
            if(!$veiculoId) $pdo->prepare("UPDATE ordens_servico SET km_entrada=NULL WHERE id=?")->execute([$osId]);
        }
        $km=kmParse($_POST['km_entrada']??'');
        if($km!==null && $veiculoId){
            $r=veiculoRegistrarKm($pdo,$veiculoId,$km,$osId,$u['id'],'os',!empty($_POST['confirmar_menor']));
            if(!$r['ok']){$erro=$r['aviso'];$pedirConfKm=$r['pedir_confirmacao'];}
        }
        if(!$erro) $sucesso='OS atualizada!';
    }

    // ───── FOTOS ─────
    elseif($acao==='upload_fotos'){
        $itemId=(int)($_POST['item_id']??0)?:null; $afId=(int)($_POST['afazer_id']??0)?:null;
        if($itemId){$c=$pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE id=? AND ordem_servico_id=?");$c->execute([$itemId,$osId]);if(!$c->fetchColumn())$itemId=null;}
        [$nf,$errs]=osSalvarFotos($pdo,$osId,$_FILES['fotos']??[],$u['id'],true,trim($_POST['descricao']??''),$itemId,$afId);
        $nf?$sucesso="$nf foto(s) adicionada(s)!":$erro='Nenhuma foto enviada.'; if($errs)$erro=implode(' ',$errs);
    }
    elseif($acao==='vinc_item_fotos'){
        $itemId=(int)($_POST['item_id']??0); $sel=array_map('intval',$_POST['foto_ids']??[]);
        $c=$pdo->prepare("SELECT COUNT(*) FROM os_itens WHERE id=? AND ordem_servico_id=?");$c->execute([$itemId,$osId]);
        if($c->fetchColumn()){
            $pdo->prepare("DELETE l FROM os_item_fotos l JOIN fotos f ON f.id=l.foto_id WHERE l.item_id=? AND f.ordem_servico_id=?")->execute([$itemId,$osId]);
            $chk=$pdo->prepare("SELECT COUNT(*) FROM fotos WHERE id=? AND ordem_servico_id=?");
            foreach($sel as $fid){$chk->execute([$fid,$osId]); if($chk->fetchColumn())$pdo->prepare("INSERT IGNORE INTO os_item_fotos (item_id,foto_id) VALUES (?,?)")->execute([$itemId,$fid]);}
            $sucesso='Fotos do item atualizadas!';
        }
    }
    elseif($acao==='vincular_afazer'){
        $pdo->prepare("UPDATE fotos SET afazer_id=? WHERE id=? AND ordem_servico_id=?")->execute([(int)($_POST['afazer_id']??0)?:null,(int)$_POST['foto_id'],$osId]);
        $sucesso='Foto vinculada!';
    }
    elseif($acao==='aprovar_foto'){
        $pdo->prepare("UPDATE fotos SET aprovada=1 WHERE id=? AND ordem_servico_id=?")->execute([(int)($_POST['foto_id']??0),$osId]);
        $sucesso='Foto aprovada!';
    }
    elseif($acao==='aprovar_todas'){
        $n=$pdo->prepare("UPDATE fotos SET aprovada=1 WHERE ordem_servico_id=? AND aprovada=0");$n->execute([$osId]);
        $sucesso=$n->rowCount().' foto(s) aprovada(s)!';
    }
    elseif($acao==='apagar_foto'){
        osApagarFoto($pdo,(int)($_POST['foto_id']??0),$osId); $sucesso='Foto removida.';
    }

    if(!$erro||$sucesso){ // PRG: evita reenviar o formulário ao atualizar
        $_SESSION['flash_os']=[$sucesso,$erro];
        header('Location: editar_os.php?id='.$osId.(isset($_POST['ancora'])?'#'.preg_replace('/[^a-z0-9_-]/i','',$_POST['ancora']):'')); exit;
    }
}
if(!empty($_SESSION['flash_os'])){[$sucesso,$erro]=$_SESSION['flash_os'];unset($_SESSION['flash_os']);}
$stmtOS->execute([$osId]);$os=$stmtOS->fetch(); // dados atualizados após um POST com aviso

// ── Dados ──
$afazeres=osAfazeres($pdo,$osId); [$afT,$afF,$afPct]=osProgresso($afazeres);
$fotos=osFotos($pdo,$osId); $fotosItem=osFotosPorItem($fotos); $fotosAfazer=osFotosPorAfazer($fotos);
$fotosPend=count(array_filter($fotos,fn($f)=>!$f['aprovada']));

$itensAdmin=$pdo->prepare("SELECT oi.*,e.foto AS estoque_foto,e.codigo AS estoque_codigo,c.nome AS cat_nome,c.cor AS cat_cor FROM os_itens oi LEFT JOIN estoque e ON e.id=oi.estoque_id LEFT JOIN rally_categorias c ON c.id=oi.categoria_id WHERE oi.ordem_servico_id=? AND oi.origem NOT IN ('mecanico','cliente') ORDER BY oi.criado_em, oi.id");
$itensAdmin->execute([$osId]);$itensAdmin=$itensAdmin->fetchAll();
$itensMec=$pdo->prepare("SELECT oi.*,c.nome AS cat_nome,us.nome AS solicitante_nome FROM os_itens oi LEFT JOIN rally_categorias c ON c.id=oi.categoria_id LEFT JOIN usuarios us ON us.id=oi.solicitado_por WHERE oi.ordem_servico_id=? AND oi.origem IN ('mecanico','cliente') ORDER BY FIELD(oi.status_aprovacao,'pendente','aprovado','recusado'), oi.criado_em");
$itensMec->execute([$osId]);$itensMec=$itensMec->fetchAll();

$mecanicos=$pdo->query("SELECT id,nome FROM usuarios WHERE tipo='mecanico' AND ativo=1 ORDER BY nome")->fetchAll();
$veiculos=$pdo->prepare("SELECT * FROM veiculos WHERE cliente_id=?");$veiculos->execute([$os['cliente_id']]);$veiculos=$veiculos->fetchAll();
$rallys=$pdo->prepare("SELECT id,nome FROM rallys WHERE ativo=1 OR id=? ORDER BY data_inicio DESC");$rallys->execute([(int)$os['rally_id']]);$rallys=$rallys->fetchAll();
$categorias=$pdo->query("SELECT id,nome,cor FROM rally_categorias WHERE ativo=1 ORDER BY nome")->fetchAll();

$statusLabel=['aberta'=>'Aberta','em_andamento'=>'Em andamento','aguardando_peca'=>'Aguard. peça','concluida'=>'Concluída','cancelada'=>'Cancelada'];
$isFechada=in_array($os['status'],['concluida','cancelada']);

$totCA=array_sum(array_map(fn($i)=>($i['valor_custo']??0)*$i['quantidade'],$itensAdmin));
$totVA=array_sum(array_map(fn($i)=>($i['valor']??0)*$i['quantidade'],$itensAdmin));
$itensMecOk=array_filter($itensMec,fn($i)=>$i['status_aprovacao']==='aprovado');
$totCM=array_sum(array_map(fn($i)=>($i['valor_custo']??0)*$i['quantidade'],$itensMecOk));
$totVM=array_sum(array_map(fn($i)=>($i['valor']??0)*$i['quantidade'],$itensMecOk));
$qtdPendItens=count(array_filter($itensMec,fn($i)=>$i['status_aprovacao']==='pendente'));

// Dados para o modal de fotos do item (JS)
$todosItens=array_merge($itensAdmin,$itensMec);
$jsItens=[]; foreach($todosItens as $it){ $jsItens[$it['id']]=['id'=>(int)$it['id'],'descricao'=>$it['descricao'],'estoque'=>(($ef=($it['estoque_foto']??null)?:($it['foto_item']??null)))?fotoUrl('estoque/'.$ef):null,'fotos'=>array_map(fn($f)=>(int)$f['id'],$fotosItem[$it['id']]??[])]; }
$jsFotos=[]; foreach($fotos as $f){ $jsFotos[]=['id'=>(int)$f['id'],'thumb'=>fotoThumbUrl($f['thumb'],$f['caminho']),'src'=>fotoUrl($f['caminho']),'legenda'=>$f['descricao']??'','aprovada'=>(int)$f['aprovada']]; }
[$capaThumb,$capaFull]=veiculoCapa($os);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <?php if($capaThumb): ?><img src="<?= $capaThumb ?>" class="carro-mini" style="width:84px;height:56px;cursor:pointer" data-galeria="capa" data-src="<?= $capaFull ?>" data-legenda="<?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?>"><?php endif; ?>
    <div>
      <a href="ordens.php" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
      <h5 class="fw-bold mb-0 mt-1"><span class="badge bg-dark me-2"><?= htmlspecialchars($os['numero_os']??$os['numero_cliente']) ?></span><?= htmlspecialchars($os['titulo']) ?></h5>
      <div class="text-muted small mt-1"><i class="bi bi-person me-1"></i><?= htmlspecialchars($os['cliente_nome']) ?><?php if($os['marca']): ?> · <a href="veiculo.php?id=<?= $os['veiculo_id'] ?>" class="text-decoration-none" title="Histórico do carro"><i class="bi bi-car-front me-1"></i><?= htmlspecialchars($os['marca'].' '.$os['modelo']) ?></a><?php endif; ?>
        · <?= ($os['local']??'oficina')==='rally'?'<i class="bi bi-flag me-1"></i>':'<i class="bi bi-wrench me-1"></i>' ?><?= htmlspecialchars(osLocalLabel($os)) ?>
        · <i class="bi bi-speedometer2 me-1"></i><?= $os['km_entrada']!==null?kmFmt($os['km_entrada']):'<span class="text-warning">km não informada</span>' ?></div>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="badge badge-<?= $os['status'] ?> fs-6"><?= $statusLabel[$os['status']] ?></span>
    <span class="fs-5 fw-bold text-success">R$ <?= number_format($os['valor_total'],2,',','.') ?></span>
  </div>
</div>

<?php if($sucesso): ?><div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if($erro):    ?><div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if($isFechada): ?><div class="alert alert-warning mb-3"><i class="bi bi-lock-fill me-2"></i>OS fechada. Altere o status para editar.</div><?php endif; ?>
<?php if($fotosPend): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center py-2 mb-3">
  <span><i class="bi bi-hourglass-split me-2"></i><strong><?= $fotosPend ?> foto(s)</strong> do mecânico aguardando aprovação.</span>
  <span><a href="#fotos" class="btn btn-sm btn-outline-dark me-1">Ver</a>
  <form method="post" class="d-inline"><input type="hidden" name="acao" value="aprovar_todas"><input type="hidden" name="ancora" value="fotos"><button class="btn btn-sm btn-success"><i class="bi bi-check2-all me-1"></i>Aprovar todas</button></form></span>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">

    <!-- Dados gerais -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
        <span>Dados Gerais</span>
        <button class="btn btn-sm btn-link text-decoration-none p-0" data-bs-toggle="collapse" data-bs-target="#dadosGerais">mostrar/ocultar</button>
      </div>
      <div class="collapse show" id="dadosGerais"><div class="card-body">
        <form method="post">
          <input type="hidden" name="acao" value="salvar_os">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Título da OS</label>
              <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($os['titulo']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Status</label>
              <select name="status" class="form-select"><?php foreach($statusLabel as $k=>$v): ?><option value="<?= $k ?>" <?= $os['status']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Mecânico</label>
              <select name="mecanico_id" class="form-select"><option value="">— Nenhum —</option>
                <?php foreach($mecanicos as $m): ?><option value="<?= $m['id'] ?>" <?= $os['mecanico_id']==$m['id']?'selected':'' ?>><?= htmlspecialchars($m['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label">Veículo</label>
              <select name="veiculo_id" class="form-select"><option value="">— Nenhum —</option>
                <?php foreach($veiculos as $v): ?><option value="<?= $v['id'] ?>" <?= $os['veiculo_id']==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['marca'].' '.$v['modelo'].' '.$v['placa']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Km do painel na entrada</label>
              <div class="input-group"><input type="text" name="km_entrada" class="form-control" inputmode="numeric" value="<?= htmlspecialchars($pedirConfKm?($_POST['km_entrada']??''):($os['km_entrada']!==null?number_format($os['km_entrada'],0,',','.'):'')) ?>" placeholder="ex.: 1.400"><span class="input-group-text">km</span></div>
              <?php if($os['km_atual']!==null): ?><div class="form-text">Km atual do carro: <?= kmFmt($os['km_atual']) ?></div><?php endif; ?></div>
            <div class="col-md-4"><label class="form-label">Local/Rally</label><?= osSelectLocal($rallys,$os) ?></div>
            <?php if($pedirConfKm): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirmar_menor" value="1" id="confKm"><label class="form-check-label text-warning-emphasis" for="confKm">Confirmo que a km é menor que a anterior (ex.: troca de painel)</label></div></div><?php endif; ?>
          </div>
          <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Salvar</button></div>
        </form>
      </div></div>
    </div>

    <!-- ═══ PARTE 1: AFAZERES DO MECÂNICO ═══ -->
    <div class="card border-0 shadow-sm mb-4" id="afazeres">
      <div class="card-header bg-white border-bottom">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-ui-checks me-2 text-success"></i>1 · Afazeres do mecânico</span>
          <span class="small text-muted"><?= $afF ?>/<?= $afT ?> concluídos</span>
        </div>
        <?php if($afT): ?><div class="progresso-os mt-2"><div style="width:<?= $afPct ?>%"></div></div><?php endif; ?>
        <div class="small text-muted mt-1"><i class="bi bi-eye me-1"></i>Aparece para o mecânico (para executar) e para o cliente (para acompanhar). <strong>Sem valores.</strong></div>
      </div>
      <div class="card-body p-0">
        <?php if($afazeres): foreach($afazeres as $k=>$a): $fa=$fotosAfazer[$a['id']]??[]; ?>
        <div class="afazer <?= $a['concluido']?'feito':'' ?>">
          <form method="post" class="m-0"><input type="hidden" name="acao" value="toggle_afazer"><input type="hidden" name="afazer_id" value="<?= $a['id'] ?>"><input type="hidden" name="ancora" value="afazeres">
            <button class="btn p-0 border-0 bg-transparent chk" <?= $isFechada?'disabled':'' ?>><?= $a['concluido']?'<i class="bi bi-check-circle-fill text-success"></i>':'<i class="bi bi-circle text-muted"></i>' ?></button></form>
          <div class="flex-fill">
            <div class="txt"><?= htmlspecialchars($a['descricao']) ?></div>
            <?php if($a['observacao']): ?><div class="small text-muted"><?= htmlspecialchars($a['observacao']) ?></div><?php endif; ?>
            <?php if($a['concluido']): ?><div class="meta"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($a['concluido_nome']??'') ?> · <?= $a['concluido_em']?date('d/m H:i',strtotime($a['concluido_em'])):'' ?></div><?php endif; ?>
          </div>
          <div class="d-flex gap-1 align-items-center">
            <?= osBotaoFoto($fa,null,$a['descricao'],'ver') ?>
            <?php if(!$isFechada): ?>
            <form method="post" class="m-0"><input type="hidden" name="acao" value="mover_afazer"><input type="hidden" name="afazer_id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="up"><input type="hidden" name="ancora" value="afazeres"><button class="btn btn-sm btn-light py-0 px-1" <?= $k===0?'disabled':'' ?> title="Subir"><i class="bi bi-arrow-up"></i></button></form>
            <form method="post" class="m-0"><input type="hidden" name="acao" value="mover_afazer"><input type="hidden" name="afazer_id" value="<?= $a['id'] ?>"><input type="hidden" name="dir" value="down"><input type="hidden" name="ancora" value="afazeres"><button class="btn btn-sm btn-light py-0 px-1" <?= $k===count($afazeres)-1?'disabled':'' ?> title="Descer"><i class="bi bi-arrow-down"></i></button></form>
            <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="editarAfazer(<?= htmlspecialchars(json_encode(['id'=>$a['id'],'d'=>$a['descricao'],'o'=>$a['observacao']]),ENT_QUOTES) ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
            <form method="post" class="m-0"><input type="hidden" name="acao" value="del_afazer"><input type="hidden" name="afazer_id" value="<?= $a['id'] ?>"><input type="hidden" name="ancora" value="afazeres"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover este afazer?"><i class="bi bi-trash3"></i></button></form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted text-center py-4 mb-0"><i class="bi bi-ui-checks d-block fs-3 mb-1"></i>Nenhum afazer. Liste abaixo o que o mecânico deve revisar/executar.</p>
        <?php endif; ?>
      </div>
      <?php if(!$isFechada): ?>
      <div class="card-footer bg-light">
        <form method="post">
          <input type="hidden" name="acao" value="add_afazer"><input type="hidden" name="ancora" value="afazeres">
          <label class="form-label small fw-semibold mb-1">Adicionar afazeres <span class="text-muted fw-normal">(um por linha para adicionar vários)</span></label>
          <div class="d-flex gap-2">
            <textarea name="descricao" rows="2" class="form-control form-control-sm" placeholder="Revisar suspensão dianteira&#10;Verificar folga dos rolamentos&#10;Trocar óleo do motor" required></textarea>
            <button class="btn btn-success btn-sm px-3"><i class="bi bi-plus-lg"></i></button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ═══ PARTE 2: ORDEM DE SERVIÇO — ITENS COM VALOR ═══ -->
    <div class="card border-0 shadow-sm mb-4" id="itens">
      <div class="card-header bg-white border-bottom">
        <div class="d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-receipt me-2 text-primary"></i>2 · Ordem de serviço — peças, serviços e gastos</span>
          <span class="badge bg-primary"><?= count($itensAdmin) ?></span>
        </div>
        <div class="small text-muted mt-1"><i class="bi bi-eye me-1"></i>Cliente vê descrição, quantidade e valor de venda. Mecânico não vê esta parte.</div>
      </div>
      <div class="card-body p-0">
        <?php if($itensAdmin): ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0 small align-middle">
          <thead><tr>
            <th style="width:44px"></th><th>Descrição</th><th class="text-center">Qtd</th>
            <th class="text-end">Custo Unit.</th><th class="text-end">Total Custo</th>
            <th class="text-end">Venda Unit.</th><th class="text-end">Total Venda</th>
            <th class="text-end">Lucro</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach($itensAdmin as $item):
            $tc=($item['valor_custo']??0)*$item['quantidade']; $tv=($item['valor']??0)*$item['quantidade']; $lu=$tv-$tc;
            $fi=$fotosItem[$item['id']]??[]; ?>
          <tr>
            <td><?= osBotaoFoto($fi,($item['estoque_foto']??null)?:($item['foto_item']??null),$item['descricao'],'gerenciar',"abrirFotosItem({$item['id']})") ?></td>
            <td>
              <?= htmlspecialchars($item['descricao']) ?>
              <?php if($item['estoque_id']): ?><span class="badge bg-light text-secondary border ms-1" style="font-size:.62rem" title="Item do estoque"><i class="bi bi-box-seam"></i> <?= htmlspecialchars($item['estoque_codigo']??'') ?></span><?php endif; ?>
              <?php if($item['cat_nome']): ?><span class="badge ms-1" style="background:<?= $item['cat_cor'] ?>;font-size:.62rem"><?= htmlspecialchars($item['cat_nome']) ?></span><?php endif; ?>
            </td>
            <td class="text-center fw-semibold"><?= $item['quantidade'] ?></td>
            <td class="text-end text-muted"><?= $item['valor_custo']!==null?'R$ '.number_format($item['valor_custo'],2,',','.'):'—' ?></td>
            <td class="text-end text-muted"><?= $item['valor_custo']!==null?'R$ '.number_format($tc,2,',','.'):'—' ?></td>
            <td class="text-end <?= $item['valor']?'text-success fw-semibold':'text-muted' ?>"><?= $item['valor']?'R$ '.number_format($item['valor'],2,',','.'):'—' ?></td>
            <td class="text-end <?= $item['valor']?'text-success fw-bold':'text-muted' ?>"><?= $item['valor']?'R$ '.number_format($tv,2,',','.'):'—' ?></td>
            <td class="text-end fw-semibold <?= $lu>0?'text-success':($lu<0?'text-danger':'text-muted') ?>"><?= ($item['valor']&&$item['valor_custo']!==null)?'R$ '.number_format($lu,2,',','.'):'—' ?></td>
            <td class="text-nowrap">
              <?php if(!$isFechada): ?>
              <button class="btn btn-sm btn-outline-primary py-0 px-1 me-1" onclick="abrirEditItem(<?= htmlspecialchars(json_encode($item)) ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline"><input type="hidden" name="acao" value="del_item"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="itens">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover?"><i class="bi bi-trash3"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold"><tr>
            <td colspan="3" class="text-end">Totais:</td><td></td>
            <td class="text-end text-muted">R$ <?= number_format($totCA,2,',','.') ?></td><td></td>
            <td class="text-end text-success">R$ <?= number_format($totVA,2,',','.') ?></td>
            <td class="text-end <?= ($totVA-$totCA)>=0?'text-success':'text-danger' ?>">R$ <?= number_format($totVA-$totCA,2,',','.') ?></td><td></td>
          </tr></tfoot>
        </table>
        </div>
        <?php else: ?><p class="text-muted text-center py-3 mb-0">Nenhum item com valor ainda.</p><?php endif; ?>
      </div>

      <?php if(!$isFechada): ?>
      <div class="card-footer bg-light border-top">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="acao" value="add_item"><input type="hidden" name="ancora" value="itens">
          <input type="hidden" name="estoque_id" id="itemEstoqueId" value="">
          <h6 class="fw-semibold mb-2 small">Adicionar peça / serviço / gasto</h6>
          <div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label form-label-sm">Categoria</label>
              <select name="categoria_id" class="form-select form-select-sm"><option value="">—</option>
                <?php foreach($categorias as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label form-label-sm">Descrição *</label>
              <input type="text" name="descricao" id="itemDescricao" class="form-control form-control-sm" required></div>
            <div class="col-md-1"><label class="form-label form-label-sm">Qtd</label>
              <input type="number" name="quantidade" id="itemQtd" class="form-control form-control-sm" value="1" min="1"></div>
            <div class="col-md-2"><label class="form-label form-label-sm">Custo Unit.</label>
              <input type="text" name="valor_custo" id="itemCusto" class="form-control form-control-sm" placeholder="0,00"></div>
            <div class="col-md-2"><label class="form-label form-label-sm">Venda Unit.</label>
              <input type="text" name="valor" id="itemValor" class="form-control form-control-sm" placeholder="0,00"></div>
            <div class="col-md-2 d-flex gap-1">
              <button type="submit" class="btn btn-success btn-sm flex-fill"><i class="bi bi-plus-lg"></i></button>
              <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalEstoque" title="Buscar no estoque"><i class="bi bi-box-seam"></i></button>
            </div>
            <div class="col-md-6"><label class="form-label form-label-sm"><i class="bi bi-camera me-1"></i>Fotos de referência (opcional, várias)</label>
              <input type="file" name="fotos_item[]" class="form-control form-control-sm" accept="image/*" multiple data-reduzir data-preview="prevAddItem"></div>
            <div class="col-md-6"><div id="prevAddItem" class="d-flex gap-1 flex-wrap"></div><div class="form-text" id="itemEstoqueInfo"></div></div>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fotos da OS -->
    <div class="card border-0 shadow-sm" id="fotos">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-images me-2 text-primary"></i>Fotos da OS</span>
        <div class="d-flex gap-2 align-items-center">
          <span class="badge bg-secondary"><?= count($fotos) ?></span>
          <button class="btn btn-sm btn-outline-primary" onclick="abrirUploadGeral()"><i class="bi bi-cloud-upload me-1"></i>Enviar fotos</button>
        </div>
      </div>
      <div class="card-body">
        <?php if($fotos): ?>
        <div class="row g-3">
          <?php foreach($fotos as $f): ?>
          <div class="col-6 col-md-4 col-xl-3">
            <div class="position-relative">
              <img src="<?= fotoThumbUrl($f['thumb'],$f['caminho']) ?>" class="w-100 rounded" style="aspect-ratio:4/3;object-fit:cover;cursor:pointer"
                   data-galeria="os" data-src="<?= fotoUrl($f['caminho']) ?>" data-legenda="<?= htmlspecialchars($f['descricao']??'') ?>">
              <span class="badge position-absolute top-0 start-0 m-1 <?= $f['aprovada']?'bg-success':'bg-warning text-dark' ?>" style="font-size:.6rem"><?= $f['aprovada']?'Aprovada':'Pendente' ?></span>
            </div>
            <div class="mt-1" style="min-height:20px">
              <?php foreach($f['itens'] as $it): ?><span class="badge bg-primary-subtle text-primary-emphasis me-1" style="font-size:.62rem"><i class="bi bi-tag me-1"></i><?= htmlspecialchars(mb_strimwidth($it['descricao'],0,22,'…')) ?></span><?php endforeach; ?>
              <?php if($f['afazer_desc']): ?><span class="badge bg-success-subtle text-success-emphasis" style="font-size:.62rem"><i class="bi bi-check2-square me-1"></i><?= htmlspecialchars(mb_strimwidth($f['afazer_desc'],0,22,'…')) ?></span><?php endif; ?>
              <?php if(!$f['itens']&&!$f['afazer_desc']): ?><span class="text-muted small">Foto geral</span><?php endif; ?>
            </div>
            <?php if($f['descricao']): ?><div class="small text-muted text-truncate"><?= htmlspecialchars($f['descricao']) ?></div><?php endif; ?>
            <div class="text-muted" style="font-size:.68rem"><?= htmlspecialchars($f['enviado_nome']) ?> · <?= date('d/m/Y',strtotime($f['criado_em'])) ?></div>
            <div class="d-flex gap-1 mt-1">
              <?php if(!$f['aprovada']): ?>
              <form method="post" class="d-inline"><input type="hidden" name="acao" value="aprovar_foto"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><input type="hidden" name="ancora" value="fotos">
                <button class="btn btn-success btn-sm py-0 px-1" title="Aprovar"><i class="bi bi-check-lg"></i></button></form>
              <?php endif; ?>
              <?php if($afazeres): ?>
              <form method="post" class="d-inline flex-fill"><input type="hidden" name="acao" value="vincular_afazer"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><input type="hidden" name="ancora" value="fotos">
                <select name="afazer_id" class="form-select form-select-sm py-0" style="font-size:.7rem" onchange="this.form.submit()" title="Vincular a um afazer">
                  <option value="">Afazer: —</option>
                  <?php foreach($afazeres as $a): ?><option value="<?= $a['id'] ?>" <?= $f['afazer_id']==$a['id']?'selected':'' ?>><?= htmlspecialchars(mb_strimwidth($a['descricao'],0,26,'…')) ?></option><?php endforeach; ?>
                </select></form>
              <?php endif; ?>
              <form method="post" class="d-inline"><input type="hidden" name="acao" value="apagar_foto"><input type="hidden" name="foto_id" value="<?= $f['id'] ?>"><input type="hidden" name="ancora" value="fotos">
                <button class="btn btn-outline-danger btn-sm py-0 px-1" data-confirm="Apagar foto?"><i class="bi bi-trash3"></i></button></form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Para ligar fotos a peças/serviços, clique no ícone <i class="bi bi-camera"></i> na linha do item.</div>
        <?php else: ?>
        <p class="text-muted text-center py-3 mb-0"><i class="bi bi-camera-slash d-block fs-3 mb-1"></i>Nenhuma foto enviada.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Coluna direita -->
  <div class="col-xl-4">
    <!-- Itens encontrados pelo mecânico -->
    <div class="card border-0 shadow-sm mb-3" id="encontrados">
      <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-wrench me-2 text-warning"></i>Encontrados pelo mecânico e pedidos do cliente</span>
        <span class="badge <?= $qtdPendItens?'bg-danger':'bg-secondary' ?>" title="Aguardando aprovação"><?= $qtdPendItens ?: count($itensMec) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if($itensMec): ?>
        <ul class="list-group list-group-flush small">
          <?php foreach($itensMec as $item): ?>
          <?php $stAp=$item['status_aprovacao']; ?>
          <li class="list-group-item <?= $stAp==='pendente'?'bg-warning-subtle':($stAp==='recusado'?'bg-light text-muted':'') ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="d-flex gap-2 align-items-start">
                <?= osBotaoFoto($fotosItem[$item['id']]??[],null,$item['descricao'],'gerenciar',"abrirFotosItem({$item['id']})") ?>
                <div>
                  <?= $item['origem']==='cliente'?'<span class="badge bg-primary me-1">Cliente</span>':'<span class="badge bg-success me-1">Mecânico</span>' ?>
                  <span class="<?= $stAp==='recusado'?'text-decoration-line-through':'' ?>"><?= htmlspecialchars($item['descricao']) ?></span> <?= $item['concluido']?'<i class="bi bi-check-circle-fill text-success"></i>':'' ?>
                  <?php if($stAp==='pendente'): ?><div><span class="badge bg-warning text-dark">Aguardando aprovação</span></div>
                  <?php elseif($stAp==='recusado'): ?><div class="small text-danger">Recusado<?= $item['motivo_recusa']?': '.htmlspecialchars($item['motivo_recusa']):'' ?></div><?php endif; ?>
                </div>
              </div>
              <?php if(!$isFechada): ?>
              <form method="post" class="m-0"><input type="hidden" name="acao" value="del_item"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover?"><i class="bi bi-trash3"></i></button></form>
              <?php endif; ?>
            </div>
            <?php if(!$isFechada && $stAp==='pendente'): ?>
            <form method="post" class="d-flex gap-1 mt-2"><input type="hidden" name="acao" value="item_aprovar"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
              <input type="text" name="valor_custo" class="form-control form-control-sm" placeholder="custo">
              <input type="text" name="valor" class="form-control form-control-sm" placeholder="venda">
              <button class="btn btn-sm btn-success text-nowrap" title="Aprovar (valores opcionais)"><i class="bi bi-check-lg"></i> Aprovar</button>
            </form>
            <button class="btn btn-sm btn-link text-danger p-0 mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#rec<?= $item['id'] ?>"><i class="bi bi-x-circle me-1"></i>Recusar</button>
            <form method="post" class="collapse mt-1" id="rec<?= $item['id'] ?>"><input type="hidden" name="acao" value="item_recusar"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
              <div class="d-flex gap-1"><input type="text" name="motivo" class="form-control form-control-sm" maxlength="255" placeholder="Motivo (aparece para quem pediu)"><button class="btn btn-sm btn-danger text-nowrap">Recusar</button></div></form>
            <?php elseif(!$isFechada && $stAp==='aprovado'): ?>
            <form method="post" class="d-flex gap-1 mt-2"><input type="hidden" name="acao" value="set_valor"><input type="hidden" name="item_id" value="<?= $item['id'] ?>"><input type="hidden" name="ancora" value="encontrados">
              <input type="text" name="valor_custo" class="form-control form-control-sm" value="<?= $item['valor_custo']!==null?number_format($item['valor_custo'],2,',','.'):'' ?>" placeholder="custo">
              <input type="text" name="valor" class="form-control form-control-sm" value="<?= $item['valor']!==null?number_format($item['valor'],2,',','.'):'' ?>" placeholder="venda">
              <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i></button>
            </form>
            <?php elseif($stAp!=='recusado'): ?>
            <div class="text-muted mt-1" style="font-size:.72rem">C: <?= $item['valor_custo']!==null?'R$ '.number_format($item['valor_custo'],2,',','.'):'—' ?> · V: <?= $item['valor']!==null?'R$ '.number_format($item['valor'],2,',','.'):'—' ?></div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer bg-light small text-muted"><i class="bi bi-info-circle me-1"></i>Amarelo = aguardando aprovação. Item do mecânico aprovado aparece para o cliente; pedido do cliente aprovado vira afazer do mecânico. Também dá para aprovar na <a href="aprovacoes.php?aba=pedidos">Central</a>.</div>
        <?php else: ?><p class="text-muted text-center py-4 mb-0 small">Nenhum item encontrado pelo mecânico nem pedido do cliente.</p><?php endif; ?>
      </div>
    </div>

    <!-- Resumo financeiro -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold border-bottom"><i class="bi bi-graph-up me-2 text-success"></i>Resumo Financeiro</div>
      <div class="card-body">
        <?php $tC=$totCA+$totCM;$tV=$totVA+$totVM;$lu=$tV-$tC; ?>
        <table class="table table-sm mb-0"><tbody>
          <tr><td class="text-muted">Total Custo:</td><td class="text-end fw-semibold text-muted">R$ <?= number_format($tC,2,',','.') ?></td></tr>
          <tr><td class="fw-semibold">Total Cliente (Venda):</td><td class="text-end fw-bold text-success fs-6">R$ <?= number_format($tV,2,',','.') ?></td></tr>
          <tr class="table-light"><td class="fw-bold">Lucro da OS:</td><td class="text-end fw-bold fs-5 <?= $lu>=0?'text-success':'text-danger' ?>">R$ <?= number_format($lu,2,',','.') ?></td></tr>
        </tbody></table>
        <?php if($afT): ?><div class="mt-3 small"><div class="d-flex justify-content-between"><span class="text-muted">Andamento dos afazeres</span><strong><?= $afPct ?>%</strong></div><div class="progresso-os mt-1"><div style="width:<?= $afPct ?>%"></div></div></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal Fotos do Item -->
<div class="modal fade" id="modalFotosItem" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title"><i class="bi bi-camera me-2"></i>Fotos do item: <span id="fiTitulo" class="fw-bold"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="fiEstoque" class="mb-3"></div>
        <form method="post" enctype="multipart/form-data" class="border rounded p-3 bg-light mb-3">
          <input type="hidden" name="acao" value="upload_fotos"><input type="hidden" name="item_id" id="fiUploadItem"><input type="hidden" name="ancora" value="itens">
          <label class="form-label small fw-semibold"><i class="bi bi-cloud-upload me-1"></i>Enviar novas fotos para este item</label>
          <div class="d-flex gap-2">
            <input type="file" name="fotos[]" class="form-control form-control-sm" accept="image/*" multiple required data-reduzir data-preview="fiPrev">
            <input type="text" name="descricao" class="form-control form-control-sm" placeholder="Legenda (opcional)" style="max-width:200px">
            <button class="btn btn-primary btn-sm">Enviar</button>
          </div>
          <div id="fiPrev" class="d-flex gap-1 flex-wrap mt-2"></div>
        </form>
        <form method="post">
          <input type="hidden" name="acao" value="vinc_item_fotos"><input type="hidden" name="item_id" id="fiVincItem"><input type="hidden" name="ancora" value="itens">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label small fw-semibold mb-0"><i class="bi bi-link-45deg me-1"></i>Vincular fotos já enviadas nesta OS</label>
            <button class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i>Salvar vínculos</button>
          </div>
          <div id="fiGrade" class="row g-2"></div>
          <div class="small text-muted mt-2">Uma foto pode ficar ligada a vários itens.</div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Upload geral -->
<div class="modal fade" id="modalUploadGeral" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Enviar fotos para a OS</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="upload_fotos"><input type="hidden" name="ancora" value="fotos">
      <div class="modal-body">
        <input type="file" name="fotos[]" class="form-control mb-2" accept="image/*" multiple required data-reduzir data-preview="ugPrev">
        <div id="ugPrev" class="d-flex gap-1 flex-wrap mb-2"></div>
        <label class="form-label small mb-1">Ligar a</label>
        <div class="row g-2">
          <div class="col-6"><select name="item_id" class="form-select form-select-sm"><option value="">Item: — (geral)</option><?php foreach($todosItens as $it): ?><option value="<?= $it['id'] ?>"><?= htmlspecialchars(mb_strimwidth($it['descricao'],0,40,'…')) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><select name="afazer_id" class="form-select form-select-sm"><option value="">Afazer: —</option><?php foreach($afazeres as $a): ?><option value="<?= $a['id'] ?>"><?= htmlspecialchars(mb_strimwidth($a['descricao'],0,40,'…')) ?></option><?php endforeach; ?></select></div>
        </div>
        <input type="text" name="descricao" class="form-control form-control-sm mt-2" placeholder="Legenda (opcional)">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Enviar</button></div>
    </form>
  </div></div>
</div>

<!-- Modal Editar Afazer -->
<div class="modal fade" id="modalAfazer" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title">Editar afazer</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post"><input type="hidden" name="acao" value="edit_afazer"><input type="hidden" name="afazer_id" id="afId"><input type="hidden" name="ancora" value="afazeres">
      <div class="modal-body">
        <label class="form-label">Descrição</label><input type="text" name="descricao" id="afDesc" class="form-control mb-2" required>
        <label class="form-label">Observação <span class="text-muted small">(visível ao mecânico e ao cliente)</span></label><textarea name="observacao" id="afObs" class="form-control" rows="2"></textarea>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Salvar</button></div>
    </form>
  </div></div>
</div>

<!-- Modal Editar Item -->
<div class="modal fade" id="modalEditItem" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post">
      <input type="hidden" name="acao" value="editar_item"><input type="hidden" name="item_id" id="editItemId"><input type="hidden" name="ancora" value="itens">
      <div class="modal-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">Descrição *</label><input type="text" name="descricao" id="editItemDesc" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">Quantidade</label><input type="number" name="quantidade" id="editItemQtd" class="form-control" min="1" value="1"></div>
        <div class="col-md-3"><label class="form-label">Categoria</label><select name="categoria_id" id="editItemCat" class="form-select"><option value="">—</option><?php foreach($categorias as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Custo Unitário (R$)</label><input type="text" name="valor_custo" id="editItemCusto" class="form-control" placeholder="0,00"></div>
        <div class="col-md-3"><label class="form-label">Venda Unitária (R$)</label><input type="text" name="valor" id="editItemVenda" class="form-control" placeholder="0,00"></div>
        <div class="col-md-6 d-flex align-items-end"><div class="small text-muted"><i class="bi bi-camera me-1"></i>Fotos do item: use o ícone de câmera na linha.</div></div>
      </div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar Item</button></div>
    </form>
  </div></div>
</div>

<!-- Modal Buscar Estoque -->
<div class="modal fade" id="modalEstoque" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Buscar no Estoque</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="text" id="buscaEstoque" class="form-control mb-3" placeholder="Nome ou código...">
      <div id="resultadoEstoque"><p class="text-muted text-center py-3">Digite para buscar...</p></div>
    </div>
  </div></div>
</div>

<?php fotosModalGaleria(); ?>

<script>
const OS_ITENS=<?= json_encode($jsItens,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const OS_FOTOS=<?= json_encode($jsFotos,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function abrirFotosItem(id){
    const it=OS_ITENS[id]; if(!it) return;
    document.getElementById('fiTitulo').textContent=it.descricao;
    document.getElementById('fiUploadItem').value=id;
    document.getElementById('fiVincItem').value=id;
    document.getElementById('fiEstoque').innerHTML = it.estoque
      ? `<div class="d-flex align-items-center gap-3 p-2 border rounded" style="background:#f7f3fe"><img src="${it.estoque}" style="width:90px;height:68px;object-fit:cover;border-radius:6px;cursor:pointer" onclick="abrirGaleria([{src:'${it.estoque}',legenda:'Foto do estoque'}],0,'${esc(it.descricao)}')"><div class="small"><strong>Foto cadastrada no estoque</strong><br><span class="text-muted">Aparece automaticamente para este item. Você pode adicionar outras abaixo.</span></div></div>`
      : '';
    const g=document.getElementById('fiGrade');
    g.innerHTML = OS_FOTOS.length ? OS_FOTOS.map(f=>`<div class="col-3 col-md-2"><label class="foto-sel d-block"><input type="checkbox" name="foto_ids[]" value="${f.id}" ${it.fotos.includes(f.id)?'checked':''}><img src="${f.thumb}"></label><div class="small text-truncate text-muted" style="font-size:.68rem">${f.aprovada?'':'⏳ '}${esc(f.legenda)||'&nbsp;'}</div></div>`).join('')
      : '<div class="col-12 text-muted small">Ainda não há fotos nesta OS. Envie acima.</div>';
    new bootstrap.Modal(document.getElementById('modalFotosItem')).show();
}
function abrirUploadGeral(){ new bootstrap.Modal(document.getElementById('modalUploadGeral')).show(); }
function editarAfazer(a){
    document.getElementById('afId').value=a.id; document.getElementById('afDesc').value=a.d; document.getElementById('afObs').value=a.o||'';
    new bootstrap.Modal(document.getElementById('modalAfazer')).show();
}
function abrirEditItem(item){
    document.getElementById('editItemId').value=item.id;
    document.getElementById('editItemDesc').value=item.descricao;
    document.getElementById('editItemQtd').value=item.quantidade;
    document.getElementById('editItemCusto').value=item.valor_custo?parseFloat(item.valor_custo).toFixed(2).replace('.',','):'';
    document.getElementById('editItemVenda').value=item.valor?parseFloat(item.valor).toFixed(2).replace('.',','):'';
    const sel=document.getElementById('editItemCat'); for(let o of sel.options){o.selected=(o.value==(item.categoria_id||''));}
    new bootstrap.Modal(document.getElementById('modalEditItem')).show();
}
function selecionarItemEstoque(id,nome,vv,vc,foto){
    document.getElementById('itemEstoqueId').value=id;
    document.getElementById('itemDescricao').value=nome;
    document.getElementById('itemValor').value=vv;
    document.getElementById('itemCusto').value=vc;
    document.getElementById('itemEstoqueInfo').innerHTML='✅ Item do estoque selecionado'+(foto?' — a foto do estoque aparecerá na linha do item.':'.');
    bootstrap.Modal.getInstance(document.getElementById('modalEstoque')).hide();
}
let bt;
document.getElementById('buscaEstoque').addEventListener('input',function(){
    clearTimeout(bt);const q=this.value.trim();
    if(q.length<2){document.getElementById('resultadoEstoque').innerHTML='<p class="text-muted text-center py-3">Digite 2+ caracteres...</p>';return;}
    bt=setTimeout(()=>{
        fetch('<?= BASE_URL ?>/admin/ajax_estoque.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(data=>{
            const div=document.getElementById('resultadoEstoque');
            if(!data.length){div.innerHTML='<p class="text-muted text-center py-3">Nenhum item encontrado.</p>';return;}
            div.innerHTML=data.map(item=>`<div class="mb-2"><div class="card border shadow-sm"><div class="card-body py-2 d-flex justify-content-between align-items-center"><div class="d-flex align-items-center gap-3">${item.foto?`<img src="<?= BASE_URL ?>/uploads/estoque/${item.foto}" style="width:48px;height:48px;object-fit:cover;border-radius:6px">`:'<div style="width:48px;height:48px;background:#eee;border-radius:6px"></div>'}<div><div class="fw-semibold">${esc(item.nome)}${item.local?` <span class="badge" style="background:#6f42c1"><i class="bi bi-geo-alt-fill me-1"></i>${esc(item.local)}</span>`:''}</div><div class="small text-muted">${item.part_number?`PN ${esc(item.part_number)} · `:''}Qtd: <strong class="${item.quantidade<=0?'text-danger':''}">${item.quantidade}</strong> · Custo: R$ ${parseFloat(item.valor_custo).toFixed(2).replace('.',',')} · Venda: <span class="text-success">R$ ${parseFloat(item.valor_venda).toFixed(2).replace('.',',')}</span></div></div></div><button class="btn btn-primary btn-sm" ${item.quantidade<=0?'disabled':''} onclick="selecionarItemEstoque(${item.id},'${String(item.nome).replace(/\\/g,'\\\\').replace(/'/g,"\\'")}','${parseFloat(item.valor_venda).toFixed(2).replace('.',',')}','${parseFloat(item.valor_custo).toFixed(2).replace('.',',')}','${item.foto||''}')"><i class="bi bi-plus-lg me-1"></i>Selecionar</button></div></div></div>`).join('');
        });
    },400);
});
</script>

<?php require_once '../includes/footer.php'; ?>
