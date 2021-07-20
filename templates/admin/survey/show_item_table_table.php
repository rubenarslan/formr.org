<div class="table-responsive no-padding">
    <table class='table table-striped'>
        <thead>
            <tr>
                <?php
                $use_columns = $empty_columns = array();
                $display_columns = array('type', 'name', 'label', 'class', 'showif', 'choices', 'value', 'block_order', 'item_order');
                foreach (current($results) AS $field => $value):
                    if (in_array($field, $display_columns) AND ! empty_column($field, $results)):
                        array_push($use_columns, $field);
                        echo "<th>{$field}</th>";
                    endif;
                endforeach;
                ?>
            </tr>
        </thead>
        <tbody>
            <!-- Item Rows -->
            <?php
            $open = false;
            foreach ($results AS $row):
                ?>
                <tr>
                    <?php
                    // $row is array... foreach( .. ) puts every element
                    // of $row to $cell variable
                    $row->type = implode(" ", array('<b>' . $row->type . '</b>', ($row->choice_list == $row->name) ? '' : $row->choice_list, '<i>' . $row->type_options . '</i>'));
                    $row->name = $row->name . ($row->optional ? "<sup title='optional'>*</sup>" : "<sup title='mandatory'>†</sup>");
                    foreach ($use_columns as $field):
                        echo '<td class=""><div class="td-contents field-' . $field . '">';
                        $cell = $row->$field;
                        if (strtolower($field) == 'choices') {
                            $cell = array_to_orderedlist($cell);
                        } elseif ($field == 'label' AND $cell === null) {
                            $cell = nl2br($row->label);
                        } elseif (($field == 'value' || $field == 'showif') && $cell != '') {
                            $cell = "<pre><code class='r'>$cell</code></pre>";
                        }
                        echo $cell;
                        echo '</td></div>';
                    endforeach;
                    ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>