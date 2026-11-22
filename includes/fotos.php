<?php
// includes/fotos.php – Armazenamento padronizado de imagens (v8)
//
// Toda imagem enviada passa por aqui:
//  • valida o tipo real do arquivo (não só a extensão)
//  • corrige a rotação do celular (EXIF)
//  • reduz para no máximo FOTO_MAX_LADO px e regrava em JPEG (qualidade FOTO_QUALIDADE)
//  • gera miniatura de THUMB_LADO px para listas e galerias
//  • organiza em pastas: uploads/os/{id}/, uploads/veiculos/, uploads/corridas/{id}/ ...
// Uma foto de celular de 4–8 MB costuma ficar com 250–500 KB, e a miniatura com ~30 KB.

if (!defined('FOTO_MAX_LADO'))  define('FOTO_MAX_LADO', 1920);
if (!defined('FOTO_QUALIDADE')) define('FOTO_QUALIDADE', 82);
if (!defined('THUMB_LADO'))     define('THUMB_LADO', 480);
if (!defined('THUMB_QUALIDADE'))define('THUMB_QUALIDADE', 75);

/** Converte $_FILES['campo'] (simples ou múltiplo) em lista de arquivos. */
function fotosNormalizarFiles(?array $f): array {
    if (!$f || empty($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $lista = [];
    foreach ($f['name'] as $i => $n) {
        if ($n === '' || $n === null) continue;
        $lista[] = ['name'=>$n,'type'=>$f['type'][$i]??'','tmp_name'=>$f['tmp_name'][$i]??'','error'=>$f['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$f['size'][$i]??0];
    }
    return $lista;
}

function fotosGdDisponivel(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
}

/** Abre a imagem com GD conforme o tipo real. */
function fotosAbrir(string $arq, string $mime) {
    switch ($mime) {
        case 'image/jpeg': return @imagecreatefromjpeg($arq);
        case 'image/png':  return @imagecreatefrompng($arq);
        case 'image/gif':  return @imagecreatefromgif($arq);
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($arq) : false;
    }
    return false;
}

/** Aplica rotação EXIF (fotos de celular "deitadas"). */
function fotosCorrigirOrientacao($img, string $arq, string $mime) {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($arq);
    $o = $exif['Orientation'] ?? 1;
    switch ($o) {
        case 3: $r = imagerotate($img, 180, 0); break;
        case 6: $r = imagerotate($img, -90, 0); break;
        case 8: $r = imagerotate($img, 90, 0); break;
        default: return $img;
    }
    if ($r) { imagedestroy($img); return $r; }
    return $img;
}

/** Redimensiona mantendo proporção (fundo branco para PNG transparente). */
function fotosRedimensionar($img, int $maxLado) {
    $w = imagesx($img); $h = imagesy($img);
    $esc = min(1, $maxLado / max($w, $h));
    $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * Salva uma imagem enviada.
 * @param array  $file    item de $_FILES (ou de fotosNormalizarFiles)
 * @param string $subdir  ex.: "os/15", "veiculos", "corridas/3", "estoque"
 * @param string $prefixo prefixo do nome do arquivo
 * @return array ['caminho'=>'os/15/x.jpg','thumb'=>'os/15/thumbs/x.jpg','tamanho'=>bytes] ou ['erro'=>'...']
 */
function fotoSalvarUpload(array $file, string $subdir, string $prefixo = 'img'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = $file['error'] ?? -1;
        if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) return ['erro'=>'Arquivo maior que o limite do servidor.'];
        return ['erro'=>'Falha no envio do arquivo.'];
    }
    return fotoSalvarArquivo($file['tmp_name'], $subdir, $prefixo, true);
}

/** Mesmo que acima, para um arquivo já existente no disco (usado na otimização de fotos antigas). */
function fotoSalvarArquivo(string $origem, string $subdir, string $prefixo = 'img', bool $ehUpload = false): array {
    if (!is_file($origem)) return ['erro'=>'Arquivo não encontrado.'];
    $mime = '';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $origem); finfo_close($fi); }
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) return ['erro'=>'Envie uma imagem JPG, PNG ou WEBP.'];

    $subdir = trim($subdir, '/');
    $dir = UPLOAD_DIR . $subdir . '/';
    $dirThumb = $dir . 'thumbs/';
    if (!is_dir($dirThumb) && !@mkdir($dirThumb, 0755, true)) return ['erro'=>'Não foi possível criar a pasta de destino.'];

    $base = preg_replace('/[^a-z0-9_]/i', '', $prefixo) . '_' . date('ymd') . '_' . bin2hex(random_bytes(4));

    if (fotosGdDisponivel() && ($img = fotosAbrir($origem, $mime))) {
        $img = fotosCorrigirOrientacao($img, $origem, $mime);
        $grande = fotosRedimensionar($img, FOTO_MAX_LADO);
        $mini   = fotosRedimensionar($img, THUMB_LADO);
        imagedestroy($img);
        $nome = $base . '.jpg';
        imageinterlace($grande, true);
        $okG = imagejpeg($grande, $dir.$nome, FOTO_QUALIDADE);
        $okT = imagejpeg($mini, $dirThumb.$nome, THUMB_QUALIDADE);
        imagedestroy($grande); imagedestroy($mini);
        if (!$okG) return ['erro'=>'Não foi possível gravar a imagem.'];
        if ($ehUpload && is_uploaded_file($origem)) @unlink($origem);
        return ['caminho'=>"$subdir/$nome", 'thumb'=>$okT ? "$subdir/thumbs/$nome" : null, 'tamanho'=>(int)filesize($dir.$nome)];
    }

    // Sem GD: grava o original como veio
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
    $nome = "$base.$ext";
    $ok = $ehUpload ? move_uploaded_file($origem, $dir.$nome) : copy($origem, $dir.$nome);
    if (!$ok) return ['erro'=>'Não foi possível gravar a imagem.'];
    return ['caminho'=>"$subdir/$nome", 'thumb'=>null, 'tamanho'=>(int)filesize($dir.$nome)];
}

