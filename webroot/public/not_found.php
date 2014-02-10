<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."View/header.php";
require_once INCLUDE_ROOT."View/public_nav.php";

?>
<div class="broken_tape">
	<h1><span>Oh no! We can't find the page you're looking for (404). <a href="<?=WEBROOT?>/">Maybe take it from the start?</a></span></h1>
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
