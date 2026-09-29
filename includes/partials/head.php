<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

$title = isset($pageTitle) ? (string) $pageTitle : APP_NAME;

/* ============================================================
   Design Phase — Modular 3-link CSS chain (SDD §3.1):
   1. variables.css  (tokens / @font-face / colors)
   2. common.css     (12 shared primitives max)
   3. page-level.css (one per renderable PHP page, strict 1:1)
   SINGLE MONOLITHIC app.css REMOVED PER SDD §6.4 CHECKLIST #1.
   ============================================================ */
$_scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$_base = basename((string) $_scriptName, '.php');
$_isAdmin = (str_contains(strtr($_scriptName, '\\', '/'), '/admin/') !== false);
$_cssFolder = $_isAdmin ? 'admin' : 'public';
$_pageCssPath = "/assets/css/{$_cssFolder}/{$_base}.css";
$_cssFsPath = __DIR__ . '/../../assets/css/' . $_cssFolder . '/' . $_base . '.css';
$_pageCssVersion = @filemtime($_cssFsPath);
$_pageCssUrl = app_url($_pageCssPath);
if ($_pageCssVersion !== false) {
    $_pageCssUrl .= '?v=' . $_pageCssVersion;
}

$_varsFsPath = __DIR__ . '/../../assets/css/variables.css';
$_varsVersion = @filemtime($_varsFsPath);
$_varsUrl = app_url('/assets/css/variables.css');
if ($_varsVersion !== false) {
    $_varsUrl .= '?v=' . $_varsVersion;
}

$_commonFsPath = __DIR__ . '/../../assets/css/common.css';
$_commonVersion = @filemtime($_commonFsPath);
$_commonUrl = app_url('/assets/css/common.css');
if ($_commonVersion !== false) {
    $_commonUrl .= '?v=' . $_commonVersion;
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1F4570">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="E-Concern">
    <?= csrf_header_meta() ?>
    <title><?= e($title) ?></title>
    <?php /* Served via PHP, not as a static .webmanifest: InfinityFree's bot
            filter answers some subresource requests with an HTML challenge page,
            and Chrome then fails with "Manifest: Line 1, column 1, Syntax error",
            which blocks PWA installability. manifest.php is the canonical source. */ ?>
    <link rel="manifest" href="<?= e(app_url('/manifest.php')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(app_url('/assets/icons/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(app_url('/assets/icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(app_url('/assets/icons/apple-touch-icon.png')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($_varsUrl) ?>" rel="stylesheet">
    <link href="<?= e($_commonUrl) ?>" rel="stylesheet">
    <link href="<?= e($_pageCssUrl) ?>" rel="stylesheet">
</head>
<body class="bg-light">
