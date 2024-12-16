<h3>Survey Spreadsheet</h3><hr />

<p>
    Survey spreadsheets contain the questions on a first sheet called "survey". They can optionally have a second sheet called "choices" where you define choices for multiple choice items in a long format. They can also optionally have a third sheet called "settings" where you define settings such as pagination and validation (most set these settings after uploading the survey though).
</p>
<p>You can <a href="https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw/" title="Select File->Make a copy if you have a Google account, or download it as an Excel file, if you don't">clone a Google spreadsheet</a> to get started or start with an <a href="<?= asset_url("assets/example_surveys/empty_survey.xlsx")?>">empty spread sheet</a>.</p>
<p>Some helpful tips:</p>
<ul>
    <li>
        You may want to make linebreaks in Excel to format your text. In Microsoft Excel on Macs, you need to press <kbd>Command ⌘</kbd>+<kbd>Option ⌥</kbd>+<kbd>Enter ↩</kbd>, on Windows it is <kbd>ctrl</kbd>+<kbd>Enter ↩</kbd>. We suggest you start working from the provided sample sheet, because it already has the proper formatting and settings. In Google Spreadsheets, the combination is <kbd>Option ⌥</kbd>+<kbd>Enter ↩</kbd>.
    </li>
    <li>
        Make text <strong>bold</strong> using  <code>__bold__</code>, make it <em>italic</em> using <code>*italic*</code>.
    </li>
</ul>
<table class='table table-striped'>
    <thead>
        <tr>
            <th>
                type
            </th>
            <th>
                name
            </th>
            <th>
                label
            </th>
            <th>
                optional
            </th>
            <th>
                showif
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                text
            </td>
            <td>
                name
            </td>
            <td>
                Please enter your name
            </td>
            <td>
                *
            </td>
            <td>
            </td>
        </tr>

        <tr>
            <td>
                number 1,130,1
            </td>
            <td>
                age
            </td>
            <td>
                How old are you?
            </td>
            <td>
            </td>
            <td>
            </td>
        </tr>

        <tr>
            <td>
                mc agreement
            </td>
            <td>
                emotional_stability1R
            </td>
            <td>
                I worry a lot.
            </td>
            <td>
            </td>
            <td>age >= 18
            </td>
        </tr>

        <tr>
            <td>
                mc agreement
            </td>
            <td>
                emotional_stability2R
            </td>
            <td>
                I easily get nervous and unsure of myself.
            </td>
            <td>
            </td>
            <td>
                age >= 18
            </td>
        </tr>
        <tr>
            <td>
                mc agreement
            </td>
            <td>
                emotional_stability3
            </td>
            <td>
                I am relaxed and not easily stressed.
            </td>
            <td>
            </td>
            <td>
                age >= 18
            </td>
        </tr>
    </tbody>
</table>

<h3>Available columns</h3>
<p>You can use more columns than the ones shown above. Unknown column types are simply ignored, so you can use them for other information.</p>
<p>The following column types exist:</p>
<dl class="dl-horizontal dl-wider">
    <dt>
        type
    </dt>
    <dd>
        set the item type (<a href="<?= WEBROOT ?>public/documentation#available_items">see item types tab</a>)
    </dd>
    <dt>
        name
    </dt>
    <dd>
        this is simply the name of the item. You'll use it to refer to the item in your data analysis and when making complex conditions, so adhere to a systematic naming scheme (we recommend scale1, scale2, scale3R for Likert-type items).
    </dd>
    <dt>
        label
    </dt>
    <dd>
        This column is the text that will be shown on the left-hand side of the answer choices (for most items). You can use Markdown formatting here.
    </dd>
    <dt>
        showif
    </dt>
    <dd>
        If you leave this empty, the item will always be shown. If it contains a condition, such as <code>sex == 1</code>, it will only be shown if that condition is true. Conditions are written in R and can be arbitrarily complex. You should always test them well. It is also possible to refer to data in other surveys using e.g. <code>other_survey$item_name != 2</code>. If you refer to data on the same page, items will also be shown dynamically using Javascript.
    </dd>
    <dt>
        optional
    </dt>
    <dd>
        Nearly all items are mandatory by default. By using <code>*</code> in this column, you can turn items optional instead. Using <code>!</code> requires a response to items that are optional by default (check, check_button).
    </dd>
    <dt>
        value
    </dt>
    <dd>
        Sometimes you may want a value to be pre-set when users first fill out a form. This can be especially handy in longitudinal studies, where you want to check that e.g. contact information is still up-to-date or when you want to highlight changes across days. You can, again, use arbitrarily complex R code (e.g. <code>a_different_survey$item1 + a_different_survey$item2</code>) to pre-set a value, but you can also simply use <code>1</code> to choose the option with the value 1 (remember that choices for mc-family items are saved as numbers, not as the choice labels per default). There is one special word, <code>sticky</code>, which always pre-sets the items value to the most recently chosen value. You also have to keep in mind when pre-setting strings, that they have to be marked up in R, like this <code>"I am text"</code> (preferably do not use single quotes because Excel will mess them up).
    </dd>
    <dt>
        class
    </dt>
    <dt>
        choice1 - choice12
    </dt>
    <dd>
        For multiple choice items, you can define the labels of the different choices here. In the database, the corresponding number will be stored (e.g., if choice1 is "honey", then the DB will record "1"). You can define at most 12 choices this way and you have no control over the value stored in the database. To define more than 12 choices or to reuse choice lists, use the choices sheet.
    </dd>

    <dd>
        This column can optionally be added to visually style items. Find the available classes below.
    </dd>
    <dt>
        item_order
    </dt>
    <dd>
        By default (and if you leave this column empty), items in formr are simply displayed in the order they are defined in the spreadsheet. If you assign numbers here, those will define the order instead. If several items share the same number, their order will be randomized when the survey is loaded.
    </dd>
    <dt>
        block_order
    </dt>
    <dd>
        You can use 1-4 letters to define blocks. For consecutively labelled blocks (without gaps), formr will then randomize block-wise (e.g., an entire block of items comes either first or second). If it helps you think about it, you can imagine, the final order of items as being determined by sorting on block_number.item_number.random_number. If that didn't help enough, how about this <a href="https://github.com/rubenarslan/formr.org/wiki/How-to-randomize-items-and-blocks">guide</a>.
    </dd>
</dl>

<h3>Optional classes for visual styling</h3>
<p>You might want to tinker with the look of certain form items. To do so you can use a variety of pre-set CSS classes. This is a fancy way of saying that if you make a new column in your survey sheet, call it "class" and add space-separated magic words, stuff will look different.</p>
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
