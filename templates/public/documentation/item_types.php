<h3>Survey Item Types</h3><hr />

<p>There are a lot of item types, in the beginning you will probably only need a few though. To see them in action,
try using the following <a href="https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw/" title="Select File->Make a copy if you have a Google account, or download it as an Excel file, if you don't">Google spreadsheet</a> or <a href="<?= site_url('widgets') ?>">fill it out yourself</a>. It contains example uses of nearly every item there is.</p>

<h4><i class="fa fa-fw fa-info"></i> Plain display types</h4>
<dl class="dl-horizontal dl-wider">
    <dt>
        note
    </dt>
    <dd>
        display text. Notes are only displayed once, you can think of them as being "answered" simple by submitting.
    </dd>
    <dt>
        note_iframe
    </dt>
    <dd>
        If you want to render complex rmarkdown <a href="https://www.htmlwidgets.org/">htmlwidgets</a>, use this.
    </dd>
    <dt>
        submit <i>timeout | auto</i>
    </dt>
    <dd>
        display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for <code>showif</code> or for dynamically generating item text have been given. 
        <br><br>You can specify an optional timeout/delay (in milliseconds). <br>Negative values mean that the user has to wait that long until they can click submit. <br>Positive values mean the submit button will automatically submit after that time has passed. However, if not all items are answered or optional, the user will end up on the same page and the timer will restart. To avoid that, you have to use it together with optional items. Then, it's a way to use timed submissions. The data in the item display table can be used to check how long an item was displayed and whether this matches with the server's time for when it sent the item and received the response.
        <br><br>You can also set the option to <code>auto</code>, which will cause the page to be submitted automatically as soon as all visible items on the page have been answered. Please note that optional items also count as visible items. This can be used for e.g. creating menu-like pages.
    </dd>
</dl>
<h4><i class="fa fa-fw fa-keyboard-o"></i> Simple input family</h4>
<dl class="dl-horizontal dl-wider">
    <dt>
        text <i>max_length</i>
    </dt>
    <dd>
        allows you to enter a text in a single-line input field. Adding a number <code>text 100</code> defines the maximum number of characters that may be entered.
    </dd>
    <dt>
        textarea <i>max_length</i>
    </dt>
    <dd>
        displays a multi-line input field
    </dd>
    <dt>
        number <i>min, max, step</i>
    </dt>
    <dd>
        for numbers. <code>step</code> defaults to <code>1</code>, using <code>any</code> will allow any decimals.
    </dd>
    <dt>
        letters <i>max_length</i>
    </dt>
    <dd>
        like text, allows only letters (<code>A-Za-züäöß.;,!: </code>), no numbers.
    </dd>
    <dt>
        email
    </dt>
    <dd>
        for email addresses. They will be validated for syntax, but they won't be verified unless you say so in the run.
    </dd>
</dl>
<h4><i class="fa fa-fw fa-arrows-h"></i> Sliders</h4>
<dl class="dl-horizontal dl-wider">
    <dt>
        range <i>min,max,step</i>
    </dt>
    <dd>
        these are sliders. The numeric value chosen is not displayed. Text to be shown to the left and right of the slider can be defined using the choice1 and choice2 fields. Defaults are <code>1,100,1</code>.
    </dd>
    <dt>
        range_ticks <i>min,max,step</i>
    </dt>
    <dd>
        like range but the individual steps are visually indicated using ticks and the chosen number is shown to the right. 
    </dd>
</dl>

<h4><i class="fa fa-fw fa-calendar"></i> Datetime family</h4>
<dl class="dl-horizontal dl-wider">
    <dt>
        date <i>min,max</i>
    </dt>
    <dd>
        for dates (displays a date picker). Input can be constrained using the min,max parameters. Allowed values would e.g. be <code>2013-01-01,2014-01-01</code> or <code>-2years,now</code>.
    </dd>
    <dt>
        time <i>min,max</i>
    </dt>
    <dd>
        for times (displays an input with hours and minutes). Input can also be constrained using min,max, e.g. <code>12:00,17:00</code>
    </dd>
</dl>
<h4><i class="fa fa-fw fa-magic"></i> Fancy family</h4>
<dl class="dl-horizontal dl-wider">
    <dt>
        geopoint
    </dt>
    <dd>
        displays a button next to a text field. If you press the button (which has the location icon <i class="fa fa-location-arrow"></i> on it) and agree to share your location, the GPS coordinates will be saved. If you deny access or if GPS positioning fails, you can enter a location manually.
    </dd>
    <dt>
        color
    </dt>
    <dd>
        allows you to pick a color, using the operating system color picker (or one polyfilled by Webshims)
    </dd>
