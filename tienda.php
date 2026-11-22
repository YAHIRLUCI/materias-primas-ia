<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/api_tienda.php';
$initCatalog = mp_obtener_catalogo();

$usuarioNombre = (string)($_SESSION['usuario_nombre'] ?? '');
$usuarioCorreo = (string)($_SESSION['usuario_correo'] ?? '');
$usuarioRol    = (string)($_SESSION['usuario_rol'] ?? 'cliente');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Tienda profesional de materias primas: catálogo en vivo, preórdenes, WhatsApp y asistente con voz.">
<meta name="theme-color" content="#3b1505">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌾</text></svg>">
<title>Materias Primas IA · Tienda</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f6f4f1;--panel:#ffffff;--border:#e6e1db;--soft:#f1eeea;
  --text:#1c1917;--muted:#6f6963;--faint:#a8a29e;
  --b950:#3b1505;--b900:#5c240a;--b800:#7c2d12;--b700:#9a3412;--b600:#c2410c;--b500:#ea580c;
  --b300:#fdba74;--b200:#fed7aa;--b100:#ffedd5;--b50:#fff7ed;
  --ok:#15803d;--ok-bg:#ecfdf5;--err:#b91c1c;--err-bg:#fef2f2;--warn:#a16207;
  --r:16px;--r2:12px;
  --shadow:0 1px 2px rgba(28,25,23,.05),0 8px 28px rgba(28,25,23,.07);
  --shadow-lg:0 16px 48px rgba(28,25,23,.16);
  --font:'Segoe UI',system-ui,-apple-system,'Helvetica Neue',Arial,sans-serif;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--text);line-height:1.5;-webkit-font-smoothing:antialiased}
