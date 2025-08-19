# emptybox-pdf-editor

A minimal, PHP-based PDF **overlay editor**. Render PDFs in the browser (PDF.js), draw **text**, **boxes**, and **highlights** with Fabric.js, then export a new PDF server-side (FPDI + TCPDF). Perfect for annotations, stamps, and cover-style redaction.

> This does **not** rewrite original PDF content. Overlays are drawn on top and saved into a new PDF. For true content edits or secure redaction (removing underlying text), use a commercial SDK.
> This does **not** work with protected PDF documents

---

## Features
- Drag-and-drop PDF upload
- Text / Box / Highlight tools
- Keyboard **Undo** (⌘Z / Ctrl+Z)
- Cursor changes per tool (I-beam / crosshair)
- Precise click placement (top-left of object at cursor)
- Save to new PDF (vector overlays, no rasterization)

---

## Usage
- Pick a tool: Text, Box, Highlight.
- Click the page to place an object (object’s top-left = click point).
- Undo: ⌘Z / Ctrl+Z (if you’re editing text, undo affects the text field; click outside to undo overlays).
- Save PDF: builds a new PDF and provides a download link. Also writes to storage/output/.
  
---

## Stack
- **Frontend:** PDF.js, Fabric.js
- **Backend:** PHP 8+, [TCPDF](https://github.com/tecnickcom/TCPDF), [FPDI](https://www.setasign.com/products/fpdi/about/) via `setasign/fpdi-tcpdf`

---

## Requirements
- PHP **8.0+**
- Composer
- PHP extensions: `mbstring`, `zip` (recommended), `gd` (if you add image stamps)

---


## Quick Start (Local)

```bash
# 1) Clone
git clone https://github.com/emptybox/emptybox-pdf-editor.git
cd emptybox-pdf-editor

# 2) Install PHP deps
composer install --no-interaction --prefer-dist

# 3) Ensure storage is writable
chmod -R 775 storage

# 4) Expose uploads & output (dev symlinks)
cd public
[ -L uploads ] || ln -s ../storage/uploads uploads
[ -L output  ] || ln -s ../storage/output  output

# 5) Run locally
php -S localhost:8080 -t .

FOLDER STRUCTURE
emptybox-pdf-editor/
├─ public/
│  ├─ index.php       # UI
│  ├─ app.js          # Editor logic (PDF.js + Fabric.js)
│  ├─ styles.css      # Styling + drop zone
│  ├─ upload.php      # PDF upload handler
│  ├─ save.php        # FPDI+TCPDF export
│  ├─ uploads -> ../storage/uploads   # (symlink for dev)
│  └─ output  -> ../storage/output    # (symlink for dev)
├─ storage/
│  ├─ uploads/
│  └─ output/
├─ vendor/
└─ composer.json





