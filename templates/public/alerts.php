<?php
if (!isset($alerts)) {
    $alerts = $site->renderAlerts();
}
if (!empty($alerts)):
    ?>
    <div class="render-alerts">
        <?php echo $alerts; ?>
    </div>
<?php endif; ?>