.is-hidden{display:none!important}
.container{max-width:1140px;margin:0 auto;padding:0 18px}
button,input,select,textarea{font-family:inherit}
:focus-visible{outline:2px solid var(--b500);outline-offset:2px}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;border-radius:12px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s,transform .12s,box-shadow .15s,border-color .15s}
.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-primary{background:var(--b800);color:#fff}
.btn-primary:hover{background:var(--b900)}
.btn-accent{background:var(--b500);color:#fff}
.btn-accent:hover{background:var(--b600)}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover{background:#15803d}
.btn-outline{background:#fff;border-color:var(--border);color:var(--text)}
.btn-outline:hover{border-color:var(--b700);background:var(--b50)}
.btn-sm{padding:7px 12px;font-size:12.5px;border-radius:10px}
.btn-block{width:100%}
.btn-icon{width:48px;height:48px;padding:0;border-radius:99px}

.input,.select{width:100%;border:1px solid var(--border);border-radius:12px;padding:11px 14px;font-size:14px;background:#fff;color:var(--text)}
.input:focus,.select:focus{border-color:var(--b600);box-shadow:0 0 0 3px var(--b100);outline:none}
label{font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px}

.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px}
.pill-gray{background:var(--soft);color:var(--muted)}
.pill-warn{background:#fef3c7;color:var(--warn)}
.pill-err{background:var(--err-bg);color:var(--err)}
.pill-ok{background:var(--ok-bg);color:var(--ok)}
.chip{border:1px solid var(--border);background:#fff;border-radius:99px;padding:7px 14px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--text);transition:.15s}
.chip:hover{border-color:var(--b700);color:var(--b900)}
.chip.on{background:var(--b800);border-color:var(--b800);color:#fff}

header.top{position:sticky;top:0;z-index:40;background:rgba(59,21,5,.97);backdrop-filter:blur(12px);color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
.top-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0}
.brand{display:flex;align-items:center;gap:11px;min-width:0}
.brand .logo{width:42px;height:42px;background:linear-gradient(145deg,var(--b500),var(--b800));border-radius:12px;display:grid;place-items:center;font-size:18px;flex:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.2)}
.brand b{font-size:15.5px;display:block;letter-spacing:.2px}
.brand small{font-size:10.5px;color:var(--b200);display:block}
.top-actions{display:flex;gap:8px;align-items:center;flex:none}
#btn-cart{position:relative}
.cart-n{position:absolute;top:-7px;right:-7px;background:#dc2626;color:#fff;font-size:11px;font-weight:800;min-width:19px;height:19px;border-radius:99px;display:grid;place-items:center;padding:0 5px;border:2px solid var(--b950)}

.carousel{position:relative;background:linear-gradient(135deg,#2a0f04,#5c240a 55%,#7c2d12);color:#fff;overflow:hidden}
.slides{position:relative;height:250px}
@media(min-width:700px){.slides{height:300px}}
.slide{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:28px 56px;opacity:0;pointer-events:none;transition:opacity .55s ease}
.slide.on{opacity:1;pointer-events:auto}
.slide-bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.22}
.slide-inner{position:relative;z-index:1;max-width:660px}
.slide .tag{display:inline-block;background:rgba(234,88,12,.3);color:var(--b200);font-size:11px;font-weight:700;padding:5px 12px;border-radius:99px;margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em}
.slide h2{font-size:clamp(22px,4.2vw,34px);line-height:1.18;margin-bottom:10px;letter-spacing:-.02em}
.slide p{color:#f5e6d3;font-size:15px;margin:0 auto 20px;max-width:500px}
.car-btn{position:absolute;top:50%;transform:translateY(-50%);width:42px;height:42px;border-radius:99px;border:none;background:rgba(0,0,0,.35);color:#fff;cursor:pointer;z-index:2;font-size:20px;backdrop-filter:blur(4px)}
.car-btn:hover{background:rgba(0,0,0,.55)}
.car-btn.prev{left:14px}.car-btn.next{right:14px}
.dots{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);display:flex;gap:8px;z-index:2}
.dots button{width:8px;height:8px;border-radius:99px;border:none;background:rgba(255,255,255,.35);cursor:pointer;padding:0;transition:.2s}
.dots button.on{background:#fff;width:24px}

.cat-head{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;justify-content:space-between;padding:34px 0 8px}
.cat-head h2{font-size:22px;letter-spacing:-.02em}
.cat-head .sub{font-size:13px;color:var(--muted);margin-top:4px}
.search-wrap{position:relative;width:min(320px,100%)}
.search-wrap .ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.4;pointer-events:none}
.search-wrap .input{padding-left:40px;padding-right:42px;border-radius:99px;background:#fff}
.search-wrap .mic{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:16px;padding:6px;border-radius:99px;color:var(--muted);line-height:1}
.search-wrap .mic:hover{color:var(--b700);background:var(--b50)}
.search-wrap .mic.rec{color:#dc2626;animation:pulse 1.2s ease-in-out infinite}
#cats{display:flex;flex-wrap:wrap;gap:8px;padding:14px 0 22px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(248px,1fr));gap:18px;padding-bottom:52px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;transition:transform .18s,box-shadow .18s}
.card:hover{box-shadow:var(--shadow-lg);transform:translateY(-3px)}
.card .ph{height:112px;background:linear-gradient(145deg,#f0ebe4,#e8e2da);display:grid;place-items:center;overflow:hidden}
.card .ph img{width:100%;height:100%;object-fit:cover}
.card .ph .ph-ico{font-size:30px;opacity:.45}
.card .body{padding:16px;display:flex;flex-direction:column;gap:9px;flex:1}
.card h3{font-size:15.5px;letter-spacing:-.01em}
.card .desc{font-size:12.5px;color:var(--muted);flex:1;line-height:1.45}
.card .meta{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.empty{text-align:center;padding:56px 16px;color:var(--muted)}
.empty b{display:block;font-size:15px;color:var(--text);margin-bottom:6px}

footer.site{background:var(--b950);color:var(--b200);text-align:center;padding:36px 16px 42px;font-size:13px}
footer.site small{display:block;margin-top:12px;opacity:.5;font-size:11px}

#cart{position:fixed;top:0;right:0;bottom:0;width:min(420px,100vw);background:#fff;z-index:50;display:flex;flex-direction:column;transform:translateX(105%);transition:transform .3s cubic-bezier(.2,.8,.2,1);box-shadow:var(--shadow-lg)}
#cart.open{transform:none}
.cart-head{background:var(--b950);color:#fff;padding:16px 18px;display:flex;justify-content:space-between;align-items:center}
.cart-head button{background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1}
.cart-body{flex:1;overflow:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.line{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--soft);padding-bottom:12px}
.line b{font-size:13.5px}
.line .sub{font-size:12px;color:var(--muted);margin-top:2px}
.qty{display:inline-flex;align-items:center;gap:8px;margin-top:8px}
.qty button{width:28px;height:28px;border-radius:99px;border:1px solid var(--border);background:var(--soft);cursor:pointer}
.qty button:hover{border-color:var(--b700)}
.pre{border-top:1px solid var(--border);padding:14px 18px;background:var(--b50)}
.pre label.chk{display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:600;cursor:pointer}
.pre label.chk input{width:16px;height:16px;accent-color:var(--b700)}
.cart-foot{border-top:1px solid var(--border);padding:16px 18px;background:var(--soft);display:flex;flex-direction:column;gap:8px}
.total{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}
.total span{color:var(--muted);font-weight:600;font-size:13px}
.total b{font-size:20px;color:var(--b900)}
#overlay{position:fixed;inset:0;background:rgba(28,25,23,.42);z-index:45;backdrop-filter:blur(2px)}

#chat{position:fixed;bottom:96px;right:18px;width:min(420px,calc(100vw - 24px));height:min(560px,76vh);background:#fff;border-radius:18px;border:1px solid var(--border);box-shadow:var(--shadow-lg);z-index:50;display:flex;flex-direction:column;overflow:hidden}
.chat-head{background:var(--b950);color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.chat-head button.close{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1}
.chat-tools{display:flex;align-items:center;gap:6px}
.chat-tool{background:rgba(255,255,255,.12);border:none;color:#fff;width:34px;height:34px;border-radius:10px;cursor:pointer;font-size:15px;display:grid;place-items:center}
.chat-tool:hover{background:rgba(255,255,255,.22)}
.chat-tool.on{background:var(--b600)}
.chat-msgs{flex:1;overflow:auto;padding:14px;background:var(--bg);display:flex;flex-direction:column;gap:10px}
.bub{max-width:92%;padding:10px 13px;border-radius:16px;font-size:13.5px;line-height:1.45;word-wrap:break-word}
.bub.u{align-self:flex-end;background:var(--b900);color:#fff;border-bottom-right-radius:4px}
.bub.b{align-self:flex-start;background:#fff;border:1px solid var(--border);border-bottom-left-radius:4px}
.chat-prod-list{margin-top:8px;display:flex;flex-direction:column;gap:8px}
.chat-prod-item{background:var(--b50);border:1px solid var(--b200);border-radius:12px;padding:8px 10px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.chat-prod-item small{display:block;color:var(--muted);font-size:11.5px}
.chat-in{display:flex;gap:8px;padding:12px;border-top:1px solid var(--border);background:#fff;align-items:center}
.chat-in .input{border-radius:99px}
.chat-in .mic{flex:none;width:42px;height:42px;padding:0;border-radius:99px;border:1px solid var(--border);background:#fff;cursor:pointer;font-size:17px;transition:.15s}
.chat-in .mic:hover{border-color:var(--b700);background:var(--b50)}
.chat-in .mic.rec{background:#dc2626;border-color:#dc2626;color:#fff;animation:pulse 1.2s ease-in-out infinite}
.fab{position:fixed;bottom:22px;right:18px;z-index:40;display:flex;gap:10px}
.fab .btn{box-shadow:var(--shadow-lg)}
.fab .wa{background:#16a34a;color:#fff}
.fab .wa:hover{background:#15803d}

#toasts{position:fixed;top:72px;right:14px;z-index:90;display:flex;flex-direction:column;gap:8px;max-width:340px}
.toast{background:var(--b900);color:#fff;padding:12px 16px;border-radius:12px;font-size:13px;box-shadow:var(--shadow-lg);animation:in .22s ease}
.toast.ok{background:var(--ok)}.toast.warn{background:#b45309}.toast.err{background:var(--err)}
@keyframes in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.45)}50%{box-shadow:0 0 0 8px rgba(220,38,38,0)}}

.modal{position:fixed;inset:0;background:rgba(28,25,23,.45);z-index:80;display:grid;place-items:center;padding:16px;backdrop-filter:blur(3px)}
.modal .box{background:#fff;border-radius:18px;padding:22px;width:min(460px,100%);max-height:90vh;overflow:auto;box-shadow:var(--shadow-lg)}
.modal h3{font-size:17px;margin-bottom:6px}
.modal .actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}

@media print{body *{visibility:hidden}#modal-quote,#modal-quote *{visibility:visible}#modal-quote{position:fixed;inset:0;background:#fff;padding:12px;display:block}#modal-quote .box{width:100%;box-shadow:none;border:none;max-height:none}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}html{scroll-behavior:auto}}
</style>
</head>
<body>
<header class="top">
  <div class="container top-row">
    <div class="brand">
      <div class="logo" aria-hidden="true">🌾</div>
      <div>
        <b id="brand">Materias Primas IA</b>
        <small>Comercializadora · Almacenamiento y pedidos</small>
      </div>
    </div>
    <div class="top-actions">
      <?php if ($usuarioNombre !== ''): ?>
        <span class="badge pill-ok"><?php echo htmlspecialchars($usuarioNombre, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php endif; ?>
      <button id="btn-cart" class="btn btn-primary btn-sm" data-a="cart">🛒 Orden <span class="cart-n" id="cart-n">0</span></button>
    </div>
  </div>
</header>

<div id="toasts" aria-live="polite"></div>

<section class="carousel" aria-label="Promociones">
  <div class="slides" id="slides"></div>
  <button class="car-btn prev" data-a="slide" data-v="-1" aria-label="Anterior">‹</button>
  <button class="car-btn next" data-a="slide" data-v="1" aria-label="Siguiente">›</button>
  <div class="dots" id="dots"></div>
</section>

<main class="container">
  <div class="cat-head">
    <div>
      <h2>Catálogo de materias primas</h2>
      <p class="sub">Stock conectado con almacenamiento · Chiles, especias, harinas, aceites y repostería</p>
    </div>
    <div class="search-wrap">
      <span class="ico" aria-hidden="true">🔍</span>
      <input id="search" class="input" type="search" placeholder="Buscar producto o categoría…" aria-label="Buscar producto">
      <button id="mic-s" class="mic" data-a="smic" title="Buscar por voz" aria-label="Buscar por voz">🎤</button>
    </div>
  </div>
  <div id="cats"></div>
  <div id="grid" class="grid"></div>
  <div id="empty" class="empty is-hidden"><b>Sin resultados</b>Prueba otro nombre o categoría.</div>
</main>

<footer class="site">
  <p>Materias primas · Pedidos, preórdenes e inventario en tiempo real</p>
  <small>© 2026 <span id="foot">Materias Primas IA</span></small>
</footer>

<aside id="cart" inert>
  <div class="cart-head">
    <strong>Orden / Preorden</strong>
    <button data-a="cart" aria-label="Cerrar">×</button>
  </div>
  <div id="cart-items" class="cart-body"></div>
  <div class="pre">
    <label class="chk"><input type="checkbox" id="is-pre"> Programar como preorden</label>
    <div id="pre-panel" class="is-hidden" style="margin-top:12px;display:grid;gap:10px">
      <div><label for="pre-date">Fecha de entrega</label><input type="date" id="pre-date" class="input"></div>
      <div><label for="pre-slot">Franja</label>
        <select id="pre-slot" class="select">
          <option>Indistinto</option>
          <option>Mañana (9–13 h)</option>
          <option>Tarde (13–18 h)</option>
        </select>
      </div>
      <div><label for="pre-notes">Notas</label><input id="pre-notes" class="input" maxlength="200" placeholder="Opcional"></div>
    </div>
  </div>
  <div class="cart-foot">
    <div class="total"><span>Total estimado</span><b id="cart-total">$0.00</b></div>
    <button class="btn btn-primary btn-block" id="btn-checkout" data-a="checkout">Confirmar y guardar orden</button>
    <button class="btn btn-outline btn-block btn-sm" data-a="clearCart">Vaciar orden</button>
    <button class="btn btn-outline btn-block" data-a="quote">Generar cotización</button>
    <button class="btn btn-success btn-block" data-a="wa">Enviar por WhatsApp</button>
  </div>
</aside>
<div id="overlay" class="is-hidden" data-a="cart"></div>

<div id="chat" class="is-hidden" role="dialog" aria-label="Asistente de tienda">
  <div class="chat-head">
    <div>
      <strong>Asistente de tienda</strong>
      <div style="font-size:11px;opacity:.72;margin-top:2px">Pide una categoría (ej. repostería) · texto o voz</div>
    </div>
    <div class="chat-tools">
      <button id="tts" class="chat-tool" data-a="tts" title="Activar respuestas por voz" aria-label="Respuestas por voz" aria-pressed="false">🔇</button>
      <button class="close" data-a="chat" aria-label="Cerrar">×</button>
    </div>
  </div>
  <div id="chat-msgs" class="chat-msgs"></div>
  <div class="chat-in">
    <button class="mic" id="mic-c" data-a="mic" title="Dictar mensaje" aria-label="Dictar mensaje">🎤</button>
    <input id="chat-input" class="input" placeholder="Ej. muéstrame todo lo de repostería…" aria-label="Mensaje">
    <button class="btn btn-primary btn-sm" data-a="send">Enviar</button>
  </div>
</div>

<div class="fab">
  <button class="btn btn-primary btn-icon" data-a="chat" title="Asistente" aria-label="Abrir asistente">💬</button>
  <a class="btn btn-icon wa" id="btn-wa" href="https://wa.me/5215500000000" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp">✆</a>
</div>

<div id="modal-quote" class="modal is-hidden">
  <div class="box">
    <h3>Cotización</h3>
    <pre id="quote-text" style="white-space:pre-wrap;background:var(--soft);padding:14px;border-radius:12px;font-size:13px;margin:12px 0;border:1px solid var(--border)"></pre>
    <div class="actions">
      <button class="btn btn-primary" data-a="copyQuote">Copiar</button>
      <button class="btn btn-outline" data-a="printQuote">Imprimir</button>
      <button class="btn btn-outline" data-a="closeQuote">Cerrar</button>
    </div>
  </div>
</div>

<script>
'use strict';

const INITIAL_DATA = <?php echo json_encode($initCatalog, JSON_UNESCAPED_UNICODE); ?>;
const SESSION_USER = {
  nombre: <?php echo json_encode($usuarioNombre, JSON_UNESCAPED_UNICODE); ?>,
  correo: <?php echo json_encode($usuarioCorreo, JSON_UNESCAPED_UNICODE); ?>,
  rol: <?php echo json_encode($usuarioRol, JSON_UNESCAPED_UNICODE); ?>
};

let CATS = (INITIAL_DATA && INITIAL_DATA.cats && Object.keys(INITIAL_DATA.cats).length)
  ? INITIAL_DATA.cats
  : {especias:'Especias', chiles:'Chiles secos', reposteria:'Repostería', harinas:'Harinas', aceites:'Aceites'};

let PRODUCTS = (INITIAL_DATA && Array.isArray(INITIAL_DATA.products) && INITIAL_DATA.products.length)
  ? INITIAL_DATA.products
  : [];

const SLIDES = [
  {tag:'Materias primas', title:'Todo lo que tu cocina necesita', text:'Chiles, especias, harinas, aceites y repostería con stock conectado.', btn:'Ver catálogo', action:'catalog', img:''},
  {tag:'Preórdenes', title:'Agenda tu entrega', text:'Programa pedido con fecha, franja horaria y registro en sistema.', btn:'Armar preorden', action:'cart', img:''},
  {tag:'Asistente de tienda', title:'Consulta por categoría al instante', text:'Di "repostería", "chiles" o "especias" y te muestro todos los productos.', btn:'Abrir asistente', action:'chat', img:''}
];

const $ = id => document.getElementById(id);
const money = n => new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n)||0);
const norm = s => String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
const esc = s => String(s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const store = {
  get(k,d){ try{ const v=localStorage.getItem('mp_tienda_v2:'+k); return v?JSON.parse(v):d; }catch{ return d; } },
  set(k,v){ try{ localStorage.setItem('mp_tienda_v2:'+k, JSON.stringify(v)); }catch{} }
};

const S = {
  cat:'todos',
  slide:0,
  cart: store.get('cart', []),
  chat: store.get('chat', []),
  cfg: {empresa:'Materias Primas IA', wa:'5215500000000', aiModel:'gpt-4o-mini', dias:2, tts:false}
};

const save = () => {
  store.set('cart', S.cart);
  store.set('chat', (S.chat||[]).slice(-60));
};
const folio = () => ('COT-' + Date.now().toString(36) + Math.random().toString(36).slice(2,5)).toUpperCase();
const prod = id => PRODUCTS.find(p => Number(p.id) === Number(id));
const cartTotal = () => S.cart.reduce((a,l)=>{
  const p = prod(l.id);
  const opt = p && p.o[l.opt] ? p.o[l.opt] : null;
  return a + (opt ? Number(opt[1]) * l.q : 0);
}, 0);
const cartCount = () => S.cart.reduce((a,l)=>a+l.q, 0);

function toast(msg, type){
  const d=document.createElement('div');
  d.className='toast'+(type==='ok'?' ok':type==='warn'?' warn':type==='err'?' err':'');
  d.textContent=msg;
  $('toasts').append(d);
  setTimeout(()=>d.remove(), 3400);
}

/* Refrescar catálogo desde MySQL */
async function syncCatalogFromDB(){
  try{
    const res = await fetch('api_tienda.php', {cache:'no-store'});
    if(!res.ok) return;
    const data = await res.json();
    if(data && data.ok && data.cats && Array.isArray(data.products) && data.products.length){
      CATS = data.cats;
      PRODUCTS = data.products;
      renderCats();
      renderCatalog();
      renderCart();
    }
  }catch{}
}

/* Carrusel */
let slideTimer;
function renderSlides(){
  $('slides').innerHTML = SLIDES.map((s,i)=>`
    <div class="slide ${i===S.slide?'on':''}">
      <div class="slide-bg" style="${s.img?`background-image:url('${esc(s.img)}')`:''}"></div>
      <div class="slide-inner">
        ${s.tag?`<span class="tag">${esc(s.tag)}</span>`:''}
        <h2>${esc(s.title)}</h2>
        <p>${esc(s.text)}</p>
        <button class="btn btn-accent" data-a="${s.action}">${esc(s.btn)}</button>
      </div>
    </div>`).join('');
  $('dots').innerHTML = SLIDES.map((_,i)=>
    `<button data-a="go" data-v="${i}" class="${i===S.slide?'on':''}" aria-label="Diapositiva ${i+1}"></button>`).join('');
}
function showSlide(n){
  S.slide = (n + SLIDES.length) % SLIDES.length;
  [...$('slides').children].forEach((el,i)=>el.classList.toggle('on', i===S.slide));
  [...$('dots').children].forEach((el,i)=>el.classList.toggle('on', i===S.slide));
}
function playSlides(){
  clearInterval(slideTimer);
  slideTimer = setInterval(()=>showSlide(S.slide+1), 6500);
}

/* Catálogo */
function renderCats(){
  $('cats').innerHTML = [['todos','Todos'],...Object.entries(CATS)]
    .map(([k,l])=>`<button class="chip ${S.cat===k?'on':''}" data-a="cat" data-v="${esc(k)}">${esc(l)}</button>`).join('');
}
function renderCatalog(){
  const q = norm($('search').value.trim());
  const list = PRODUCTS.filter(p =>
    (S.cat==='todos'||String(p.c)===String(S.cat)) &&
    (!q || norm(p.n+' '+p.d+' '+(CATS[p.c]||'')).includes(q))
  );
  $('grid').innerHTML = list.map(p=>{
    const stockBadge = p.s===0
      ? '<span class="badge pill-err">Agotado</span>'
      : p.s<10
        ? `<span class="badge pill-warn">Stock: ${p.s}</span>`
        : `<span class="badge pill-ok">Stock: ${p.s}</span>`;
    const ph = p.img
      ? `<img src="${esc(p.img)}" alt="${esc(p.n)}" loading="lazy" onerror="this.remove()">`
      : `<span class="ph-ico">🌾</span>`;
    return `<article class="card">
      <div class="ph">${ph}</div>
      <div class="body">
        <div class="meta"><span class="badge pill-gray">${esc(CATS[p.c] || p.c)}</span>${stockBadge}</div>
        <h3>${esc(p.n)}</h3>
        <p class="desc">${esc(p.d)}</p>
        <select id="sel-${p.id}" class="select" aria-label="Presentación de ${esc(p.n)}">
          ${p.o.map((o,i)=>`<option value="${i}">${esc(o[0])} — ${money(o[1])}</option>`).join('')}
        </select>
        <button class="btn btn-primary btn-sm" data-a="add" data-v="${p.id}" ${p.s===0?'disabled':''}>Agregar a la orden</button>
      </div>
    </article>`;
  }).join('');
  $('empty').classList.toggle('is-hidden', list.length>0);
}

/* Carrito */
function addToCart(id, opt, q=1){
  const p = prod(id);
  if(!p || p.s <= 0){ toast('Producto agotado en almacén','warn'); return; }
  const safeOpt = (p.o && p.o[opt]) ? opt : 0;
  const l = S.cart.find(x=>Number(x.id)===Number(id) && Number(x.opt)===Number(safeOpt));
  const nextQty = (l ? l.q : 0) + q;
  if(nextQty > p.s){
    toast('Solo hay ' + p.s + ' en existencia','warn');
    return;
  }
  l ? (l.q = nextQty) : S.cart.push({id:p.id, opt:safeOpt, q});
  save(); renderCart();
}
function renderCart(){
  $('cart-n').textContent = cartCount();
  $('cart-total').textContent = money(cartTotal());
  const validLines = S.cart.filter(l => prod(l.id) && prod(l.id).o[l.opt]);
  if(validLines.length !== S.cart.length){ S.cart = validLines; save(); }
  $('cart-items').innerHTML = S.cart.length ? S.cart.map((l,i)=>{
    const p=prod(l.id), o=p.o[l.opt];
    return `<div class="line">
      <div>
        <b>${esc(p.n)}</b>
        <div class="sub">${esc(o[0])} · ${money(o[1])}</div>
        <div class="qty">
          <button data-a="qty" data-v="${i}" data-d="-1" aria-label="Menos">−</button>
          <span>${l.q}</span>
          <button data-a="qty" data-v="${i}" data-d="1" aria-label="Más">+</button>
        </div>
      </div>
      <div style="text-align:right">
        <b>${money(o[1]*l.q)}</b><br>
        <button data-a="del" data-v="${i}" style="border:0;background:none;color:var(--err);cursor:pointer;font-size:12px;margin-top:6px">Quitar</button>
      </div>
    </div>`;
  }).join('') : '<p style="text-align:center;color:var(--muted);padding:36px 8px">Tu orden está vacía.</p>';
}
function toggleCart(force){
  const open = force!==undefined ? force : !$('cart').classList.contains('open');
  $('cart').classList.toggle('open', open);
  $('cart').inert = !open;
  $('overlay').classList.toggle('is-hidden', !open);
  if(open) renderCart();
}
function orderText(title, f){
  const pre=$('is-pre').checked;
  const tot=cartTotal();
  const iva = tot - tot/1.16;
  const lines=S.cart.map((l,i)=>{const p=prod(l.id),o=p.o[l.opt];return `${i+1}. ${p.n} (${o[0]}) x${l.q} — ${money(o[1]*l.q)}`;}).join('\n');
  let t=`${title}\n${S.cfg.empresa}\nFolio: ${f||folio()}\nFecha: ${new Date().toLocaleDateString('es-MX',{day:'2-digit',month:'long',year:'numeric'})}\n\n${lines}\n\nTOTAL: ${money(tot)} MXN\nIVA incluido (16%): ${money(iva)}\n`;
  if(pre) t+=`\n★ PREORDEN\nFecha: ${$('pre-date').value||'—'}\nFranja: ${$('pre-slot').value}\nNotas: ${$('pre-notes').value||'—'}\n`;
  return t;
}
function validOrder(){
  if(!S.cart.length){ toast('Agrega al menos un producto','warn'); return false; }
  if($('is-pre').checked && !$('pre-date').value){ toast('Elige la fecha de la preorden','warn'); return false; }
  return true;
}

async function submitOrderToDB(){
  if(!validOrder()) return;
  const btn = $('btn-checkout');
  if(btn) btn.disabled = true;

  const payload = {
    es_preorden: !!$('is-pre').checked,
    fecha_entrega: $('pre-date').value || '',
    franja: $('pre-slot').value || 'Indistinto',
    notas: ($('pre-notes').value || '').trim(),
    cliente_nombre: SESSION_USER.nombre || 'Cliente Tienda',
    cliente_correo: SESSION_USER.correo || '',
    items: S.cart.map(l => {
      const p = prod(l.id);
      const o = p.o[l.opt];
      return {
        id: p.id,
        nombre: p.n,
        presentacion: o[0],
        precio: Number(o[1]),
        cantidad: Number(l.q)
      };
    })
  };

  try{
    const res = await fetch('checkout_tienda.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if(!res.ok || !data.ok){
      toast(data.error || 'No se pudo guardar la orden','err');
      return;
    }
    const f = data.folio || folio();
    $('quote-text').textContent = orderText((payload.es_preorden ? 'PREORDEN REGISTRADA' : 'ORDEN REGISTRADA') + ' · ' + S.cfg.empresa, f);
    $('modal-quote').classList.remove('is-hidden');
    S.cart = [];
    save();
    renderCart();
    await syncCatalogFromDB();
    toast('Registrada con folio ' + f, 'ok');
  }catch{
    toast('Error de conexión al guardar la orden','err');
  }finally{
    if(btn) btn.disabled = false;
  }
}

/* IA vía servidor PHP + renderizado completo de productos por categoría */
async function askAI(userText){
  const catalog = PRODUCTS.map(p=>({
    id: p.id,
    nombre: p.n,
    categoria_key: p.c,
    categoria: CATS[p.c] || p.c,
    descripcion: p.d,
    stock: p.s,
    presentaciones: p.o.map(o=>`${o[0]} = ${o[1]} MXN`)
  }));

  const cartSummary = S.cart.map(l => {
    const p = prod(l.id);
    const o = p && p.o[l.opt] ? p.o[l.opt] : null;
    return p && o ? `${p.n} (${o[0]}) x${l.q}` : null;
  }).filter(Boolean);

  const ctrl = new AbortController();
  const to = setTimeout(()=>ctrl.abort(), 22000);
  try{
    const res = await fetch('chat_ia.php', {
      method:'POST',
      signal: ctrl.signal,
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({
        mensaje: userText,
        empresa: S.cfg.empresa,
        modelo: S.cfg.aiModel,
        cats: CATS,
        catalogo,
        carrito: cartSummary
      })
    });
    clearTimeout(to);
    if(!res.ok) return null;
    const data = await res.json();
    return (data && data.ok) ? data : null;
  }catch{
    return null;
  }
}

function buildSuggestedProductsHtml(list){
  if(!Array.isArray(list) || !list.length) return '';
  const items = list.map(raw => {
    const p = prod(raw.id) || PRODUCTS.find(x => norm(x.n) === norm(raw.nombre || raw.n || ''));
    if(!p) return '';
    const presTxt = p.o.map(o => `${esc(o[0])} (${money(o[1])})`).join(' · ');
    const stkTxt = p.s > 0 ? `Stock: ${p.s}` : 'Agotado';
    const addBtn = p.s > 0
      ? `<button class="btn btn-primary btn-sm" data-a="chatAdd" data-v="${p.id}|0">+ Agregar</button>`
      : `<span class="badge pill-err">Agotado</span>`;
    return `<div class="chat-prod-item">
      <div>
        <b>${esc(p.n)}</b>
        <small>${presTxt} · ${stkTxt}</small>
      </div>
      ${addBtn}
    </div>`;
  }).filter(Boolean);
  return items.length ? `<div class="chat-prod-list">${items.join('')}</div>` : '';
}

/* Chat + voz */
function bubble(who, html){
  const b=document.createElement('div');
  b.className='bub '+(who==='u'?'u':'b');
  b.innerHTML=html;
  $('chat-msgs').append(b);
  $('chat-msgs').scrollTop=$('chat-msgs').scrollHeight;
  return b;
}
function keepChat(w,h){ S.chat.push({w,h}); save(); }
function restoreChat(){
  if($('chat-msgs').children.length) return;
  (S.chat||[]).forEach(m=>bubble(m.w,m.h));
}

const ttsOK = 'speechSynthesis' in window;
const sttOK = ('webkitSpeechRecognition' in window) || ('SpeechRecognition' in window);

function speak(text){
  if(!ttsOK || !S.cfg.tts) return;
  try{ speechSynthesis.cancel(); }catch{}
  const clean = String(text).replace(/<[^>]*>/g,' ').replace(/[*_`#>~]/g,' ').replace(/\s+/g,' ').trim();
  if(!clean) return;
  const u = new SpeechSynthesisUtterance(clean);
  u.lang='es-MX'; u.rate=1.02; u.pitch=1;
  const vs = speechSynthesis.getVoices();
  const v = vs.find(x=>/es[-_]MX/i.test(x.lang)) || vs.find(x=>/^es/i.test(x.lang));
  if(v) u.voice=v;
  speechSynthesis.speak(u);
}
function updateTtsBtn(){
  const btn=$('tts');
  if(!ttsOK){ btn.style.display='none'; return; }
  const on=!!S.cfg.tts;
  btn.textContent = on ? '🔊' : '🔇';
  btn.classList.toggle('on', on);
  btn.title = on ? 'Desactivar respuestas por voz' : 'Activar respuestas por voz';
  btn.setAttribute('aria-pressed', String(on));
}

let recog=null, recMode=null;
function setMicUI(rec){
  const mc=$('mic-c'), ms=$('mic-s');
  if(mc){ mc.classList.toggle('rec', rec && recMode==='chat'); mc.textContent = (rec&&recMode==='chat') ? '⏹' : '🎤'; }
  if(ms){ ms.classList.toggle('rec', rec && recMode==='search'); }
  if(recMode==='chat' && $('chat-input'))
    $('chat-input').placeholder = rec ? 'Escuchando… di tu pregunta' : 'Ej. muéstrame todo lo de repostería…';
}
function stopDictation(){
  if(recog){ try{ recog.stop(); }catch{} recog=null; }
  setMicUI(false);
}
function startDictation(mode){
  if(!sttOK){
    toast('Tu navegador no soporta dictado. Usa Chrome, Edge o Safari.','warn');
    return;
  }
  if(recog){ stopDictation(); return; }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const r = new SR();
  recog=r; recMode=mode;
  r.lang='es-MX';
  r.interimResults=true;
  r.maxAlternatives=1;
  r.continuous=false;
  let finalTxt='';
  r.onresult = e => {
    let inter='';
    for(const res of e.results){
      if(res.isFinal) finalTxt += res[0].transcript;
      else inter += res[0].transcript;
    }
    const txt=(finalTxt+inter).trim();
    if(mode==='chat') $('chat-input').value=txt;
    else { $('search').value=txt; renderCatalog(); }
  };
  r.onend = () => {
    recog=null; setMicUI(false);
    if(mode==='chat' && finalTxt.trim()) setTimeout(()=>sendChat(), 180);
  };
  r.onerror = ev => {
    recog=null; setMicUI(false);
    if(ev.error==='not-allowed') toast('Permite el micrófono en el navegador.','warn');
    else if(ev.error!=='aborted') toast('No se detectó voz. Intenta de nuevo.','warn');
  };
  try{
    r.start();
    setMicUI(true);
    toast('Escuchando… habla ahora','ok');
  }catch{
    recog=null; setMicUI(false);
    toast('No se pudo iniciar el micrófono.','warn');
  }
}

async function sendChat(text){
  const input=$('chat-input');
  const txt=(text ?? input.value).trim();
  if(!txt) return;
  input.value='';
  bubble('u', esc(txt));
  keepChat('u', esc(txt));
  const b = bubble('b', '<span style="opacity:.6">Consultando catálogo e IA…</span>');

  let html='', plain='';
  const remote = await askAI(txt);
  if(remote && remote.reply){
    const cardsHtml = buildSuggestedProductsHtml(remote.productos_sugeridos || []);
    html = esc(remote.reply).replace(/\n/g,'<br>') + cardsHtml;
    plain = remote.reply;
    if(remote.category_key && CATS[remote.category_key]){
      S.cat = remote.category_key;
      renderCats();
      renderCatalog();
    }
  }else{
    const t=norm(txt);
    const catEntry = Object.entries(CATS).find(([k,l]) => t.includes(norm(k)) || t.includes(norm(l)));
    const catHits = catEntry ? PRODUCTS.filter(p => String(p.c) === String(catEntry[0])) : [];
    const hit = !catHits.length ? PRODUCTS.find(p=>norm(p.n+' '+p.d).split(/\s+/).some(w=>w.length>3&&t.includes(w))) : null;

    if(/hola|buenas|buenos dias|buenas tardes|ayuda|hey/.test(t)){
      html='¡Hola! Pídeme una categoría como <b>repostería</b>, <b>chiles</b> o <b>especias</b> y te muestro todos sus productos.';
      plain=html.replace(/<[^>]*>/g,'');
    }else if(/carrito|orden|total|cuanto llevo/.test(t)){
      html=S.cart.length?`Llevas <b>${cartCount()}</b> artículos por <b>${money(cartTotal())}</b>.`:'Tu orden está vacía por ahora.';
      plain=html.replace(/<[^>]*>/g,'');
    }else if(catHits.length){
      html = `Productos en <b>${esc(catEntry[1])}</b> (${catHits.length}):` + buildSuggestedProductsHtml(catHits);
      plain = catHits.map(p => p.n).join(', ');
    }else if(hit){
      html = `<b>${esc(hit.n)}</b> · ${hit.s?hit.s+' en stock':'agotado'}` + buildSuggestedProductsHtml([hit]);
      plain = hit.n;
    }else{
      html='No encontré eso en el catálogo. Prueba con <b>repostería</b>, <b>chiles</b>, <b>especias</b>, <b>harinas</b> o <b>aceites</b>.';
      plain='No encontré eso en el catálogo.';
    }
  }
  b.innerHTML=html;
  $('chat-msgs').scrollTop=$('chat-msgs').scrollHeight;
  keepChat('b', html);
  speak(plain || html.replace(/<[^>]*>/g,' '));
}

/* Eventos */
document.addEventListener('click', e=>{
  const el=e.target.closest('[data-a]'); if(!el) return;
  const a=el.dataset.a, v=el.dataset.v, d=el.dataset.d;

  if(a==='cat'){ S.cat=v; renderCats(); renderCatalog(); }
  if(a==='add'){ const p=prod(+v); if(!p) return; addToCart(p.id, +$('sel-'+p.id).value); toast(p.n+' agregado','ok'); }
  if(a==='chatAdd'){ const [id,opt]=v.split('|').map(Number); addToCart(id,opt); toast('Agregado a la orden','ok'); }
  if(a==='qty'){
    const l=S.cart[+v]; if(!l) return;
    const p=prod(l.id);
    const next = l.q + (+d);
    if(next > (p ? p.s : 9999)){ toast('Stock máximo alcanzado en almacén','warn'); return; }
    l.q = next;
    if(l.q<=0) S.cart.splice(+v,1);
    save(); renderCart();
  }
  if(a==='del'){ S.cart.splice(+v,1); save(); renderCart(); }
  if(a==='clearCart'){ S.cart=[]; save(); renderCart(); toast('Orden vaciada','ok'); }
  if(a==='cart') toggleCart();
  if(a==='catalog') window.scrollTo({top: document.querySelector('main').offsetTop-60, behavior:'smooth'});
  if(a==='chat'){
    const box=$('chat');
    const opening=box.classList.contains('is-hidden');
    box.classList.toggle('is-hidden');
    if(opening){
      restoreChat();
      if(!$('chat-msgs').children.length)
        bubble('b','Hola. Pídeme una categoría como <b>repostería</b>, <b>chiles</b> o <b>especias</b> para ver todos sus productos y agregarlos directo.');
      setTimeout(()=>$('chat-input').focus(), 80);
    }else{
      stopDictation();
      try{ speechSynthesis.cancel(); }catch{}
    }
  }
  if(a==='send') sendChat();
  if(a==='mic') startDictation('chat');
  if(a==='smic') startDictation('search');
  if(a==='tts'){
    if(!ttsOK){ toast('Este navegador no soporta voz alta.','warn'); return; }
    S.cfg.tts = !S.cfg.tts;
    updateTtsBtn();
    if(S.cfg.tts){
      toast('Respuestas por voz activadas','ok');
      speak('Respuestas por voz activadas.');
    }else{
      try{ speechSynthesis.cancel(); }catch{}
      toast('Voz desactivada','ok');
    }
  }
  if(a==='slide'){ showSlide(S.slide + +v); playSlides(); }
  if(a==='go'){ showSlide(+v); playSlides(); }
  if(a==='checkout') submitOrderToDB();
  if(a==='quote'){
    if(!validOrder()) return;
    $('quote-text').textContent = orderText('COTIZACIÓN · '+S.cfg.empresa);
    $('modal-quote').classList.remove('is-hidden');
  }
  if(a==='closeQuote') $('modal-quote').classList.add('is-hidden');
  if(a==='copyQuote') navigator.clipboard.writeText($('quote-text').textContent).then(()=>toast('Copiado','ok'), ()=>toast('No se pudo copiar','warn'));
  if(a==='printQuote') window.print();
  if(a==='wa'){
    if(!validOrder()) return;
    window.open('https://wa.me/'+S.cfg.wa+'?text='+encodeURIComponent(orderText('Orden · '+S.cfg.empresa)),'_blank','noopener');
    toast('Abriendo WhatsApp','ok');
  }
});

$('search').addEventListener('input', ()=>renderCatalog());
$('is-pre').addEventListener('change', e=>{
  $('pre-panel').classList.toggle('is-hidden', !e.target.checked);
  if(e.target.checked){
    const min=new Date(Date.now()+(S.cfg.dias||2)*864e5).toISOString().slice(0,10);
    $('pre-date').min=min; $('pre-date').value=min;
  }
});
$('chat-input').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); sendChat(); } });
document.addEventListener('keydown', e=>{
  if(e.key==='Escape'){
    toggleCart(false);
    $('modal-quote').classList.add('is-hidden');
    stopDictation();
  }
});
document.querySelector('.carousel').addEventListener('mouseenter', ()=>clearInterval(slideTimer));
document.querySelector('.carousel').addEventListener('mouseleave', playSlides);
if(ttsOK) window.speechSynthesis.onvoiceschanged = ()=>{};

function applyBrand(){
  $('brand').textContent = S.cfg.empresa;
  $('foot').textContent = S.cfg.empresa;
  document.title = S.cfg.empresa + ' · Tienda';
  $('btn-wa').href = 'https://wa.me/' + S.cfg.wa;
}

applyBrand();
updateTtsBtn();
renderSlides(); showSlide(0); playSlides();
renderCats(); renderCatalog(); renderCart();
syncCatalogFromDB();
</script>
</body>
</html>