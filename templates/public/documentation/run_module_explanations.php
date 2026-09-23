<h3>formr <i>Runs/Studies</i></h3><hr />
<p>
    A formr "run" contains your study's complete design. All the things that a single participant is going to do in your study should take place in one run. Designs can range from the simple (a single survey or a randomised experiment) to the complex (like a diary study with daily reminders by email and text message or a longitudinal study tracking social network changes).
</p>
<div class="panel-group">
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#more_run">More information about runs<br></a>
        </div>
        <div id="more_run" class="panel-collapse collapse more_run">
            <div class="panel-body">
                <p>
                    Inside a run, participants' data is connected, so you can track how many times a participant filled out her diary or whether her social network grew in size since the first measurement time point.
                </p>
                <p>
                    So, why "run"? In formr, runs consist of simple modules that are chained together linearly. Because most modules are boombox-themed, it may help to think of a tape running. Using controls such as the skip backward button, the pause button and the stop button, you control the participant's progression along the run. Surveys can be thought of as the record button: whenever you place a survey in your run, the participant can input data.
                </p>
                <p>
                    Data for the control units are supplied on-the-fly to the statistics programming language R. Therefore, you can dynamically generate feedback graphics for your participants with minimal programming knowledge. With more programming knowledge, nothing keeps you from making full use of R. You could for example conduct complex sentiment analyses on participants' tweets and invite them to follow-up surveys only if they express anger.
                </p>
                <p>
                    Since runs contain your study's complete design, it makes sense that runs' administration side is where every user management-related action takes place. There is a user overview, where you can see at which position in the run each participant is and when they were last active. Here, you can send people custom reminders (if they are running late), shove them to a different position in the run (if they get lost somewhere due to an unforeseen complication) or see what the study looks like for them (if they report problems).
                </p>
                <p>
                    Runs are also where you customise your study's look, upload files (such as images, videos, Javascript files), control access and enrollment. In addition, there are logs of every email sent, every position a participant has visited and of automatic progressions.
                </p>
            </div>
        </div>
    </div>
</div>
<h4>
    Participant links
</h4>
<p>
    Your run lives at <strong><?= run_url('yourRunName') ?></strong>. Once you set its access to <em>People who have the link can access</em>, anyone who opens that address is given a session and enrolled — for most studies this plain link is the only one you need to share.
</p>
<p>
    Each participant is identified by a <strong>session code</strong>. It travels in the link as <code>?code=…</code> and is also kept in a cookie, so a participant can close the tab and pick up where they left off. To send someone their own link, use <code>{{login_link}}</code> in an <a data-toggle="collapse" href="#email">Email</a>, or <code>{{login_code}}</code> to build a custom link yourself — for instance to invite informants to rate a participant.
</p>
<h5>Advanced: <small>starting a fresh session with <code>?_new_session=1</code></small></h5>
<p>
    Add <code>?_new_session=1</code> to your run link and <em>every</em> visit starts a brand-new session: formr generates a fresh session code and forwards the visitor to their own <code>?code=…</code> link. This is meant for links that cannot carry a personal code — a printed QR code or poster, a link handed to many raters at once, or one rater working through several targets in turn.
</p>
<ul>
    <li>Other parameters are carried across, so <code>?_new_session=1&amp;rated=P1</code> lands on <code>?code=…&amp;rated=P1</code> and <code>rated</code> is still available to your survey's items.</li>
    <li>It works only on the run's own address, not on a link to a particular page inside the run.</li>
    <li>It grants no extra access. The run still has to be set to <em>People who have the link can access</em>, exactly as for anyone else arriving without a code.</li>
    <li>An existing cookie is deliberately ignored: someone who already has a session gets a new, separate one instead of resuming the old one.</li>
    <li>Participants land on an ordinary <code>?code=…</code> link, so reloading or bookmarking <em>that</em> page keeps them in the session they just started.</li>
</ul>
<p>
    <small class="text-muted">Because every visit enrols someone new, don't use it for a link people might open twice — a reminder email, say. Use <code>{{login_link}}</code> there instead.</small>
</p>
<h4>
    Module explanations
