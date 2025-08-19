// public/app.js
const pdfjsLib = window['pdfjs-dist/build/pdf'];
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

const fileInput = document.getElementById('fileInput');
const dropZone = document.getElementById('dropZone');
const pagesEl = document.getElementById('pages');
const statusEl = document.getElementById('status');

const btnSelect = document.getElementById('toolSelect');
const btnText = document.getElementById('toolText');
const btnRect = document.getElementById('toolRect');
const btnHighlight = document.getElementById('toolHighlight');
const btnUndo = document.getElementById('undo');
const btnSave = document.getElementById('save');

let currentTool = 'select';
let pdfUrl = null;
let doc = null;
let pageCount = 0;

const overlays = {};   // pageNumber -> fabric.Canvas
const pageDims = {};   // pageNumber -> { canvasWidth, canvasHeight }
const historyStack = []; // crude global undo

const origRender = fabric.Text.prototype._renderChar;
fabric.Text.prototype._renderChar = function(method, ctx, lineIndex, charIndex, _char, left, top) {
  if (ctx.textBaseline === 'alphabetical') {
    ctx.textBaseline = 'alphabetic';
  }
  return origRender.call(this, method, ctx, lineIndex, charIndex, _char, left, top);
};

function setStatus(msg) { statusEl.textContent = msg || ''; }
function setTool(t) {
  currentTool = t;
  [btnSelect,btnText,btnRect,btnHighlight].forEach(b=>b.classList.remove('tools-active'));
  ({select:btnSelect,text:btnText,rect:btnRect,highlight:btnHighlight}[t]).classList.add('tools-active');
  Object.values(overlays).forEach(cv => {
    cv.isDrawingMode = false;
    cv.selection = (t === 'select');
    cv.discardActiveObject();
    cv.requestRenderAll();
  
    // change cursor depending on tool
    switch (t) {
      case 'text':
        cv.defaultCursor = 'text';     // I-beam
        break;
      case 'rect':
      case 'highlight':
        cv.defaultCursor = 'crosshair'; // crosshair for drawing shapes
        break;
      default:
        cv.defaultCursor = 'default';   // normal arrow
    }
  });
  
  Object.values(overlays).forEach(cv => {
    const wrap = cv.lowerCanvasEl.parentNode; // .canvas-container
    wrap.classList.remove('text-tool','rect-tool','highlight-tool');
    if (t !== 'select') wrap.classList.add(t + '-tool');
  });
}
btnSelect.onclick = () => setTool('select');
btnText.onclick = () => setTool('text');
btnRect.onclick = () => setTool('rect');
btnHighlight.onclick = () => setTool('highlight');

btnUndo.onclick = () => {
  const last = historyStack.pop();
  if (!last) return;
  const { page, objId } = last;
  const cv = overlays[page];
  const obj = cv.getObjects().find(o => o.__id === objId);
  if (obj) { cv.remove(obj); cv.requestRenderAll(); }
};

function isPdfFile(file){ return file && file.type === 'application/pdf'; }

async function handleFile(file){
  if (!isPdfFile(file)) { setStatus('Invalid file. PDF only.'); return; }
  setStatus('Uploading…');
  const fd = new FormData();
  fd.append('pdf', file);
  const res = await fetch(window.PDF_EDITOR_CONFIG.uploadUrl, { method:'POST', body:fd });
  const data = await res.json().catch(()=>({}));
  if (!res.ok || !data.ok) { setStatus((data && data.error) || 'Upload failed'); return; }
  pdfUrl = data.url;
  setStatus('Rendering…');
  await loadPdf(pdfUrl);
  setStatus('Ready');
}

