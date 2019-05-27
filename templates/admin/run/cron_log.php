<?php
Template::loadChild('header');
Template::loadChild('acp_nav');
?>	
<h2>cron log</h2>
<p>
    The cron job runs every x minutes, to evaluate whether somebody needs to be sent a mail. This usually happens if a pause is over. It will then skip forward or backward, send emails and shuffle participants, but will stop at surveys and pages, because those should be viewed by the user.
</p>


<?php if (!empty($cronlogs)) { ?>
    <table class='table table-striped table-bordered'>
        <thead><tr>
                <?php
                foreach (current($cronlogs) AS $field => $value):
                    if ($field == 'skipbackwards')
                        $field = '<i class="fa fa-backward" title="SkipBackward"></i>';
                    elseif ($field == 'skipforwards')
                        $field = '<i class="fa fa-forward" title="SkipForward"></i>';
                    elseif ($field == 'pauses')
                        $field = '<i class="fa fa-pause" title="Pause"></i>';
                    elseif ($field == 'emails')
                        $field = '<i class="fa fa-envelope" title="Emails attempted to send"></i>';
                    elseif ($field == 'shuffles')
                        $field = '<i class="fa fa-random" title="Shuffle"></i>';
                    elseif ($field == 'sessions')
                        $field = '<i class="fa fa-users" title="User sessions"></i>';
                    elseif ($field == 'errors')
                        $field = '<i class="fa fa-bolt" title="Errors that occurred"></i>';
                    elseif ($field == 'warnings')
                        $field = '<i class="fa fa-exclamation-triangle" title="Warnings that occurred"></i>';
                    elseif ($field == 'notices')
                        $field = '<i class="fa fa-info-circle" title="Notices that occurred"></i>';

                    echo "<th>{$field}</th>";
                endforeach;
                ?>
            </tr></thead>
        <tbody>
            <?php
            $tr_class = '';

            // printing table rows
            foreach ($cronlogs AS $row):
                foreach ($row as $cell):
                    echo "<td>$cell</td>";
                endforeach;

                echo "</tr>\n";
            endforeach;
            ?>

        </tbody></table>
    <?php
    $pagination->render("admin/run/" . $run->name . "/cron_log");
} else {
    echo "No cron jobs yet. Maybe you disabled them in the <a href='" . WEBROOT . "admin/run/" . $run->name . "/settings'>settings</a>.";
}
?>
</div>
</div>

<?php
Template::loadChild('footer');
