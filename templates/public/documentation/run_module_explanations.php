<h3>formr <i>Runs</i></h3><hr />
<p>
    A formr "run" contains your study's complete design. Designs can range from the simple (a single survey or a randomised experiment) to the complex (like a diary study with daily reminders by email and text message or a longitudinal study tracking social network changes).
</p>
<div class="panel-group">
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#more_run">More information about runs<br></a>
        </div>
        <div id="more_run" class="panel-collapse collapse more_run">
            <div class="panel-body">
                <p>
                    Inside a run, participants' data can be connected, so you can track how many times a participant filled out her diary or whether her social network grew in size since the first measurement timepoint.
                </p>
                <p>
                    So, why "run"? In formr, runs consist of simple modules that are chained together linearly. Because most modules are boombox-themed, it may help to think of a tape running. Using controls such as the skip backward button, the pause button and the stop button, you control the participant's progression along the run. Surveys can be thought of as the record button: whenever you place a survey in your run, the participant can input data.
                </p>
                <p>
                    Because data are supplied on-the-fly to the statistics programming language R, you can dynamically generate feedback graphics for your participants with minimal programming knowledge. With more programming knowledge, nothing keeps you from making full use of R. You could for example conduct complex sentiment analyses on participants' tweets and invite them to follow-up surveys only if they express anger.	
                </p>
                <p>
                    Since runs contain your study's complete design, it makes sense that runs' administration side is where every user management-related action takes place. There is an overview of users, where you can see at which position in the run each participant is and when they were last active. Here, you can send people custom reminders (if they are running late), shove them to a different position in the run (if they get lost somewhere due to an unforeseen complication) or see what the study looks like for them (if they report problems).
                </p>
                <p>
                    Runs are also where you customise your study's look, upload files (such as images), control access and enrollment. In addition, there are logs of every email sent, every position a participant has visited and of automatic progressions (the cron job).
                </p>
            </div>
        </div>
    </div>
</div>
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
                    Survey item tables are just spreadsheets – and they can just as easily be shared, reused, recycled and collaboratively edited using e.g. Google Sheets.
                </p>
                <p>
                    Surveys can remain fairly simple: a bunch of items that belong together and that a participant can respond to in one sitting. For some people, simple surveys are all they need, but in formr a survey always has to be part of a (simple) run.
                </p>
                <p>
                    Surveys can feature various items, allowing e.g. numeric, textual input, agreement on a Likert scale, geolocation and so on.
                </p>
                <p>
                    Items can be optionally shown depending on the participant's responses in the same survey, in previous surveys and entirely different data sources (e.g. data gleaned from Facebook activity). Item labels and choice labels can also be customised using <a href="<?= site_url("public/documentation#knitr_markdown") ?>">knitr</a>, so you can e.g. refer to a participant's pet or last holiday location by name or address men and women differently.
                </p>
                <p>
                    For R-savvy personality psychologists, formr includes a few nice timesavers. Data import can be automated without any funny format business and items will be correctly typed according to the item table, not according to flawed heuristics.<br>
                    If you name your items according to the schema <code>BFI_extra_2<i>R</i></code>, items with an R at the end can be automatically reversed and items ending on consecutive numbers with the same prefix will be aggregated to a mean score (with the name of the prefix). Internal consistency analyses and item frequency plots can also be automatically generated. Hence, some tedious manual data wrangling can be avoided and as an added benefit, you will start giving your items meaningful and memorable names early on. The relevant functions can be found in the <a href="https://github.com/rubenarslan/formr"><i class="fa fa-github-alt fa-fw"></i> R package on Github</a>. The functions are also always available, whenever you use R inside formr runs and surveys.
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
                    These are external links - use them to send participants to other, specialised data collection modules, such as a social network generator, a reaction time task, another survey software (we won't be too sad), anything really. However, you can also simply call upon external functionality without sending the participant anywhere – one popular application of this is sending text messages.
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
            <a class="accordion-toggle" data-toggle="collapse" href="#email">
                <i class="fa-fw fa fa-envelope pull-left fa-3x"></i>
                Email<br>
                <small>invite, remind, send feedback</small></a>
        </div>
        <div id="email" class="panel-collapse collapse email">
            <div class="panel-body">
                <p>
                    Using an SMTP account (most email addresses come with one) that you can <a href="<?= site_url("admin/mail") ?>">set up in the mail section</a>, you can send emails to your participants, their friends or yourself. Using the tag <code>{{login_link}}</code>, you can send participants a personalised link to the run. You can also use <code>{{login_code}}</code> to use the session code to create custom links, e.g. for inviting peers to rate this person (informants). Many ISPs limit using their SMTP server to send automated email. Gmail users cannot send more than 500 emails a day and have to disable some advanced security features. Vendors like <a href="http://sendgrid.com">Sendgrid</a> offer free student accounts as part of the <a href="https://education.github.com/">Github education pack</a> and are more amenable to automated emails via SMTP.
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
                    A participant fills out your survey. After completing it, they see the thank you note at pos. 30. Before they see the page marked by the stop point, an email is sent off to <em>youremailaddress@example.org</em> - this way you (or whoever's email address you use here) would get an email notification for every notification. This might be helpful in longitudinal surveys where experimenter intervention is required to e.g. set up a phone interview or in a clinical study where you want to do a structured interview after a screening task.
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
                    <li><i class="fa-li fa fa-forward"></i> 		Pos. 20. a Skip Forward which checks <code>optimism$pessimist == 1</code>. If the person is a pessimist, it skips forward to pos 5.
                    </li>
                    <li><i class="fa-li fa fa-pencil-square"></i> 	Pos. 30. a survey tailored to optimists
                    </li>
                    <li><i class="fa-li fa fa-forward"></i> 		Pos. 40. a Skip Forward which checks <code>TRUE</code>, so it always skips forward to pos 6.
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
            </div>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <a class="accordion-toggle" data-toggle="collapse" href="#skip_forward">
                <i class="fa-fw fa fa-hourglass-half pull-left fa-3x"></i>
                Waiting Time<br>
                <small>reminders</small></a>
        </div>
        <div id="skip_forward" class="panel-collapse collapse skip_forward">
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
