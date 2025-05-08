<script>(function (H) {
        H.className = H.className.replace(/\bno_js\b/, 'js')
    })(document.documentElement)</script>
<title><?php echo $site->makeTitle(); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="description" content="<?php echo $meta['description']; ?>" />
<meta name="keywords" content="<?php echo $meta['keywords']; ?>" />
<meta name="author" content="<?php echo $meta['author']; ?>" />

<meta property="og:title" content="<?php echo $meta['title']; ?>"/>
<meta property="og:image" content="<?php echo $meta['image']; ?>"/>
<meta property="og:image:url" content="<?php echo $meta['image']; ?>"/>
<meta property="og:image:width" content="600" />
<meta property="og:image:height" content="600" />
<meta property="og:url" content="<?php echo $meta['url']; ?>"/>
<meta property="og:site_name" content="<?php echo $meta['author']; ?>"/>
<meta property="og:description" content="<?php echo $meta['description']; ?>"/>

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo $meta['title']; ?>" />
<meta name="twitter:image" content="<?php echo $meta['image']; ?>" />
<meta name="twitter:image:alt" content="formr.org logo" />
<meta name="twitter:url" content="<?php echo $meta['url']; ?>" />
<meta name="twitter:description" content="<?php echo $meta['description']; ?>" />

<?php
foreach ($css as $id => $files) {
    print_stylesheets($files, $id);
}
?>
<script>
    window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}' ?>;
</script>

<?php
foreach ($js as $id => $files) {
    print_scripts($files, $id);
}
?>
<link rel="icon" href="<?php echo site_url('favicon.ico'); ?>">

<?php 
if ($run->getManifestJSONPath()): ?>
    <link rel="manifest" href="<?php echo run_url($run->name).'manifest'; ?>">
    
    <!-- Safari specific icons -->
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x192.png'); ?>" sizes="192x192">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x512.png'); ?>" sizes="512x512">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x384.png'); ?>" sizes="384x384">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x128.png'); ?>" sizes="128x128">
    
    <!-- Safari specific meta tags -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?php echo h($run->name); ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo h($run->title ?: $run->name); ?>">
    <meta name="theme-color" content="#2196F3">
    <meta name="msapplication-navbutton-color" content="#2196F3">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="msapplication-starturl" content="<?php echo run_url($run->name); ?>">

    <?php
    // Get VAPID public key from the run
    $vapidPublicKey = $run->getVapidPublicKey();
    if ($vapidPublicKey):
    ?>
    <script>
        // Make VAPID public key available globally
        window.vapidPublicKey = <?php echo json_encode($vapidPublicKey); ?>;
    </script>
    <?php endif; ?>
    
<?php endif; ?>

