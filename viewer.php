<?php
// Anteprima interattiva di un modello 3D .glb generato dall'agente 3d_gen.
// I file vivono in /models/{uuid}.glb (cleanup lazy fatto dall'agente).
// L'id è un hex 32 char (bin2hex di 16 byte random) — non guessable.

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(400);
    exit('Invalid id');
}

$file = __DIR__ . '/models/' . $id . '.glb';
if (!is_file($file)) {
    http_response_code(404);
    exit('Modello non trovato o scaduto.');
}

$src = 'models/' . $id . '.glb';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Anteprima 3D</title>
<style>
  html, body { margin: 0; padding: 0; width: 100%; height: 100%; background: #1a1a1a; overflow: hidden; }
  model-viewer {
    width: 100%;
    height: 100%;
    background: #1a1a1a;
    --progress-bar-color: #4a9eff;
  }
  .hint {
    position: fixed;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    color: #888;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 12px;
    pointer-events: none;
    user-select: none;
  }
</style>
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>
</head>
<body>
<model-viewer
  src="<?= htmlspecialchars($src, ENT_QUOTES) ?>"
  alt="Modello 3D"
  camera-controls
  touch-action="pan-y"
  auto-rotate
  auto-rotate-delay="2000"
  shadow-intensity="1"
  exposure="1.1"
  environment-image="neutral"
  ar
  ar-modes="webxr scene-viewer quick-look">
</model-viewer>
<div class="hint">trascina per ruotare · pinch per zoom</div>
</body>
</html>
