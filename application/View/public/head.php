<script>(function (H) {H.className = H.className.replace(/\bno_js\b/, 'js')})(document.documentElement)</script>
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
foreach ($js as $id => $files) {
	print_scripts($files, $id);
}
?>
<link rel="icon" href="<?php echo site_url('favicon.ico'); ?>">