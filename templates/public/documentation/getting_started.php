<h3>Getting Started</h3><hr />

<h4> <i class="fa fa-circle"></i> Creating Studies </h4>
<p>
    To begin creating studies using formr, you need to <a href="<?= site_url('register') ?>">sign-up</a> with your email and obtain an administrator account.
    An administrator account is obtained by sending a request via email to <a href="mailto:accounts@formr.org">accounts@formr.org</a>.
    Studies in formr are <a href="<?= site_url('documentation#sample_survey_sheet') ?>">created using spreadsheets</a>. As a good starting point, you can clone the following <a href="https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw/">Google spreadsheet</a>
    and study it to get versed with the definitions of the <a href="<?= site_url('documentation#available_items') ?>">item types</a> formr supports.
</p>

<p>
    <strong>1. Upload the items</strong>: 
    With your spreadsheet ready, login to formr admin and go to <strong>Surveys > Create new Surveys</strong>.<br />
    You can either upload your spreadsheet if it was stored locally on your computer using the form <i>Upload an item table</i> or
    you could import a Google spreadsheet via it's visible share link, using the form <i>Import a Googlesheet</i>. When importing a Googlesheet,
    you will need to manually specify the name of your survey where's if uploading a spreadsheet, the name of your survey is obtained using the filename
    of the spreadsheet.
</p>
<p>
    <strong>2. Manage your survey</strong>: 
    If your spreadsheet was well formed (as described <a href="<?= site_url('documentation#sample_survey_sheet') ?>">here</a>) and the items were successfully uploaded, you survey will be added
    to the <b>Surveys</b> menu. To manage your created survey, go to <strong>Surveys > <i>YourSurveyName</i></strong>.<br />
    In the survey admin area you can test your study, change some survey settings, view and download results, upload and delete survey items etc.
    The survey menu to the left in the survey admin area contains hints that are self explanatory.
</p>
<p>
    <strong>3.Create a Run</strong>: 
    A formr "run" contains your study's complete design. Designs can range from the simple (a single survey or a randomized experiment) to the complex (like a diary study with daily reminders by email and text message or a longitudinal study tracking social network changes).
    It is recommended you <a href="<?= site_url('documentation#run_module_explanations') ?>">read more about runs</a> before you begin. To create a run go to <strong>Runs > Create a new Run</strong>. Enter a meaningful run name which should contain only of alphanumeric characters.
    If the run was creates successfully, it will be added to the <strong>Runs</strong> menu and you will be redirected to the run admin area.
    Here you can add <a href="<?= site_url('documentation#run_module_explanations') ?>">Run Units</a>, design the complexity of your study and test your study. To modify your study definition later, you can go to <strong>Runs > <i>YourRunName</i></strong>.
    Your run is the entry point of your study. For participants to access your study, you need to <i>set you run as public or protected</i> in the admin area and it will be accessible under the URL <strong><?= run_url('YourRunName') ?></strong>
</p>


<h4> <i class="fa fa-circle"></i> Setting up your own formr instance </h4>
<p>
    If you wish to set up your own instance of formr please follow the guidelines in our <a href="https://github.com/rubenarslan/formr.org/blob/master/INSTALLATION.md" target="_blank">installation guide</a>.
</p>
<p>
    There is always <a href="<?= site_url('documentation#help') ?>"">help</a> if you need assistance.
</p>