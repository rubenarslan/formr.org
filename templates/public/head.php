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

<?php
// --- Determine Favicon and OG/Twitter Image URLs ---
$favicon_url = site_url('favicon.ico'); // Default favicon
$og_image_url = asset_url('build/img/formr-og.png'); // Default OG/Twitter image

// Check if run context exists and has PWA path set
if (isset($run) && $run instanceof Run) { 
    $run_pwa_icon_path_val = $run->getPwaIconPath();
    if ($run_pwa_icon_path_val) { // Check if path is set and not empty/null
        $webroot_pwa_path = APPLICATION_ROOT . 'webroot/' . $run_pwa_icon_path_val;
        if (is_dir($webroot_pwa_path)) {
            $pwa_icon_base_url_for_head = asset_url(trim($run_pwa_icon_path_val, '/') . '/', false);

            // Check for OG/Twitter Image (prioritize larger, then generic)
            if (file_exists($webroot_pwa_path . 'icon-512x512.png')) {
                $og_image_url = $pwa_icon_base_url_for_head . 'icon-512x512.png';
            } elseif (file_exists($webroot_pwa_path . 'icon.png')) {
                $og_image_url = $pwa_icon_base_url_for_head . 'icon.png';
            }

            // Check for Favicon (prioritize specific, then generic)
            if (file_exists($webroot_pwa_path . 'favicon.png')) {
                $favicon_url = $pwa_icon_base_url_for_head . 'favicon.png';
            } elseif (file_exists($webroot_pwa_path . 'icon.png')) {
                $favicon_url = $pwa_icon_base_url_for_head . 'icon.png';
            }
        }
    }
    // Update the $meta array for OG/Twitter tags if a custom image was found
    if ($og_image_url !== asset_url('build/img/formr-og.png')) {
        $meta['image'] = $og_image_url;
    }
}
// --- End URL Determination ---

// OG and Twitter tags will now use the potentially updated $meta['image']
?>
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
<link rel="icon" href="<?php echo $favicon_url; ?>">

<?php 
if (isset($run) && $run instanceof Run && $run->getManifestJSONPath()): 
    // Determine base path for PWA icons if not already done (or re-verify)
    $pwa_icon_base_url_for_head = asset_url('pwa/', false); // Default path
    $run_pwa_icon_path_val = $run->getPwaIconPath(); 
    if ($run_pwa_icon_path_val) { 
        $webroot_pwa_path = APPLICATION_ROOT . 'webroot/' . $run_pwa_icon_path_val;
        if (is_dir($webroot_pwa_path)) {
            $pwa_icon_base_url_for_head = asset_url(trim($run_pwa_icon_path_val, '/') . '/');
        }
    } // Note: $pwa_icon_base_url_for_head might be re-calculated here if the first check didn't run or needs update
?>
    <link rel="manifest" href="<?php echo run_url($run->name).'manifest'; ?>">
    
    <!-- Safari specific icons -->
    <link rel="apple-touch-icon" href="<?php echo $pwa_icon_base_url_for_head . 'apple-touch-icon.png'; ?>"> <!-- General, e.g. 180x180 -->
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo $pwa_icon_base_url_for_head . 'apple-touch-icon-152x152.png'; ?>">
    <link rel="apple-touch-icon" sizes="167x167" href="<?php echo $pwa_icon_base_url_for_head . 'apple-touch-icon-167x167.png'; ?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?php echo $pwa_icon_base_url_for_head . 'apple-touch-icon-192x192.png'; ?>">
    <!-- Add more apple-touch-icon sizes if provided by user, e.g., apple-touch-icon-76x76.png -->
    
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

