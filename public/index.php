<?php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>PHP PDF Editor (MVP)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="styles.css">
  <!-- PDF.js (viewer) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
  <!-- Fabric.js (overlay editing) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
</head>
<body>
  <header>
    <h1>PHP PDF Editor</h1>

    <!-- Drop Zone -->
    <div id="dropZone" class="dropzone" tabindex="0" role="button" aria-label="Drop PDF here or click to upload">
      <div>
        <strong>Drop PDF here</strong> or click to upload
        <div class="dz-hint">Max 50MB • .pdf only</div>
      </div>
      <input type="file" id="fileInput" accept="application/pdf" hidden>
    </div>

    <div class="toolbar">
      <button id="toolSelect" class="btn tools-active">Select</button>
      <button id="toolText" class="btn">Text</button>
      <button id="toolRect" class="btn">Box</button>
      <button id="toolHighlight" class="btn">Highlight</button>
      <button id="undo" class="btn">Undo</button>
      <button id="save" class="btn primary">Save PDF</button>
      <span id="status"></span>
    </div>
  </header>

  <main>
    <div id="pages"></div>
  </main>

  <script>
    window.PDF_EDITOR_CONFIG = {
      uploadUrl: 'upload.php',
      saveUrl: 'save.php'
    };
  </script>
  <script src="app.js"></script>
</body>
</html>
