<?php 
	$providers = Config::get('social_share');
	if (!$providers) {
		return;
	}
?>
<div class="social-share">
	<div class="social-share-icon share">share</div>
	<?php 
		foreach ($providers as $name => $data):
			$href = strt_replace($data['url'], array('title' => $title, 'url' => $url));
	?>
		<a href="javascript:void(0);" class="social-share-icon <?= $name ?>" data-href="<?= $href ?>" data-width="<?= $data['width'] ?>" data-height="<?= $data['height'] ?>"  data-target="<?= $data['target'] ?>"></a>
	<?php endforeach; ?>
</div>