/** Apaga arquivos (ignora vazios e caminhos fora de uploads). */
function fotoApagar(?string ...$caminhos): void {
    foreach ($caminhos as $c) {
        if (!$c || str_contains($c, '..')) continue;
        $arq = UPLOAD_DIR . ltrim($c, '/');
        if (is_file($arq)) @unlink($arq);
    }
}

function fotoUrl(?string $caminho): string {
    return $caminho ? BASE_URL . '/uploads/' . ltrim($caminho, '/') : '';
}
/** URL da miniatura (cai para a foto original se não houver miniatura). */
function fotoThumbUrl(?string $thumb, ?string $caminho): string {
    return fotoUrl($thumb ?: $caminho);
}

/** Lê uma configuração do sistema (tabela config_sistema). */
function configGet(PDO $pdo, string $chave, $padrao = null) {
    try { $s = $pdo->prepare("SELECT valor FROM config_sistema WHERE chave=?"); $s->execute([$chave]); $v = $s->fetchColumn(); return $v === false ? $padrao : $v; }
    catch (PDOException $e) { return $padrao; }
}
function configSet(PDO $pdo, string $chave, $valor): void {
    $pdo->prepare("INSERT INTO config_sistema (chave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$chave, $valor]);
}

/** Tamanho total (bytes) e quantidade de arquivos de uma pasta, recursivo. */
function pastaUso(string $dir): array {
    $bytes = 0; $qtd = 0;
    if (!is_dir($dir)) return [0, 0];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $bytes += $f->getSize(); $qtd++; } }
    return [$bytes, $qtd];
}

function bytesFmt($b): string {
    $b = (float)$b;
    if ($b >= 1073741824) return number_format($b/1073741824, 2, ',', '.') . ' GB';
    if ($b >= 1048576)    return number_format($b/1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024)       return number_format($b/1024, 0, ',', '.') . ' KB';
    return (int)$b . ' B';
}

/** Modal de visualização de fotos (lightbox com navegação), incluído uma vez por página. */
function fotosModalGaleria(): void { ?>
<div class="modal fade" id="modalGaleria" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 bg-black">
      <div class="modal-header border-0 bg-black py-2">
        <h6 class="modal-title text-white" id="galeriaTitulo"></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-1 text-center position-relative">
        <img id="galeriaImg" src="" class="img-fluid" style="max-height:78vh">
        <button type="button" class="btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2 opacity-75" id="galeriaPrev"><i class="bi bi-chevron-left"></i></button>
        <button type="button" class="btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2 opacity-75" id="galeriaNext"><i class="bi bi-chevron-right"></i></button>
        <div class="text-white-50 small py-2" id="galeriaLegenda"></div>
      </div>
    </div>
  </div>
</div>
<?php }