</h4>
<div class="panel-group">
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#survey">
                <i class="fa-fw fa fa-pencil-square pull-left fa-3x"></i>
                Survey<br>
                <small>ask questions, get data</small></a>
        </div>
        <div id="survey" class="panel-collapse collapse survey">
            <div class="panel-body">
                <p>
                    Surveys are series of questions (or other items) that are created using simple spreadsheets/<a href="<?= site_url('public/documentation#sample_survey_sheet') ?>">item tables</a> (e.g. Excel).
                </p>
                <p>
                    Survey item tables are just spreadsheets and they can just as easily be shared, reused, recycled and collaboratively edited using e.g. Google Sheets.
                </p>
                <p>
                    Surveys can remain fairly simple: a bunch of items that belong together and that a participant can respond to in one sitting. For some people, simple surveys are all they need, but in formr a survey always has to be part of a (simple) run/study.
                </p>
                <p>
                    Surveys can feature various items, allowing e.g. numeric, textual input, agreement on a Likert scale, geolocation and so on.
                </p>
                <p>
                    Items can be optionally shown depending on the participant's responses in the same survey, in previous surveys and entirely different data sources (e.g. data gleaned from Facebook activity). Item labels and choice labels can also be customised using <a href="<?= site_url("public/documentation#knitr_markdown") ?>">knitr</a>, so you can e.g. refer to a participant's pet or last holiday location by name or use people's preferred term of address.
                </p>
                <p>
                    If you use R, formr includes a few nice timesavers in its <a href="https://github.com/rubenarslan/formr"><i class="fa fa-github-alt fa-fw"></i> R package on Github</a>. Data import can be automated without any funny format business. Items will be correctly typed according to the item table, not according to flawed heuristics. You will also have item and value labels available in R. <br>
                    If you name your items according to the schema <code>BFI_extra_2<i>R</i></code>, items with an R at the end can be automatically reversed and items ending on consecutive numbers with the same prefix will be aggregated to a mean score (with the name of the prefix). Using our <a href="https://cran.r-project.org/package=codebook">R package <i>codebook</i></a> you can then document the data, as well as automatically generate internal consistency analyses and item frequency plots. Hence, some tedious manual data wrangling can be avoided, especially if you start giving your items meaningful and memorable names early on. The <i>formr::</i> functions are also always available, whenever you use R inside formr runs and surveys.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#form_unit">
                <i class="fa-fw fa fa-wpforms pull-left fa-3x"></i>
                Form (v2)<br>
                <small>the same survey, rendered by the new form engine</small></a>
        </div>
        <div id="form_unit" class="panel-collapse collapse form_unit">
            <div class="panel-body">
                <p>
                    A Form unit does what a Survey unit does — it asks the questions defined in a study's item table — but renders them with the new <strong>form (v2) engine</strong>: the whole survey is loaded once as a single document, pages are submitted in the background without a reload, <code>showif</code>s react instantly in the browser, answers are autosaved, and a page that cannot reach the server is queued and replayed once the participant is back online. It also offers a one-item-per-screen "solo" layout and localized buttons.
                </p>
                <p>
                    The unit appears as <strong>Add Form (form_v2, beta)</strong> in the run editor once the instance administrator has enabled it. Choosing a study in the Form unit switches that study to the v2 engine; alternatively, upgrade an existing study from its settings page, which converts the Survey units using it into Form units. In R (run conditions, other surveys' labels) a Form's data is reached exactly like a survey's: <code>study_name$item</code>.
                </p>
                <p>
                    The one authoring rule that changes: in a Form, <code>showif</code> is JavaScript and may not contain <code>r(...)</code>; R belongs in the <code>value</code> column, and a hidden item's <code>value</code> is how an R result gets into a showif. Everything else — spreadsheet format, item types, R elsewhere in the run — stays the same. Read the full <a href="<?= site_url('documentation#form_v2') ?>">Form (v2) guide</a> before switching a study that is already collecting data.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#external">
                <i class="fa-fw fa fa-external-link-square pull-left fa-3x"></i>
                External link<br>
                <small>use modules outside formr</small></a>
        </div>
        <div id="external" class="panel-collapse collapse external">
            <div class="panel-body">
                <p>
                    These are external links - use them to send participants to other, specialised data collection modules, such as a social network generator, a reaction time task, another survey software (we won't be too sad), anything really. However, you can also simply call upon external functionality without sending the participant anywhere – one popular application of this is sending text messages via an R <abbr class="initialism" title="Application programming interface.">API</abbr> call.
                </p>
                <p>
                    If you insert the placeholder <code>{{login_code}}</code>, it will be replaced by the participant's run session code, allowing you to link data later (but only if your external module picks this variable up!).
                </p>
                <p>
                    Sometimes, you may find yourself wanting to do more complicated stuff like <b>(a)</b> sending along more data, the participant's age or sex for example, <b>(b)</b> calling an <abbr class="initialism" title="Application programming interface.">API</abbr> to do some operations before the participant is sent off (e.g. making sure the other end is ready to receive, this is useful if you plan to integrate formr tightly with some other software) <b>(c)</b> redirecting the participant to a large number of custom links (e.g. you want to redirect participants to the profile of the person who last commented on their Facebook wall to assess closeness) <b>(d)</b> you want to optionally redirect participants back to the run (e.g. as a fallback or to do complicated stuff in formr).
                </p>
                <p>
                    You can either choose to "finish/wrap up" this component <em>before</em> the participant is redirected (the simple way) or enable your external module to call our <abbr class="initialism" title="Application programming interface.">API</abbr> to close it only once the external component is finished (the proper way). If you do the latter, the participant will always be redirected to the external page until that page makes the call that the required input has been made.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#push_notification">
                <i class="fa-fw fa fa-bell pull-left fa-3x"></i>
                Push notification<br>
                <small>send notifications to participants</small></a>
        </div>
        <div id="push_notification" class="panel-collapse collapse push_notification">
            <div class="panel-body">
                <p>
                    Push notifications are a way to send notifications to participants. To be able to use them, you need to generate an application manifest in the run settings.
                </p>
                <p>
                    The manifest is a JSON file that describes your app. It is used to generate a PWA and a shortcut icon on the participant's device.
                </p>
                <p>
                    Once your study has a manifest, you can add special items (see App section of <a href="<?= site_url('documentation#available_items') ?>">available items</a>) like <code>add_to_homescreen</code> to your surveys. This will add a button to the participant's device that allows them to add your study to their homescreen. <code>request_phone</code> will guide the user to switch to a mobile device. And <code>push_notification</code> will ask the user to allow push notifications for your study. You can also insert the <code>request_cookie</code> item to explicitly ask participants to allow functional cookies which won't expire when the app is closed.
                </p>
                <p>
                    Sending push notifications to the user can be configured in the run. Be aware that notifications of "normal" priority can be delayed or supressed by the participants phone.You can define when a notification should expire, what message should be shown, whether the user needs to interact with the notification, whether it is silent or whether it should vibrate. Push notifications are automatically dismissed once the user opens the app. As long as push notifications exist, a badge counter (1) will be shown on the app on the operating systems that support it.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#email">
                <i class="fa-fw fa fa-envelope pull-left fa-3x"></i>
                Email<br>
                <small>invite, remind, send feedback</small></a>
        </div>
        <div id="email" class="panel-collapse collapse email">
            <div class="panel-body">
                <p>
                    Using an SMTP account (most email addresses come with one) that you can <a href="<?= site_url("admin/mail") ?>">set up in the mail section</a>, you can send emails to your participants, their friends or yourself. Using the tag <code>{{login_link}}</code>, you can send participants a personalised link to the run. You can also use <code>{{login_code}}</code> to use the session code to create custom links, e.g. for inviting peers to rate this person (informants). Many ISPs limit using their SMTP server to send automated email. Gmail users cannot send more than 500 emails a day and have to disable some advanced security features. Vendors like <a href="https://sendgrid.com">Sendgrid</a> offer free student accounts as part of the <a href="https://education.github.com/">Github education pack</a> and are more amenable to automated emails via SMTP.
                </p>
                <h5>
                    Example 1: <small>email to participants or their friend</small>
                </h5>
                <p>
                    A simple one-shot survey with feedback. Let's say your run contains
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey called <strong>big5</strong> which assesses the big 5 personality traits and asks for the participant's email address (the field is called <code>email_address</code>).
                    </li>
                    <li>
                        <i class="fa-li fa fa-envelope"></i> 		Pos. 20. an email with a feedback plot of the participant's big 5 scores. The recipient field contains <code>big5$email_address</code>.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 30. Displays the same feedback as in the email to the participants.
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    A participant fills out your survey. After completing it, they see the feedback page, which contains a bar chart of their individual big 5 scores. Before they see the page marked by the stop point, an email containing the same feedback is sent off to their email address - this way they get a take-home copy as well.
                </p>
                <h5>
                    Example 2: <small>email to yourself</small>
                </h5>
                <p>
                    A simple one-shot survey after which you receive a notification.
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey called <strong>big5</strong> as above.
                    </li>
                    <li>
                        <i class="fa-li fa fa-envelope"></i> 		Pos. 20. an email containing the participant's code. The recipient field contains <code>'youremailaddress@example.org'</code>. Note the single quotes, they mean that this is a constant.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 30. Display a thank you note.
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    A participant fills out your survey. After completing it, they see the thank you note at pos. 30. Before they see the page marked by the stop point, an email is sent off to <em>youremailaddress@example.org</em> - this way you (or whoever's email address you use here) would get an email notification for every participant. This might be helpful in longitudinal surveys where experimenter intervention is required to e.g. set up a phone interview, in an assessment context when you want the scores to be automatically generated, or in a clinical study where you want to do a structured interview after a screening task.
                </p>
                <p>
                    See the  <a href="<?= site_url("public/documentation#knitr_markdown") ?>">Knitr &amp; Markdown</a> section to find out how to generate personalised emails, which contain feedback, including plots. In the <a href="<?= site_url("public/documentation#skip_backward") ?>">next section</a>, you'll learn how to use the email module for invitations in a diary study.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#skip_backward">
                <i class="fa-fw fa fa-backward pull-left fa-3x"></i>
                Skip Backward<br>
                <small>create loops</small></a>
        </div>
        <div id="skip_backward" class="panel-collapse collapse skip_backward">
            <div class="panel-body">
                <p>
                    Skip backward allows you to jump back in the run, if a specific condition is fulfilled.
                </p>
                <p>
                    This way, you can create a <strong>loop</strong>. Loops, especially in combination with reminder emails are useful for <strong>diary</strong>, <strong>training</strong>, and <strong>experience sampling</strong> studies.<br>
                </p>
                <p>
                    The condition is specified in R and all necessary survey data is automatically available. The simplest condition would be <code>TRUE</code> – always skip back, no matter what. A slightly more complex one is <code>nrow(diary) &lt; 14</code>, this means that the diary must have been filled out at least fourteen times. Even more complex: <code>nrow(diary) &lt; 14 | !time_passed(days = 20, time = first(diary$created))</code>, this means that at least 20 days must have passed since the first diary was done and that at least 14 diaries must have been filled out. But any complexity is possible, as shown in Example 2.
                </p>
                <h5>
                    Example 1:
                </h5>
                <p>
                    A simple diary. Let's say your run contains
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey in which you find out the participant's email address
                    </li>
                    <li>
                        <i class="fa-li fa fa-pause"></i> 			Pos. 20. a pause which always waits until 6PM on the next day
                    </li>
                    <li>
                        <i class="fa-li fa fa-envelope"></i> 		Pos. 30. an email invitation
                    </li>
                    <li>	
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 40. a survey called <strong>diary</strong> containing your diary questions
                    </li>
                    <li>
                        <i class="fa-li fa fa-backward"></i> 		Pos. 50. You would now add a Skip Backward with the following condition: <code>nrow(diary) &lt; 14</code> and the instructions to jump back to position 20, the pause, if that is true.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 60. At this position you could then use a Stop point, marking the end of your diary study.
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    Starting at 20, participants would receive their first invitation to the diary at 6PM the next day after enrolling. After completion, the Skip Backward would send them back to the pause, where you could thank them for completing today's diary and instruct them to close their web browser. Automatically, once it is 6PM the next day, they would receive another invitation, complete another diary etc. Once this cycle repeated 14 times, the condition would no longer be true and they would progress to position 60, where they might receive feedback on their mood fluctuation in the diary.
                </p>
                <h5>
                    Example 2:
                </h5>
                <p>
                    But you can also make a loop that doesn't involve user action, to periodically check for external events:
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a short survey called <strong>location</strong> that mostly just asks for the participants' GPS coordinates and contact info
                    </li>
                    <li>
                        <i class="fa-li fa fa-pause"></i> 			Pos. 20. a pause which always waits one day
                    </li>
                    <li>
                        <i class="fa-li fa fa-backward"></i> 		Pos. 30. A Skip Backward checks which checks the weather at the participant's GPS coordinates. If no thunderstorm occurred there, it jumps back to the pause at position 20. If a storm occurred, however, it progresses.
                    </li>
                    <li>
                        <i class="fa-li fa fa-envelope"></i> 		Pos. 40. an email invitation
                    </li>
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 50. a survey called <strong>storm_mood</strong> containing your questions regarding the participant's experience of the storm.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 60. A stop button, ending the study.
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    In this scenario, the participant takes part in the short survey first. We obtain the geolocation, which can be used to retrieve the local weather using API calls to weather information services in the Skip Backward at position 30. The weather gets checked once each day (pause at 20) and if there ever is a thunderstorm in the area, the participant is invited via email (40) to take a survey (50) detailing their experience of the thunderstorm. This way, the participants only get invited when necessary, we don't have to ask them to report weather events on a daily basis and risk driving them away.
                </p>
                <h4>Advanced: <small>computed jumps (return a position number)</small></h4>
                <p>
                    Instead of a TRUE/FALSE condition, your R code can return a <strong>position number of 2 or higher</strong> — the participant then jumps straight to that position, ignoring the "skip backward to" field. This lets a single unit send different participants to different positions: e.g. <code>c(30, 50, 70)[screener$arm]</code> routes arm 1 to 30, arm 2 to 50, arm 3 to 70. The same works in a Skip Forward.
                </p>
                <p>How formr reads the value your code returns:</p>
                <ul>
                    <li><code>TRUE</code> / <code>FALSE</code> — the ordinary skip: jump to the position in the field below, or carry on to the next unit.</li>
                    <li><strong><code>0</code> and <code>1</code> always mean FALSE and TRUE</strong>, never positions 0 and 1. Conditions that return 1/0 rather than TRUE/FALSE — <code>screener$consent</code> on a 1/0 coded item, <code>sum(x)</code>, <code>nrow(df)</code> — therefore keep working exactly as they always have. If you genuinely need to send someone to position 1, renumber the run.</li>
                    <li><strong><code>2</code> or higher</strong> — an absolute position to jump to.</li>
                    <li><strong>Decimals are rounded to the nearest whole number</strong>, with halves rounding up: <code>29.5</code> and <code>30.4</code> both mean position 30. Rounding happens first, so <code>1.9</code> becomes 2 and does jump.</li>
                    <li><strong>Several values</strong> (a vector) — only the first one is used, and a warning is recorded in the unit's log. Return a single value.</li>
                    <li><strong>Anything else</strong> — a negative number, text, a date — falls back to a best-effort TRUE/FALSE reading and records a warning. Don't rely on it.</li>
                    <li><strong>NA or an empty result</strong> (<code>logical(0)</code>, <code>NULL</code> — typically a comparison against a column that does not exist for this participant) halts the unit and emails you; the message names the fix, e.g. <code>isTRUE(survey$consent == "yes")</code>. Before formr v1.10.3 an empty result silently meant FALSE.</li>
                    <li><strong>Skip units created before formr v1.10.3</strong> have their condition wrapped in <code>as.logical({ … })</code>. Before computed jumps existed, any non-zero number meant TRUE, and the wrapper keeps that reading (<code>as.logical(25)</code> is TRUE) so an older study is not rerouted. A wrapped condition that returns an R <code>Date</code> or <code>POSIXct</code> value also keeps its old reading (a past moment is TRUE, a future one FALSE); a date <em>string</em> is NA and halts. To use a position number in such a unit, remove the wrapper.</li>
                </ul>
                <p>A few more things worth knowing:</p>
                <ul>
                    <li>The number must match a unit's position <strong>exactly</strong>; there is no nearest-match. If no unit sits at that position, the participant simply carries on with the next unit after the skip and the study's admin is emailed — nobody is ever stranded.</li>
                    <li>Direction is up to your number, not the unit type: a Skip Backward whose code returns a position ahead of it moves the participant forward.</li>
                    <li>The number is a position, not a unit — re-check your computed jumps after reordering a run.</li>
                </ul>
                <p>
                    <small class="text-muted">The <em>Routes to</em> column in the unit's Test view shows the destination formr would actually pick for each participant — use it to check your code before you send anyone through.</small>
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#pause">
                <i class="fa-fw fa fa-pause pull-left fa-3x"></i>
                Pause<br>
                <small>delay continuation</small></a>
        </div>
        <div id="pause" class="panel-collapse collapse pause">
            <div class="panel-body">
                <p>
                    This simple component allows you to delay the continuation of the run, be it<br>
                    until a certain date (01.01.2014 for research on new year's hangovers),<br>
                    time of day (asking participants to sum up their day in their diary after 7PM)<br>
                    or to wait relative to a date that a participant specified (such as her graduation date or the last time he cut his nails).
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey collecting personality and contact info + the graduation data of university students
                    </li>
                    <li>
                        <i class="fa-li fa fa-pause"></i> 			Pos. 20. a pause which waits until 4 months after graduation
                    </li>
                    <li>
                        <i class="fa-li fa fa-envelope"></i> 		Pos. 30. an email invitation
                    </li>
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 40. another personality survey 
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 50. A stop button, ending the study. On this last page, the students get feedback on how their personality has changed after graduation.
                    </li>
                </ul>
                <p>
                    See the <a href="#knitr_markdown">Knitr &amp; Markdown</a> section to find out how to personalise the text shown while waiting.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#skip_forward">
                <i class="fa-fw fa fa-forward pull-left fa-3x"></i>
                Skip Forward<br>
                <small>filters/screenings, paths</small></a>
        </div>
        <div id="skip_forward" class="panel-collapse collapse skip_forward">
            <div class="panel-body">
                <p>
                    Skip forward allows you to jump forward in the run, if a specific condition is fulfilled. 
                </p>
                <p>
                    This way, you can create <strong>filters</strong>, and parallel paths or branches in a study. Filters are useful to screen participants. You may need parallel paths in a study to randomise people to one experimental branch out of many, or to make sure a certain part of your study is only completed by those for whom it is relevant.
                </p>
                <h4>
                    Example 1: <small>a filter/screening</small>
                </h4>
                <p>
                    Let's say your run contains
                </p>
                <ul class="fa-ul">
                    <li>
                        <i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey (depression) which has an item about suicidality
                    </li>
                    <li>
                        <i class="fa-li fa fa-forward"></i> 		Pos. 20. a Skip Forward which checks <code>depression$suicidal != 1</code>. If the person is not suicidal, it skips forward to pos 40.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 30. At this position you would use a Stop point. Here you could give the participant the numbers for suicide hotlines and tell them they're not eligible to participate.
                    </li>
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 40. Here you could do your real survey.
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 50. A stop button, ending the study. 
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    Starting at 10, participants would complete a survey on depression. If they indicated suicidal tendencies, they would receive the numbers for suicide hotlines at which point the run would end for them. If they did not indicate suicidal tendencies, they would be eligible to participate in the main survey.
                </p>
                <h4>
                    Example 2: <small>different paths</small>
                </h4>
                <p>
                    Let's say your run contains
                </p>
                <ul class="fa-ul">
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 10. a survey on optimism (optimism)
                    </li>
                    <li><i class="fa-li fa fa-forward"></i> 		Pos. 20. a Skip Forward which checks <code>optimism$pessimist == 1</code>. If the person is a pessimist, it skips forward to pos 50.
                    </li>
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 30. a survey tailored to optimists
                    </li>
                    <li><i class="fa-li fa fa-forward"></i> 		Pos. 40. a Skip Forward which checks <code>TRUE</code>, so it always skips forward to pos 60.
                    </li>
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 50. a survey tailored to pessimists
                    </li>
                    <li>
                        <i class="fa-li fa fa-stop"></i> 			Pos. 60. At this position you would thank both optimists and pessimists for their participation.
                    </li>
                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    Starting at 10, participants would complete a survey on optimism. If they indicated that they are pessimists, they fill out a different survey than if they are optimists. Both groups receive the same feedback at the end. It is important to note that we have to let the optimists jump over the survey tailored to pessimists at position 40, so that they do not have to take both surveys.
                </p>
                <h4>Advanced: <small>computed jumps (return a position number)</small></h4>
                <p>
                    Instead of a TRUE/FALSE condition, your R code can return a <strong>position number of 2 or higher</strong> — the participant then jumps straight to that position, ignoring the "skip forward to" field. This lets a single unit send different participants to different arms: e.g. <code>c(30, 50, 70)[screener$arm]</code> routes arm 1 to 30, arm 2 to 50, arm 3 to 70. The same works in a Skip Backward.
                </p>
                <p>How formr reads the value your code returns:</p>
                <ul>
                    <li><code>TRUE</code> / <code>FALSE</code> — the ordinary skip: jump to the position in the field below, or carry on to the next unit.</li>
                    <li><strong><code>0</code> and <code>1</code> always mean FALSE and TRUE</strong>, never positions 0 and 1. Conditions that return 1/0 rather than TRUE/FALSE — <code>screener$consent</code> on a 1/0 coded item, <code>sum(x)</code>, <code>nrow(df)</code> — therefore keep working exactly as they always have. If you genuinely need to send someone to position 1, renumber the run.</li>
                    <li><strong><code>2</code> or higher</strong> — an absolute position to jump to.</li>
                    <li><strong>Decimals are rounded to the nearest whole number</strong>, with halves rounding up: <code>29.5</code> and <code>30.4</code> both mean position 30. Rounding happens first, so <code>1.9</code> becomes 2 and does jump.</li>
                    <li><strong>Several values</strong> (a vector) — only the first one is used, and a warning is recorded in the unit's log. Return a single value.</li>
                    <li><strong>Anything else</strong> — a negative number, text, a date — falls back to a best-effort TRUE/FALSE reading and records a warning. Don't rely on it.</li>
                    <li><strong>NA or an empty result</strong> (<code>logical(0)</code>, <code>NULL</code> — typically a comparison against a column that does not exist for this participant) halts the unit and emails you; the message names the fix, e.g. <code>isTRUE(survey$consent == "yes")</code>. Before formr v1.10.3 an empty result silently meant FALSE.</li>
                    <li><strong>Skip units created before formr v1.10.3</strong> have their condition wrapped in <code>as.logical({ … })</code>. Before computed jumps existed, any non-zero number meant TRUE, and the wrapper keeps that reading (<code>as.logical(25)</code> is TRUE) so an older study is not rerouted. A wrapped condition that returns an R <code>Date</code> or <code>POSIXct</code> value also keeps its old reading (a past moment is TRUE, a future one FALSE); a date <em>string</em> is NA and halts. To use a position number in such a unit, remove the wrapper.</li>
                </ul>
                <p>A few more things worth knowing:</p>
                <ul>
                    <li>The number must match a unit's position <strong>exactly</strong>; there is no nearest-match. If no unit sits at that position, the participant simply carries on with the next unit after the skip and the study's admin is emailed — nobody is ever stranded.</li>
                    <li>Direction is up to your number, not the unit type: a Skip Forward whose code returns a position behind it moves the participant backward.</li>
                    <li>The number is a position, not a unit — re-check your computed jumps after reordering a run.</li>
                </ul>
                <p>
                    <small class="text-muted">The <em>Routes to</em> column in the unit's Test view shows the destination formr would actually pick for each participant — use it to check your code before you send anyone through.</small>
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#waiting_time">
                <i class="fa-fw fa fa-hourglass-half pull-left fa-3x"></i>
                Waiting Time<br>
                <small>reminders</small></a>
        </div>
        <div id="waiting_time" class="panel-collapse collapse skip_forward">
            <div class="panel-body">
                <p>
                    Waiting Time are like Pauses, but instead of making the participant wait, we wait for the participant.
                </p>
                <p>
                    By waiting for the participant for a certain amount of time, we can make sure that people are reminded to participate in our diary study after one hour—but only if they need a reminder. We can also make sure that a part of a study is only accessible at certain times of day.
                </p>
                <h4>
                    Example 1: <small>reminder</small>
                </h4>
                <p>
                    Let's say your run contains
                </p>
                <ul class="fa-ul">
                    <li><i class="fa-li fa fa-pause"></i> 			Pos. 10. a pause (e.g. let's say we know when exchange students will arrive in their host country, and they cannot answer questions before they've been there one week)</li>
                    <li><i class="fa-li fa fa-envelope"></i> 		Pos. 20. Now we have to send our exchange students an email to invite them to do the survey.</li>
                    <li><i class="fa-li fa fa-hourglass-half"></i> 		Pos. 30. a Waiting Time for 7 days. If the user clicks the link to answer questions, the study jumps to position 50, the survey. If two weeks go by without a reaction, the study moves on to the next position, the reminder.</li>
                    <li><i class="fa-li fa fa-envelope"></i> 		Pos. 40. This is our email reminder for the students who did not react after 7 days.</li>
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 50. the survey we want the exchange students to fill out. We set an access window of 7 weeks for this survey (in the survey settings), so we wait at most 7 weeks for students to fill the survey out.</li>
                    <li><i class="fa-li fa fa-pause"></i> 			Pos. 60. Because this is a longitudinal study, we now wait for our exchange students to return home. The rest is left out.</li>

                </ul>
                <h5>
                    What would happen?
                </h5>
                <p>
                    The pause would simply lead to all exchange students being invited once they've been in their host country for a week (we left out the part where we obtained or entered the necessary information). After the invitation, however, we don't just give up, if they don't react. After another week has passed (one week in the host country), we remind them.<br>
                    How is this done? We set a waiting time for the participant of 7 days. <br>
                    Now <a href="https://www.youtube.com/watch?v=JVHVZksFRZg#t=0m39s">if he doesn't answer</a> for one week, the run will automatically go on to 40, to our email reminder (tentatively titled "Oh lover boy..."). We hope the participant clicks on the link in our invitation email before then though.<br>
                    If he does, he will jump to the survey at position 60.<br>
                    <a href="https://www.youtube.com/watch?v=JVHVZksFRZg#t=0m43s">If he still doesn't answer</a>, we will patiently wait for another seven weeks. This time, we set an expiry time in the survey settings to achieve this. Until seven weeks have passed he can do the survey. Once the seven weeks are over without him finishing the survey, the run moves on to the next position, which stands for waiting for return home, i.e. we gave up on getting a reaction in the first wave (but we still have "Baby, oh baby, My sweet baby, you're the one" up our sleeve).
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#stop_point">
                <i class="fa-fw fa fa-stop pull-left fa-3x"></i>
                Stop Point<br>
                <small>you need at least one</small></a>
        </div>
        <div id="stop_point" class="panel-collapse collapse stop_point">
            <div class="panel-body">
                <p>
                    You will always need at least one. These are stop points in your run, where you can give short or complex feedback, ranging from "You're not eligible to participate." to "This is the scatter plot of your mood and your alcohol consumption across the last two weeks".
                </p>
                <p>
                    If you combine these end points with Skip Forward, you can have several in your run: You would use the Skip Forward to check whether participants are eligible, and if so, skip over the stop point between the Skip Forward and the survey that they are eligible for. This way, ineligible participants end up in a dead end before the survey. In the edit run interface, you can see green counts of the number of people on this position on the left, so you can see easily how many people are ineligible by checking the count.<br>
                    See the <a href="#knitr_markdown" data-toggle="tab">Knitr &amp; Markdown</a> section to find out how to generate personalised feedback, including plots.
                </p>
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#shuffle">
                <i class="fa-fw fa fa-random pull-left fa-3x"></i>
                Shuffle<br>
                <small>randomise participants</small></a>
        </div>
        <div id="shuffle" class="panel-collapse collapse shuffle">
            <div class="panel-body">
                <p>
                    This is a very simple component. You simply choose how many groups you want to randomly assign your participants to. We start counting at one (1), so if you have two groups you will check <code>shuffle$group == 1</code> and <code>shuffle$group == 2</code>. You can read a person's group using <code>shuffle$group</code>. If you generate random groups at more than one point in a run, you might have to use the last one <code>tail(shuffle$group,1)</code> or check the unit id <code>shuffle$unit_id</code>, but usually you needn't do this.
                </p>
                <p>
                    If you combine a Shuffle with Skip Forward, you could send one group to an entirely different arm/path of the study. But maybe you just want to randomly switch on a specific item in a survey - then you would use a "showif" in the survey item table containing e.g. <code>shuffle$group == 2</code>. The randomisation always has to occur before you try to use the number, but the participants won't notice it unless you tell them somehow (for example by switching on a note telling them which group they've been assigned to).
                </p>
            </div>
        </div>
    </div>
</div><!-- closes panel group-->
