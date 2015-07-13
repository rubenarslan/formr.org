<?php
    Template::load('header_nav');
?>
<div class="row">
	<div class="col-lg-5 col-lg-offset-1 col-sm-7 col-sm-offset-1 col-xs-12">
		<h3><i class="fa fa-fw fa-coffee"></i> Team</h3>
		<p>
			The most recent addition to our team is <a href="https://www.psych.uni-goettingen.de/de/it/team/cyril-tata/cyril-s-tata">Cyril Tata</a>.
		</p>
		<p>
			formr was mostly made by <a href="https://www.psych.uni-goettingen.de/en/biopers/team/arslan">Ruben C. Arslan</a>. <br>The current incarnation of the survey framework draws on prior work by Linus Neumann, prior funding by Jaap J. A. Denissen, ideas, testing, and feedback by Sarah J. Lennartz and Isabelle Habedank.
		</p>
		
		<h3><i class="fa fa-fw fa-money"></i> Funding</h3>
		<p>Friedrich-Schiller-University Jena – <a href="http://dfg.de/">DFG</a> <a href="http://www.kompass.uni-jena.de">project "Kompass"</a>, PIs: <a href="http://www.uni-jena.de/en/Faculties/Social+and+Behavioral+Sciences/Institutes+_+Departments/Institute+of+Psychology/Departments/Differential+Psychology+and+Personality+Psychology/Personality+Psychology+and+Psychological+Assessment/Julia+Zimmermann.html">Julia Zimmermann</a>, <a href="https://www.uni-jena.de/Fakult%C3%A4ten/Sozial_+und+Verhaltenswissenschaften/Institute_Lehrst%C3%BChle/Institut+f%C3%BCr+Psychologie/Abteilungen/Lehrstuhl+f%C3%BCr+Differentielle+Psychologie_+Pers%C3%B6nlichkeitspsychologie+und+Psychologische+Diagnostik/Franz+Neyer.html">Franz J. Neyer</a>
		</p>
		<p>Georg August University Göttingen – <a href="https://psych.uni-goettingen.de/en/biopers/team/penke">Lars Penke</a>, current hosting</p>
		
		<h4><i class="fa fa-fw fa-github"></i> Other credit</h4>
		<p>
			formr is open source software and uses a lot of other free software, see the <a href="https://github.com/rubenarslan/formr.org">Github repository</a> for some due credit. Most importantly, formr uses <a href="http://opencpu.org">OpenCPU</a> as its R backend.
		</p>

	</div>
	<div class="col-lg-3 col-lg-offset-1" style="text-align:center">
		<p class="lead">
			<img src="<?=WEBROOT?>assets/img/goettingen_uni.png" alt="Uni Göttingen logo"><br>
			<small><small>Georg August University Göttingen</small></small>
		</p>
		
		<p class="lead">
			<img src="<?=WEBROOT?>assets/img/jena_uni.png" alt="Uni Jena logo"><br>
			<small><small>Friedrich Schiller University Jena</small></small>
		</p>
	</div>
</div>
<?php
    Template::load('footer');
?>
