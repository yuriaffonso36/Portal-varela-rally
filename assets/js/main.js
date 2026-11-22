// assets/js/main.js – Varela Rally v8

// Confirmar ações destrutivas
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
});

// ─────────────────────────────────────────────────────────────
// Redução de fotos NO CELULAR antes do envio
// Qualquer <input type="file" data-reduzir> tem as imagens reduzidas
// para no máx. 1920px (JPEG 85%) antes de subir. Envio fica rápido no 4G
// e nunca estoura o limite de upload do servidor.
// ─────────────────────────────────────────────────────────────
const VR_MAX = 1920, VR_Q = 0.85;

function vrReduzirImagem(file) {
    return new Promise(resolve => {
        if (!file.type.startsWith('image/') || file.type === 'image/gif') return resolve(file);
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            const esc = Math.min(1, VR_MAX / Math.max(img.width, img.height));
            if (esc === 1 && file.size < 900 * 1024) { URL.revokeObjectURL(url); return resolve(file); }
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * esc); c.height = Math.round(img.height * esc);
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(img, 0, 0, c.width, c.height);
            c.toBlob(b => {
                URL.revokeObjectURL(url);
                if (!b || b.size >= file.size) return resolve(file);
                resolve(new File([b], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
            }, 'image/jpeg', VR_Q);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

document.querySelectorAll('input[type=file][data-reduzir]').forEach(input => {
    input.addEventListener('change', async function () {
        if (!this.files.length || typeof DataTransfer === 'undefined') { vrPreview(this); if (this.files.length && this.hasAttribute('data-autoenviar') && this.form) this.form.submit(); return; }
        const form = this.form, botoes = form ? form.querySelectorAll('[type=submit]') : [];
        botoes.forEach(b => { b.disabled = true; b.dataset.txt = b.innerHTML; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparando...'; });
        try {
            const dt = new DataTransfer();
            for (const f of this.files) dt.items.add(await vrReduzirImagem(f));
            this.files = dt.files;
        } catch (e) { /* mantém originais */ }
        botoes.forEach(b => { b.disabled = false; b.innerHTML = b.dataset.txt; });
        vrPreview(this);
        if (this.hasAttribute('data-autoenviar') && this.form) this.form.submit();
    });
});

function vrPreview(input) {
    const alvo = input.dataset.preview ? document.getElementById(input.dataset.preview) : null;
    if (!alvo) return;
    alvo.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.className = 'rounded border';
        img.style.cssText = 'height:70px;width:70px;object-fit:cover';
        alvo.appendChild(img);
    });
}

// Preview legado (upload antigo)
const inputFoto = document.getElementById('foto_input');
if (inputFoto && !inputFoto.hasAttribute('data-reduzir')) {
    inputFoto.addEventListener('change', function () {
        const preview = document.getElementById('foto_preview');
        if (!preview) return;
        preview.innerHTML = '';
        Array.from(this.files).forEach(file => {
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.createElement('img');
                img.src = e.target.result; img.className = 'img-thumbnail me-2 mb-2'; img.style.height = '100px';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    });
}

// ─────────────────────────────────────────────────────────────
// Galeria com navegação
//   <img data-galeria="grupo" data-src="grande.jpg" data-legenda="...">
//   ou abrirGaleria([{src,legenda}], indice, titulo)
// ─────────────────────────────────────────────────────────────
let vrGal = { lista: [], i: 0 };
function vrGalMostrar() {
    const it = vrGal.lista[vrGal.i]; if (!it) return;
    document.getElementById('galeriaImg').src = it.src;
    document.getElementById('galeriaLegenda').textContent = (it.legenda || '') + (vrGal.lista.length > 1 ? `  (${vrGal.i + 1}/${vrGal.lista.length})` : '');
    const multi = vrGal.lista.length > 1;
    document.getElementById('galeriaPrev').style.display = multi ? '' : 'none';
    document.getElementById('galeriaNext').style.display = multi ? '' : 'none';
}
function abrirGaleria(lista, indice, titulo) {
    const modal = document.getElementById('modalGaleria');
    if (!modal || !lista.length) return;
    vrGal = { lista, i: indice || 0 };
    document.getElementById('galeriaTitulo').textContent = titulo || '';
    vrGalMostrar();
    bootstrap.Modal.getOrCreateInstance(modal).show();
}
if (document.getElementById('modalGaleria')) {
    document.getElementById('galeriaPrev').onclick = () => { vrGal.i = (vrGal.i - 1 + vrGal.lista.length) % vrGal.lista.length; vrGalMostrar(); };
    document.getElementById('galeriaNext').onclick = () => { vrGal.i = (vrGal.i + 1) % vrGal.lista.length; vrGalMostrar(); };
    document.addEventListener('keydown', e => {
        if (!document.getElementById('modalGaleria').classList.contains('show')) return;
        if (e.key === 'ArrowLeft') document.getElementById('galeriaPrev').click();
        if (e.key === 'ArrowRight') document.getElementById('galeriaNext').click();
    });
}
document.addEventListener('click', e => {
    const el = e.target.closest('[data-galeria]');
    if (!el) return;
    e.preventDefault();
    const grupo = el.dataset.galeria;
    const itens = Array.from(document.querySelectorAll(`[data-galeria="${grupo}"]`));
    abrirGaleria(itens.map(x => ({ src: x.dataset.src || x.src, legenda: x.dataset.legenda || '' })), itens.indexOf(el), el.dataset.titulo || '');
});

// Lightbox legado (.foto-thumb)
document.querySelectorAll('.foto-thumb:not([data-galeria])').forEach(img => {
    img.addEventListener('click', function () {
        const modal = document.getElementById('modalFoto');
        if (!modal) return;
        modal.querySelector('#modalFotoImg').src = this.dataset.src || this.src;
        modal.querySelector('#modalFotoDesc').textContent = this.dataset.desc || '';
        new bootstrap.Modal(modal).show();
    });
});
