<!DOCTYPE html>
<html>
	<head>
		<meta http-equiv="Content-type" content="text/html; charset=utf-8">
		<title><?php echo $title; ?> - formr.org</title>
		<style type="text/css" media="screen">
			body { background-color: #f1f1f1;  margin: 0; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; }
			.container { margin: 50px auto 40px auto; width: 600px; text-align: center; }
			a { color: #4183c4; text-decoration: none; }
			a:hover { text-decoration: underline; }
			h1 { width: 800px; position:relative; left: -100px; letter-spacing: -1px; line-height: 60px; font-size: 60px; font-weight: 100; margin: 0px 0 50px 0; text-shadow: 0 1px 0 #fff; }
			p { color: rgba(0, 0, 0, 0.5); margin: 20px 0; line-height: 1.6; }
			button {display: none;}
		</style>
		<link rel="icon" href="<?php echo site_url('favicon.ico'); ?>">
	</head>
	<body>
		<div class="container">
			<h1><?php echo $code; ?></h1>
			<p><strong><?php echo $title; ?></strong></p>

			<p><?php echo $text; ?></p>
			<?php if ($link): ?>
				<p class="back"> <a href="<?= $link ?>">Go to Site</a> </p>
			<?php endif; ?>
		</div>
		<div>
			<?php echo !empty($site) ? $site->renderAlerts() : null; ?>
		</div>
	</body>
</html>

