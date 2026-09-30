<?php
/**
 * A run's icons and PWA head: the favicon, and for a run with a manifest the
 * manifest link, Apple touch icons and standalone metas. Shared by
 * templates/public/head.php (v1) and templates/run/form_index.php (form_v2).
 * Expects $run (a Run, or unset outside a run).
 */
$pwaRun = (isset($run) && $run instanceof Run) ? $run : null;
$pwaIconDir = $pwaIconBaseUrl = null;
if ($pwaRun && ($pwaIconPath = $pwaRun->getPwaIconPath()) && is_dir(APPLICATION_ROOT . 'webroot/' . $pwaIconPath)) {
    $pwaIconDir = APPLICATION_ROOT . 'webroot/' . $pwaIconPath;
    $pwaIconBaseUrl = asset_url(trim($pwaIconPath, '/') . '/', false);
}
$pwaFaviconUrl = site_url('favicon.ico');
if ($pwaIconDir && file_exists($pwaIconDir . 'favicon.png')) {
    $pwaFaviconUrl = $pwaIconBaseUrl . 'favicon.png';
} elseif ($pwaIconDir && file_exists($pwaIconDir . 'icon.png')) {
    $pwaFaviconUrl = $pwaIconBaseUrl . 'icon.png';
}
?>
<link rel="icon" href="<?php echo h($pwaFaviconUrl); ?>">
<?php if ($pwaRun && $pwaRun->getManifestJSONPath()):
    // Personalize the manifest URL with ?code=<participant_session> when
    // there's an active RunSession for this request. iOS captures
    // start_url at PWA install time and never re-fetches the manifest, so
    // the icon URL embeds whatever start_url was personalized at the
    // moment of "Add to Home Screen". Without ?code= here, the captured
    // start_url is the clean public one, leaving the cookie as the only
    // session recovery — which iOS evicts via ITP / storage pressure.
    $pwaManifestUrl = run_url($pwaRun->name) . 'manifest';
    if (isset($pwaRun->activeRunSession) && $pwaRun->activeRunSession instanceof RunSession
            && $pwaRun->activeRunSession->id && $pwaRun->activeRunSession->session) {
        $pwaManifestUrl .= '?code=' . urlencode($pwaRun->activeRunSession->session);
    }
?>
<link rel="manifest" href="<?php echo h($pwaManifestUrl); ?>">
<?php if ($pwaIconBaseUrl): ?>
<link rel="apple-touch-icon" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon.png'; ?>">
<link rel="apple-touch-icon" sizes="152x152" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-152x152.png'; ?>">
<link rel="apple-touch-icon" sizes="167x167" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-167x167.png'; ?>">
<link rel="apple-touch-icon" sizes="192x192" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-192x192.png'; ?>">
<?php else: ?>
<?php // No custom icon set uploaded — the default icon the manifest uses, so
      // iOS "Add to Home Screen" gets an app icon instead of a page snapshot. ?>
<link rel="apple-touch-icon" href="<?php echo asset_url('pwa/icon.png', false); ?>">
<?php endif; ?>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="application-name" content="<?php echo h($pwaRun->name); ?>">
<meta name="apple-mobile-web-app-title" content="<?php echo h($pwaRun->title ?: $pwaRun->name); ?>">
<meta name="theme-color" content="#2196F3">
<meta name="msapplication-navbutton-color" content="#2196F3">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="msapplication-starturl" content="<?php echo run_url($pwaRun->name); ?>">
<?php endif; ?>
