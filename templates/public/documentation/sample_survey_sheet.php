<h3>Survey Spreadsheet</h3><hr />

<p>
    Survey spreadsheets contain the questions on a first sheet called "survey". They can optionally have a second sheet called "choices" where you define choices for multiple choice items in a long format. They can also optionally have a third sheet called "settings" where you define settings such as pagination and validation (most set these settings after uploading the survey though).
</p>
<p>You can <a href="https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw/" title="Select File->Make a copy if you have a Google account, or download it as an Excel file, if you don't">clone a Google spreadsheet</a> to get started or start with an <a href="<?= asset_url("assets/example_surveys/empty_survey.xlsx")?>">empty spreadsheet</a>.</p>
<p>Some helpful tips:</p>
<ul>
    <li>
        You may want to make linebreaks in Excel to format your text. In Microsoft Excel on Macs, you need to press <kbd>Command ⌘</kbd>+<kbd>Option ⌥</kbd>+<kbd>Enter ↩</kbd>, on Windows it is <kbd>Alt</kbd>+<kbd>Enter ↩</kbd>. We suggest you start working from the provided sample sheet, because it already has the proper formatting and settings. In Google Spreadsheets, the combination is <kbd>Option ⌥</kbd>+<kbd>Enter ↩</kbd>.
    </li>
    <li>
        Make text <strong>bold</strong> using  <code>__bold__</code>, make it <em>italic</em> using <code>*italic*</code>.
    </li>
</ul>
<div class="table-responsive">
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
</div>

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
    <dd>
        This column can optionally be added to visually style items. Find the available classes below.
    </dd>
    <dt>
        choice1 - choice12
    </dt>
    <dd>
        For multiple choice items, you can define the labels of the different choices here. In the database, the corresponding number will be stored (e.g., if choice1 is "honey", then the DB will record "1"). You can define at most 12 choices this way and you have no control over the value stored in the database. To define more than 12 choices or to reuse choice lists, use the choices sheet.
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

<h3>Randomising items and blocks</h3>
<p>The <code>item_order</code> and <code>block_order</code> columns together cover the common randomisation schemes. Items that share an <code>item_order</code> value are shuffled among themselves; <code>block_order</code> assigns items to blocks, and consecutively labelled blocks are shuffled as wholes. Four examples:</p>
<h4>1. Simple randomisation of items</h4>
<p>An instruction is shown first, followed by items 1&ndash;4 in random order.</p>
<table class="table table-condensed" style="width:auto">
<thead><tr><th>name</th><th>block_order</th><th>item_order</th></tr></thead>
<tbody>
<tr><td>instr</td><td></td><td>1</td></tr>
<tr><td>item_1</td><td></td><td>2</td></tr>
<tr><td>item_2</td><td></td><td>2</td></tr>
<tr><td>item_3</td><td></td><td>2</td></tr>
<tr><td>item_4</td><td></td><td>2</td></tr>
</tbody>
</table>
<h4>2. Random block order, fixed order within blocks</h4>
<p>Blocks A and B are presented in random order; within each block the items keep their order.</p>
<table class="table table-condensed" style="width:auto">
<thead><tr><th>name</th><th>block_order</th><th>item_order</th></tr></thead>
<tbody>
<tr><td>item_1</td><td>A</td><td>1</td></tr>
<tr><td>item_2</td><td>A</td><td>2</td></tr>
<tr><td>item_3</td><td>B</td><td>1</td></tr>
<tr><td>item_4</td><td>B</td><td>2</td></tr>
</tbody>
</table>
<h4>3. Fixed block order, random order within blocks</h4>
<p>Block A always comes first. Its two items are shuffled and followed by a submit button, so each block sits on its own page.</p>
<table class="table table-condensed" style="width:auto">
<thead><tr><th>name</th><th>block_order</th><th>item_order</th></tr></thead>
<tbody>
<tr><td>item_1</td><td>A</td><td>1</td></tr>
<tr><td>item_2</td><td>A</td><td>1</td></tr>
<tr><td>submit1</td><td>A</td><td>2</td></tr>
<tr><td>item_3</td><td>B</td><td>3</td></tr>
<tr><td>item_4</td><td>B</td><td>3</td></tr>
<tr><td>submit2</td><td>B</td><td>4</td></tr>
</tbody>
</table>
<h4>4. Random blocks, random items within them</h4>
<p>Blocks are shuffled and so are the items inside each block; the submit button stays at the bottom of its block's page. The same trick places an instruction item at the top of every page.</p>
<table class="table table-condensed" style="width:auto">
<thead><tr><th>name</th><th>block_order</th><th>item_order</th></tr></thead>
<tbody>
<tr><td>item_1</td><td>A</td><td>1</td></tr>
<tr><td>item_2</td><td>A</td><td>1</td></tr>
<tr><td>submit1</td><td>A</td><td>2</td></tr>
<tr><td>item_3</td><td>B</td><td>1</td></tr>
<tr><td>item_4</td><td>B</td><td>1</td></tr>
<tr><td>submit2</td><td>B</td><td>2</td></tr>
</tbody>
</table>
<h3>Optional classes for visual styling</h3>
<p>Add a <code>class</code> column with space-separated class names to change how an item looks. The available classes are documented in the <a href="#css_classes" data-toggle="tab">CSS classes</a> section.</p>
