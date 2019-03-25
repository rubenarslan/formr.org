<?php
$providers = Config::get('social_share');
if (!$providers) {
    return;
}
?>

<?php
foreach ($providers as $name => $data):
    $href = strt_replace($data['url'], array('title' => $title, 'url' => $url));
    ?>
    <a href="javascript:void(0);" class="social-share-icon <?= $name ?>" data-href="<?= $href ?>" data-width="<?= $data['width'] ?>" data-height="<?= $data['height'] ?>"  data-target="<?= $data['target'] ?>">
        <i class="fa fa-<?= $name ?> fa-2x"></i>
    </a>
<?php endforeach; ?>