// Dropzone interactions
;['dragenter','dragover'].forEach(evt=>{
  dropZone.addEventListener(evt, (e)=>{ e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dragover'); });
});
;['dragleave','drop'].forEach(evt=>{
  dropZone.addEventListener(evt, (e)=>{ e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dragover'); });
});
dropZone.addEventListener('drop', (e)=>{
  const file = e.dataTransfer.files && e.dataTransfer.files[0];
  if (file) handleFile(file);
});
dropZone.addEventListener('click', ()=> fileInput.click());
dropZone.addEventListener('keydown', (e)=>{ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
fileInput.addEventListener('change', (e)=>{ if (e.target.files.length) handleFile(e.target.files[0]); });

async function loadPdf(url) {
  pagesEl.innerHTML = '';
  doc = await pdfjsLib.getDocument(url).promise;
  pageCount = doc.numPages;
  overlaysClear();
  for (let i=1; i<=pageCount; i++) { await renderPage(i); }
  setTool('select');
}
function overlaysClear(){ Object.keys(overlays).forEach(k => delete overlays[k]); }

async function renderPage(pageNum) {
  const page = await doc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.5 });
  const wrap = document.createElement('div');
  wrap.className = 'page-wrap';

  const c = document.createElement('canvas');
  c.className = 'pdf-canvas';
  c.width = viewport.width;
  c.height = viewport.height;

  const overlayEl = document.createElement('canvas');
  overlayEl.className = 'overlay';
  overlayEl.width  = Math.round(viewport.width);
  overlayEl.height = Math.round(viewport.height);
  overlayEl.style.width  = overlayEl.width + 'px';
  overlayEl.style.height = overlayEl.height + 'px';

  wrap.style.width = viewport.width + 'px';
  wrap.style.height = viewport.height + 'px';
  wrap.appendChild(c);
  wrap.appendChild(overlayEl);
  pagesEl.appendChild(wrap);

  const ctx = c.getContext('2d');
  await page.render({ canvasContext: ctx, viewport }).promise;

  const cv = new fabric.Canvas(overlayEl, {
    selection: true,
    preserveObjectStacking: true,
    enableRetinaScaling: true
  });
  overlayEl.style.pointerEvents = 'auto';
  cv.upperCanvasEl.addEventListener('mousedown', () => console.log('upper mousedown on page', pageNum));


  const container = cv.getElement().parentNode; // .canvas-container
  container.style.position = 'absolute';
  container.style.inset = '0';
  container.style.zIndex = '3';
  container.style.pointerEvents = 'auto';
  
  cv.upperCanvasEl.setAttribute('tabindex', '0');
  cv.upperCanvasEl.style.pointerEvents = 'auto';
  
  overlays[pageNum] = cv;
  pageDims[pageNum] = { canvasWidth: viewport.width, canvasHeight: viewport.height };

  cv.on('mouse:down', (opt) => {
    const p = cv.getPointer(opt.e);
  
    if (currentTool === 'text') {
      const it = new fabric.IText('Type here', {
        left: p.x,
        top: p.y,
        originX: 'left',
        originY: 'top',
        fontSize: 18,
        fill: '#000000',
        backgroundColor: 'rgba(255,255,0,0.15)'
      });
      addObject(cv, it, pageNum);
      it.enterEditing();
      if (it.hiddenTextarea) it.hiddenTextarea.focus();
      cv.requestRenderAll();
      return;
    }
  
    if (currentTool === 'rect' || currentTool === 'highlight') {
      const rect = new fabric.Rect({
        left: p.x,
        top: p.y,
        originX: 'left',
        originY: 'top',
        width: 80,
        height: 36,
        fill: currentTool === 'highlight' ? 'rgba(255,255,0,0.3)' : 'rgba(0,0,0,0)',
        stroke: currentTool === 'highlight' ? 'rgba(255,255,0,0.6)' : '#ff3b3b',
        strokeWidth: currentTool === 'highlight' ? 0 : 2,
        selectable: true
      });
      addObject(cv, rect, pageNum);
    }
  });

}
function addObject(cv, obj, pageNum){
  obj.__id = 'o' + Math.random().toString(36).slice(2);
  cv.add(obj);
  cv.setActiveObject(obj);
  cv.requestRenderAll();
  historyStack.push({ page: pageNum, objId: obj.__id });
}
function colorToRgba(c) {
  if (!c) return {r:255,g:255,b:255,a:1};
  if (typeof c === 'string') {
    if (c.startsWith('rgba')) { const [r,g,b,a] = c.match(/[\d.]+/g).map(Number); return {r,g,b,a}; }
    if (c.startsWith('rgb'))  { const [r,g,b] = c.match(/[\d.]+/g).map(Number); return {r,g,b,a:1}; }
    if (c[0] === '#') {
      let hex = c.slice(1); if (hex.length===3) hex = hex.split('').map(h=>h+h).join('');
      const r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
      return {r,g,b,a:1};
    }
  }
  return {r:255,g:255,b:255,a:1};
}

btnSave.onclick = async () => {
  if (!pdfUrl) { setStatus('No PDF loaded'); return; }
  setStatus('Saving…');

  // Commit any in-progress edits so text value is captured
  Object.values(overlays).forEach(cv => {
    cv.getObjects().forEach(o => {
      if (o.type === 'i-text' && o.isEditing) o.exitEditing();
    });
  });

  const edits = [];
  for (let p = 1; p <= pageCount; p++) {
    const cv = overlays[p];
    const dims = pageDims[p];
    if (!cv || !dims) continue;

    const pageOps = [];
    cv.getObjects().forEach(o => {
      // ignore invisible/zero-size artifacts
      if (o.visible === false) return;

      if (o.type === 'i-text') {
        const fill = colorToRgba(o.fill);
        const text = (o.text || '').toString();
        if (text.length === 0) return;
        pageOps.push({
          type: 'text',
          left: o.left,
          top: o.top,
          fontSize: o.fontSize,
          text: text,
          color: fill
        });
      } else if (o.type === 'rect') {
        const stroke = colorToRgba(o.stroke);
        const fill = colorToRgba(o.fill);
        const w = (o.width || 0) * (o.scaleX || 1);
        const h = (o.height || 0) * (o.scaleY || 1);
        if (w <= 0 || h <= 0) return;
        pageOps.push({
          type: (fill.a > 0 && fill.r === 255 && fill.g === 255 && fill.b === 0) ? 'highlight' : 'rect',
          left: o.left,
          top: o.top,
          width: w,
          height: h,
          stroke,
          strokeWidth: o.strokeWidth || 0,
          fill
        });
      }
    });

    edits.push({
      page: p,
      canvasWidth: dims.canvasWidth,
      canvasHeight: dims.canvasHeight,
      ops: pageOps
    });
  }

  try {
    const res = await fetch(window.PDF_EDITOR_CONFIG.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pdfUrl, edits })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      console.error('Save error payload:', data);
      setStatus((data && data.error) || 'Save failed (server error)');
      return;
    }
    setStatus('Saved ✓');
    const a = document.createElement('a');
    a.href = data.url;
    a.download = '';
    a.click();
  } catch (err) {
    console.error(err);
    setStatus('Save failed (network error)');
  }
};


function anyTextEditing() {
  return Object.values(overlays).some(cv => {
    const ao = cv.getActiveObject();
    return ao && ao.type === 'i-text' && ao.isEditing;
  });
}

document.addEventListener('keydown', (e) => {
  // Cmd+Z (mac) or Ctrl+Z (win/linux), without Shift (no redo here)
  const isUndoCombo = (e.key === 'z' || e.key === 'Z') && (e.metaKey || e.ctrlKey) && !e.shiftKey;
  if (!isUndoCombo) return;

  // If currently editing a text box, let Fabric handle text undo (don’t hijack)
  if (anyTextEditing()) return;

  e.preventDefault();
  // Reuse your existing undo button / logic
  btnUndo.click();
});