</dl>
<h4><i class="fa fa-fw fa-check-square"></i> Multiple choice family</h4>
<p>The, by far, biggest family of items. Please note, that there is some variability in how the answers are stored. You need to know about this, if you <strong>(a)</strong> intend to analyse the data in a certain way, for example you will want to store numbers for Likert scale choices, but text for timezones and cities <strong>(b)</strong> if you plan to use conditions in the run or in showif or somewhere else where R is executed. <strong>(b)</strong> is especially important, because you might not notice if <code>demographics$sex == 'male'</code> never turns true because sex is stored as 0/1 and you're testing as female.</p>
<dl class="dl-horizontal dl-wider">
    <dt>
        mc <i>choice_list</i>
    </dt>
    <dd>
        multiple choice (radio buttons), you can choose only one.
    </dd>
    <dt>
        mc_button <i>choice_list</i>
    </dt>
    <dd>
        like <code>mc</code> but instead of the text appearing next to a small button, a big button contains each choice label
    </dd>

    <dt>
        mc_multiple <i>choice_list</i>
    </dt>
    <dd>
        multiple multiple choice (check boxes), you can choose several. Choices defined as above.
    </dd>
    <dt>
        mc_multiple_button
    </dt>
    <dd>
        like mc_multiple and mc_button
    </dd>

    <dt>
        check
    </dt>
    <dd>
        a single check box for confirmation of a statement.
    </dd>
    <dt>
        check_button
    </dt>
    <dd>
        a bigger button to check.
    </dd>

    <dt>
        rating_button <br><i>min, max, step</i>
    </dt>
    <dd>
        This shows the choice1 label to the left, the choice2 label to the right and a series of numbered buttons as defined by <code>min,max,step</code> in between. Defaults to 1,5,1.
    </dd>
    <dt>
        sex
    </dt>
    <dd>
        shorthand for <code>mc_button</code> with the ♂, ♀ symbols as choices
    </dd>
    <dt>
        select_one <i>choice_list</i>
    </dt>
    <dd>
        a dropdown, you can choose only one
    </dd>
    <dt>
        select_multiple <i>choice_list</i>
    </dt>
    <dd>
        a list in which, you can choose several options
    </dd>
    <dt>
        select_or_add_one <br><i>choice_list, maxType</i>
    </dt>
    <dd>
        like select_one, but it allows users to choose an option not given. Uses <a href="https://ivaynberg.github.io/select2/">Select2</a>. <i>maxType</i> can be used to set an upper limit on the length of the user-added option. Defaults to 255.
    </dd>
    <dt>
        select_or_add_multiple <br><i>choice_list, maxType, <br>maxChoose</i>
    </dt>
    <dd>
        like select_multiple and select_or_add_one, allows users to add options not given. <i>maxChoose</i> can be used to place an upper limit on the number of chooseable options.
    </dd>
    <dt>
        mc_heading <i>choice_list</i>
    </dt>
    <dd>
        This type permits you to show the labels for mc or mc_multiple choices only once.<br>
        To get the necessary tabular look, assign a constant width to the choices (using e.g. mc-width100), give the heading the same choices as the mcs, and give the following mcs (or mc_multiples)  the same classes + hide_label. <br>
        On small screens the mc_heading will be hidden and labels will automatically be displayed again, because the tabular layout would otherwise break down.
    </dd>
</dl>



