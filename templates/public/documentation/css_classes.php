<h3>CSS classes</h3><hr />
<p>You might want to tinker with the look of certain form items. To do so you can use a variety of pre-set CSS classes. This is a fancy way of saying that if you make a new column in your survey sheet, call it "class" and add space-separated magic words, stuff will look different.</p>
<p>The classes are defined in <a href="https://github.com/rubenarslan/formr.org/blob/master/webroot/assets/common/css/custom_item_classes.css"><code>custom_item_classes.css</code></a> on GitHub, which is the authoritative list if a class you need is not documented below. Every item's wrapper also carries an <code>item-&lt;type&gt;</code> class (for example <code>item-mc</code>), so you can target all items of one type. If the pre-set classes are not enough, a run's settings let you add your own CSS, which is loaded on every page of that run; use the <code>class</code> column to attach your own class names and style them there.</p>
<p>These are the available styling classes:</p>
<dl class="dl-horizontal dl-wider">
    <dt>
        left100, (200, …, 900)
    </dt>
    <dd>
        controls the width of the left-hand column (labels). The default is left300, you have 100 pixel increments to choose from.
    </dd>
    <dt>
        right100, (200, …, 900)
    </dt>
    <dd>
        controls the width of the right-hand column (answers). There is no default here, usually the right-hand column will extend in accordance with the display width.
    </dd>
    <dt>
        right_offset0, (100, …, 900)
    </dt>
    <dd>
        controls the offset (distance) of the right-hand column to the left (not the label column, just the left). This is 300 pixels (+20 extra) by default. Analogously with left_offset100 etc. (defaults to 0).
    </dd>

    <dt>
        label_align_left <br>label_align_center <br>label_align_right
    </dt>
    <dd>
        controls the text alignment of the left-hand column (labels), by default it is aligned to the right.
    </dd>
    <dt>
        answer_align_left <br>answer_align_center <br>answer_align_right
    </dt>
    <dd>
        controls the text alignment of the right-hand column (answers), by default it is aligned to the left.
    </dd>

    <dt>
        answer_below_label
    </dt>
    <dd>
        This leads to answers stacking below labels, instead of them being side-by-side (the default). It entails zero offsets and left alignment. Can be overridden with offsets and the alignment classes.
    </dd>

    <dt>
        hide_label
    </dt>
    <dd>
        This hides the labels for mc and mc_multiple replies. Useful in combination with a fixed width for mc, mc_multiple labels and mc_heading – this way you can achieve a tabular layout. On small screens labels will automatically be displayed again, because the tabular layout cannot be maintained then.
    </dd>

    <dt>
        show_value_instead_of_label
    </dt>
    <dd>
        This hides the labels for mc_button and mc_multiple_button, instead it shows their values (useful numbers from 1 to x). Useful in combination with mc_heading – this way you can achieve a tabular layout. On small screens labels will automatically be displayed again, because the tabular layout cannot be maintained then.
    </dd>

    <dt>
        rotate_label45, rotate_label30, <br>rotate_label90
    </dt>
    <dd>
        This rotates the labels for mc and mc_multiple replies. Useful if some have long words, that would lead to exaggerated widths for one answer column.
    </dd>


    <dt>
        mc_block
    </dt>
    <dd>
        This turns answer labels for mc-family items into blocks, so that lines break before and after the label.
    </dd>

    <dt>
        mc_vertical
    </dt>
    <dd>
        This makes answer labels for mc-family items stack up. Useful if you have so many options, that they jut out of the viewport. If you have very long list, consider using select-type items instead (they come with a search function).
    </dd>

    <dt>
        mc_horizontal
    </dt>
    <dd>
        This makes answer labels for mc-family items stay horizontal on small screens.
    </dd>


    <dt>
        mc_equal_widths
    </dt>
    <dd>
        This makes answer labels for mc-family items have equal widths, even though their contents would lead them to have different widths. This won't work in combination with every other option for mc-styling and if your widest elements are very wide, the choices might jut out of the viewport.
    </dd>


    <dt>
        mc_width50 (60, … , <br>100, 150, 200)
    </dt>
    <dd>
        This makes choice labels and choice buttons for mc-family items have fixed widths. If one choice has text wider than that width, it might jut out or ignore the fixed width, depending on the browser.
    </dd>

    <dt>
        rating_button_label_width50 <br>(60, … , 100, 150, 200)
    </dt>
    <dd>
        This makes the labels for rating_button items have fixed widths. This can be useful to align rating_buttons buttons with each other even though the end points are labelled differently. A more flexible solution would be to horizontally center the choices using answer_align_center.
    </dd>

    <dt>
        space_bottom_10 <br>(10, 20, … , 60)
    </dt>
    <dd>
        Controls the space after an item. Default value is 15.
    </dd>
    <dt>
        space_label_answer_vertical_10 <br>(10, 20, … , 60)
    </dt>
    <dd>
        Controls the vertical space between label and choices, if you've set answer_below_label. Default value is 15.
    </dd>
    <dt>
        clickable_map
    </dt>
    <dd>
        If you use this class for a text type item, with one image in the label, this image will become a clickable image, with the four outer corners selectable (the selection will be stored in the text field). Will probably require customisation for your purposes.
    </dd>
</dl>
