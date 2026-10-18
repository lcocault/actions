<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?> — Actions</title>
<link rel="stylesheet" href="<?= $url('/assets/portal.css') ?>">
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>
<header class="site">
  <a class="brand" href="<?= $url('/tasks') ?>">Actions</a>
  <nav aria-label="Navigation principale">
    <a href="<?= $url('/tasks') ?>">Tâches</a>
    <a href="<?= $url('/tasks/new') ?>">Nouvelle tâche</a>
    <a href="<?= $url('/projects') ?>">Projets</a>
  </nav>
</header>
<main id="contenu">
<?php if ($flash !== null): ?>
  <div class="flash flash-<?= $e($flash['type']) ?>" role="<?= $e($flash['type'] === 'error' ? 'alert' : 'status') ?>">
    <?= $e($flash['text']) ?>
<?php if (isset($flash['link'])): ?>
    <a href="<?= $url($flash['link']['path']) ?>"><?= $e($flash['link']['label']) ?></a>
<?php endif; ?>
  </div>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
