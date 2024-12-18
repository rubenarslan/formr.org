<h3>Knit R &amp; Markdown</h3><hr />

<h4>
    This section gives some guidance on how to format and customise text in formr. In many cases you'll do it right by default. You'll also see how to access the data you just collected in formr in R — for example to score an assessment, give feedback, or customise the study in other ways.
</h4>
<h5>
    Markdown
</h5>
<p>
    You can format text/feedback everywhere (i.e. item labels, choice labels, the feedback shown in pauses, stops, in emails) in a natural fashion using <a href="http://daringfireball.net/projects/markdown/syntax" title="Go to this link for a more exhaustive guide">Github-flavoured Markdown</a>.<br>
    The philosophy is that you write like you would in a plain-text email and Markdown turns it nice.<br>
    In most cases, characters with special meaning won't entail unintended side effects if you use them normally, but if you ever need to specify that they shouldn't have side effects, escape it with a backslash: <code>\*10\*</code> doesn't turn italic. 
</p>
<pre>
* list item 1
* list item 2
</pre>
<p>
    will turn into a nice bulleted list.
</p>
<ul>
    <li>list item 1
    </li>
    <li>list item 2
    </li>
</ul>
<p>
    <code>#</code> at the beginning of a line turns it into a large headline, <code>##</code> up to <code>######</code> turn it into smaller ones.
</p> 
<p>
    <code>*<em>italics</em>* and __<strong>bold</strong>__</code> are also easy to do.
</p>
<p><code>[<a href="http://yihui.name/knitr/">Named links</a>](http://yihui.name/knitr/)</code> and embedded images <code>![image description](http://imgur.com/imagelink)</code> are easy. If you simply paste a link, it will be clickable automatically too, even easier. Email addresses are a bit special, you need the "mailto:" prefix: [<a href="mailto:contact_email@example.com">Contact us</a>](mailto:contact_email@example.com).
</p>

<p>
    You can quote something by placing a &gt; at the beginning of the line.
</p>
<p>
    If you're already familiar with <a href="https://en.wikipedia.org/wiki/HTML"><abbr title="Hypertext Markup Language">HTML</abbr></a> you can also use that instead, though it is a little less readable for humans. Or mix it with Markdown! You may for example use it to go beyond Markdown's features and e.g. add icons to your text using <code>&lt;i class="fa fa-smile-o"&gt;&lt;/i&gt;</code> to get <i class="fa fa-smile-o"></i> for instance. Check the full set of available icons at <a href="http://fontawesome.io/icons/">Font Awesome</a>.
</p>
<h5>
    Knitr
</h5>
<p>
    If you want to customise the text or generate custom feedback, including plots, you can use <a href="http://yihui.name/knitr/">Knitr</a>. Thanks to Knitr you can freely mix Markdown and chunks of R. You can load data using R commands, but the data you just collected for this participant will automatically be made available as R data frames. See <a href="<?=site_url("documentation/#r_helpers")?>">R helpers</a> for more information.
     Some examples:
</p>
<ul class="fa-ul">
    <li>
        <i class="fa-li fa fa-calendar"></i>

        <code>Today is `r date()`</code> shows today's date.<br>
    </li>
    <li>
        <i class="fa-li fa fa-user"></i>

        <code>Hello `r demographics$name`</code> greets someone using the variable "name" from the survey "demographics".<br>
    </li>
    <li>
        <i class="fa-li fa fa-female"></i>

        <code>Dear `r ifelse(demographics$sex == 1, 'Sir', 'Madam')`</code> greets someone differently based on the variable "sex" from the survey "demographics".<br>
    </li>
    <li>
        <i class="fa-li fa fa-bar-chart-o"></i>
        You can also plot someone's extraversion on the standard normal distribution.
        <pre>```{r}
<code class="r">library(formr)
# build scales automatically
big5 = formr_aggregate(results = big5)
# standardise
big5$extraversion = scale(big5$extraversion, center = 3.2, scale = 2.1)

# plot
qplot_on_normal(big$extraversion, xlab = "Extraversion")
</code>```
        </pre>yields<br>
        <img src="<?= asset_url('build/img/examples/example_fb_plot.png') ?>" width="330" height="313" alt="Graph of extraversion bell curve feedback">
    </li>
</ul>