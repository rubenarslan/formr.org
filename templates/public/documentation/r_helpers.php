<h3>R Helpers</h3><hr />

<h4>R in formr</h4>
<p>
    In formr, you can use R to write simple and complex code. Various places allow you to specify either R code (e.g., showif column, value column, SkipForward/SkipBackward, External, Pause button conditions) or R code interspersed with Markdown (as in knitr, e.g., labels, Stop button, Pause button texts). The R code you wrote will be automatically enriched with the data objects you name and processed using <abbr title="A way to use R on the web safely">OpenCPU</abbr>. By default, your participants cannot view the R code you write.
</p>
<h4>Automatically enriched data</h4>
<p>
When you write R code in formr, we try to automatically determine what data you need and supply it. To do so, formr has a look up table of all the available data (the surveys defined in the run, the items defined in these surveys, as well as some metadata about the participant and run progress).
</p>
<p>
For example, to obtain somebody's age, you need only write <code class="r">demographics$age</code>. formr will then automatically create a data frame named "demographics" containing the variable "age". To give another example, to see whether a participant ever reported a headache in your diary, you might just write <code class="r">any(diary$headache > 1)</code>. In this case, formr would create a data frame containing all responses to the headache question. It's important to note that formr simply checks whether the name of the survey exists anywhere in the text and whether the name of the item exists anywhere else. So, <code class="r">demographics$age</code> works, but so does <code class="r">demographics[, 'age']</code>. If an item name exists in multiple surveys that you have named, it will be supplied for all surveys.
</p>

<h5>Available data</h5>
<dl class="dl-horizontal dl-wider">
    <dt>user_id</dt>
    <dd>The unique user code which we use for logging people in, e.g., NqbpASFVlcci5cnVvpMZG4ueILaYvFk39fDND305XvPLh3KW4xzrP0ygJ1phs1gf.</dd>

    <dt>.formr<br>
        <i>$login_link</i>
        <i>$login_code</i>
        <i>$nr_of_participants</i>
        <i>$session_last_active</i>
    </dt>
    <dd>
        Useful shortcuts to obtain the link to the personalised study link, the login code (currently the same as user_id), the total number of participants in the run (even those who only saw the first page), as well as a date-time when the current participant was last active.
    </dd>
    
    <dt>YourSurvey<br>
    <i>$YourItem1</i><br>
    <i>$YourItem2</i></dt>
    <dd>Any of the surveys that are part of the run and any of their items can be requested in this way. In addition, if you have named items belonging to a scale with a numeric suffix and an optional R, you need only name the scale (e.g., <b>extraversion</b>) and all items (e.g., <b>extraversion1, extraversion2R, extraversion3</b>) will be supplied.</dd>
    
    <dt>survey_users<br>
    <i>$created</i><br>
    <i>$modified</i><br>
    <i>$user_code</i><br>
    <i>$email</i><br>
    <i>$email_verified</i><br>
    <i>$mobile_number</i><br>
    <i>$mobile_verified</i></dt>
    <dd>This data frame contains user account information, such as when the account was created, the user's contact details, and whether they have been verified. This is usually empty, because most study participants don't sign up on formr.</dd>
    
    <dt>survey_run_sessions<br>
    <i>$session</i><br>
    <i>$created</i><br>
    <i>$last_access</i><br>
    <i>$ended</i><br>
    <i>$position</i><br>
    <i>$current_unit_id</i><br>
    <i>$deactivated</i><br>
    <i>$no_email</i></dt>
    <dd>This data frame tracks user sessions in the run/study, including when they started the study (created), last accessed it, ended it (reached a Stop button), the current position in the run, and whether the user has opted out of email notifications.</dd>
    
    <dt>survey_unit_sessions<br>
    <i>$created</i><br>
    <i>$ended</i><br>
    <i>$expired</i><br>
    <i>$unit_id</i><br>
    <i>$position</i><br>
    <i>$type</i></dt>
    <dd>This data frame contains metadata about the progression of the user through the run/study, including when they reached each unit (created), left it (ended), and so on.</dd>
    
    <dt>externals<br>
    <i>$created</i><br>
    <i>$ended</i><br>
    <i>$position</i></dt>
    <dd>Metadata about external units linked to the study, i.e. when users were sent there, whether they returned/completed the external unit (ended) and the position in the run.</dd>
    
    <dt>survey_items_display<br>
    <i>$created</i><br>
    <i>$answered_time</i><br>
    <i>$answered</i><br>
    <i>$displaycount</i><br>
    <i>$item_id</i></dt>
    <dd>This data frame tracks the display and response behavior for survey items, including timestamps for when they were displayed and answered.</dd>
    
    <dt>survey_email_log<br>
    <i>$email_id</i><br>
    <i>$created</i><br>
    <i>$recipient</i></dt>
    <dd>This data frame logs email interactions, including when an email was sent and its recipient.</dd>
    
    <dt>shuffle<br>
    <i>$unit_id</i><br>
    <i>$created</i><br>
    <i>$group</i></dt>
    <dd>This data frame tracks shuffled units and the group they belong to for randomisation purposes.</dd>
</dl>

<h4>Packages</h4>
<p>
    Wherever you use R in formr you can also use the functions in its R package. If you want to use the package in a different environment,
    you'll need to install it using the following code.	
</p>
<pre><code class="r">install.packages('formr', repos = c('https://rforms.r-universe.dev', 'https://cloud.r-project.org'))</code></pre>
<p>The package currently has the following feature sets</p>
<ul>
    <li>Some shorthand functions for frequently needed operations on the site: 
        <pre><code class="r">first(cars) # first non-missing value
last(cars) # last non-missing value
current(cars) # last value, even if missing
"formr." %contains% "mr." # will yield TRUE
"formr." %contains_word% "mr" # will yield FALSE
"12, 15" %contains% "1" # will yield TRUE
"12, 15" %contains_word% "1" # will yield FALSE</code></pre></li>
    <li>Some helper functions to make it easier to correctly deal with dates and times: 
        <pre><code class="r">time_passed(hours = 7) 
next_day()
in_time_window(time1, time2)</code></pre></li>
    <li>Connecting to formr, importing your data, correctly typing all variables, automatically aggregating scales.</li>
    <li>Easily making feedback plots e.g. <pre><code class="r">qplot_on_normal(0.8, "Extraversion")</code></pre>
        The package also has a function to simulate possible data, so you can try to make feedback plots ahead of collecting data.</li>
</ul>

<h4>Further data</h4>
<p>
    Sometimes, you need more than the data that formr auto-enriches your study with. For example, you might have designed a couples' diary study and need the partner's data to synchronize participation. In these cases, you will have to explicitly load the data using <a href="<?=site_url("documentation/#api")?>">formr's API</a>.
</p>
<p>
    Other times, you might want to import data from elsewhere on the web. You can R packages and functions to, for example, read a participant's social media posts or to look up information in an external, online database. 
</p>