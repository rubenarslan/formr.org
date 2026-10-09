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
// --- Determine the OG/Twitter image (the favicon: public/pwa_head) ---
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
<meta name="twitter:image:alt" content="formr logo" />
<meta name="twitter:url" content="<?php echo $meta['url']; ?>" />
<meta name="twitter:description" content="<?php echo $meta['description']; ?>" />


<script>
    window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}' ?>;
<?php
    // Get VAPID public key from the run
    if (isset($run) && $run instanceof Run) {
        $vapidPublicKey = $run->getVapidPublicKey();
    } else {
        $vapidPublicKey = null;
    }
    if ($vapidPublicKey):
    ?>
    // Make VAPID public key available globally
    window.vapidPublicKey = <?php echo json_encode($vapidPublicKey); ?>;
<?php endif;
    if (isset($run_session) && $run_session instanceof RunSession && ($us = $run_session->getCurrentUnitSession())) {
        $expires = $us->expires;
    } else {
        $expires = null;
    }
    if ($expires):
        try {
            $expires = (new DateTime($expires))->format(DateTime::ATOM);
        } catch (Exception $e) {
            $expires = null;
        }
    ?>
    window.unit_session_expires = <?php echo json_encode($expires); ?>;
<?php endif; ?>
</script>


<?php Template::loadChild('public/pwa_head', array('run' => isset($run) ? $run : null)); ?>


<?php
foreach ($css as $id => $files) {
    print_stylesheets($files, $id);
}
foreach ($js as $id => $files) {
    print_scripts($files, $id);
}
?>