<h4><i class="fa fa-fw fa-eye-slash"></i> Hidden family</h4>
These items don't require the user to do anything, so including them simply means that the relevant value will be stored. If you have exclusively hidden items in a form, things will wrap up immediately and move to the next element in the run. This can be useful for hooking up with other software which sends data over the query string i.e. https://formr.org/run_name?param1=10&amp;user_id=29
<dl class="dl-horizontal dl-wider">
    <dt>
        calculate
    </dt>
    <dd>
        in the <strong>value</strong> column you can specify an R expression, the result of which will be saved into this variable. Useful to pull in external data or to forestall recalculating something repeatedly that you want to refer to later. If the calculation is based on values from the same module, you can insert the calculate item in the last line of the sheet behind the last submit button and its result will be stored in the database for use in further modules.
    </dd>
    <dt>
        ip
    </dt>
    <dd>
        saves your IP address. You should probably not do this covertly but explicitly announce it.
    </dd>
    <dt>
        referrer
    </dt>
    <dd>
        saves the last outside referrer (if any), ie. from which website you came to formr
    </dd>
    <dt>
        server var
    </dt>
    <dd>
        saves the <a href="https://us1.php.net/manual/en/reserved.variables.server.php">$_SERVER</a> value with the index given by var. Can be used to store one of 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'HTTP_ACCEPT_CHARSET', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_CONNECTION', 'HTTP_HOST', 'QUERY_STRING', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT'. In English: the browser, some stuff about browser language information, some server stuff, and access time.
    </dd>
    <dt>
        get var
    </dt>
    <dd>
        saves the <code>var</code> from the query string, so in the example above <code>get <em>param1</em></code> would lead to 10 being stored.
    </dd>
    <dt>
        random min,max
    </dt>
    <dd>
        generates <a href="https://php.net/mt_rand">a random number</a> for later use (e.g. randomisation in experiments). Minimum and maximum default to 0 and 1 respectively. If you specify them, you have to specify both.
    </dd>
    <dt>
        hidden
    </dt>
    <dd>
        you can use this item with a pre-set value, if you need to use data from previous pages together with data on the same page for a showif
    </dd>
    <dt>
        block
    </dt>
    <dd>
        Blocks progress. You can give this item a showif such as <code>(item1 + item2) > 100</code> to add further requirements.
    </dd>

</dl>


<h4><i class="fa fa-fw fa-file"></i> File uploads</h4>
You can ask study participants to upload image, audio, video, text, and PDF files in formr. Server-side limits for the maximal file sizes apply, you can also set lower limits per item. 

<dl class="dl-horizontal dl-wider">
    <dt>
        file <i>max_size_in_bytes</i>
    </dt>
    <dd>
        Permits all of the file types allowed by audio, video, image plus PDFs and some text files.
    </dd>
    <dt>
        audio <i>max_size_in_bytes</i>
    </dt>
    <dd>
        Upload audio files. If you assign the class "record_audio" in the class column, a little recording interface will be shown. It will use the device microphone.
    </dd>
    <dt>
        video <i>max_size_in_bytes</i>
    </dt>
    <dd>
        Upload video files.
    </dd>
    <dt>
        image <i>max_size_in_bytes</i>
    </dt>
    <dd>
        Upload image files. On smartphones, this can trigger the camera app.
    </dd>

</dl>

<h4><i class="fa fa-fw fa-bell"></i> Progressive web app items</h4>

Formr studies can be installed as a PWA (Progressive Web App). This allows you to send push notifications to users to invite them to return to the study, e.g. for experience sampling studies. To make this work, you need to generate a manifest.json file in the study/run settings.

<dl class="dl-horizontal dl-wider">
    <dt>
        request_phone
    </dt>
    <dd>
        Helps transition desktop users to continue the study on their mobile device. On mobile devices, it automatically confirms mobile usage. On desktop, it displays a QR code for users to scan with their phone. Returns 'is_phone' for mobile users, 'is_desktop' for desktop users, 'qr_scanned' when successfully scanned, or 'not_checked' before verification.
    </dd>
    <dt>
        add_to_home_screen
    </dt>
    <dd>
        Displays a button that prompts users to add the study to their home screen as a PWA. The button's text can be customized using the choice field. Returns one of these statuses: 'added', 'ios_not_prompted', 'not_requested', 'not_prompted', 'already_added', 'no_support', or 'not_added'.
    </dd>
    <dt>
        push_notification
    </dt>
    <dd>
        Adds a button to request permission for sending push notifications. The button text can be customized using the choice field. When enabled, stores the push notification subscription data needed to send notifications to the user. For optional items, accepts 'not_requested', 'not_supported', or 'permission_denied' as valid states.
    </dd>
    <dt>
        request_cookie
    </dt>
    <dd>
        Prompts participants to enable functional cookies so their device can be recognised in later visits. Displays nothing on devices where this permission has already been granted. Returns 'functional_cookie' for users who had already consented, 'consent_given' after the button is used, and remains 'not_checked' until consent is provided. If the item is marked as required, the survey page cannot be submitted until functional cookie consent is recorded. In apps, we usually need functional cookies to be enabled to track users across sessions, so it makes sense to include this item after an app has been added to the home screen.
    </dd>
</dl>
