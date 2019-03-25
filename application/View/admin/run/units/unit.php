<div class="row run_unit_inner <?= strtolower($unit->type) ?>" data-type="<?= $unit->type ?>">
    <div class="col-xs-12">
        <h4><input type="text" value="<?= $unit->description ?>" placeholder="Description (click to edit)" class="run_unit_description" name="description"></h4>
    </div>
    <div class="col-xs-3 run_unit_position">
        <h1><i class="muted fa fa-2x <?= $unit->icon ?>"></i></h1>
        <?= $unit->howManyReachedIt() ?> <button href="ajax_remove_run_unit_from_run" class="remove_unit_from_run btn btn-xs hastooltip" title="Remove unit from run" type="button"><i class="fa fa-times"></i></button> <br />
        <input class="position" value="<?= $unit->position ?>" type="number" name="<?= $unit->run_unit_id ?>" step="1" max="32000" min="-32000"> <br />
    </div>
    <div class="col-xs-9 run_unit_dialog">
        <input type="hidden" value="<?= $unit->run_unit_id ?>" name="run_unit_id" />
        <input type="hidden" value="<?= $unit->id ?>" name="unit_id" />
        <input type="hidden" value="<?= $unit->special ?>" name="special" />

        <?php echo $dialog ?>
    </div>
</div>
<div class="clear clearfix"></div>