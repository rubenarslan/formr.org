<h3>R Helpers</h3><hr />

<p>
	Wherever you use R in formr you can also use the functions in its R package. If you want to use the package in a different environment,
	you'll need to install it using these two lines of code.	
</p>
<pre><code class="r">install.packages("devtools")
devtools::install_github("rubenarslan/formr")</code></pre>
<p>The package currently has the following feature sets</p>
<ul>
	<li>Some shorthand functions for frequently needed operations on the site: 
<pre><code class="r">first(cars); 
last(cars); 
current(cars); 
"formr." %contains% "mr."</code></pre></li>
	<li>Some helper functions to make it easier to correctly deal with dates and times: 
<pre><code class="r">time_passed(hours = 7); 
next_day(); 
in_time_window(time1, time2);</code></pre></li>
	<li>Connecting to formr, importing your data, correctly typing all variables, automatically aggregating scales.</li>
	<li>Easily making feedback plots e.g. <pre><code class="r">qplot_on_normal(0.8, "Extraversion")</code></pre>
		The package also has a function to simulate possible data, so you can try to make feedback plots ahead of collecting data.</li>
</ul>