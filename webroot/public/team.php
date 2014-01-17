<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."View/header.php";

require_once INCLUDE_ROOT."View/public_nav.php";

?>
<div class="row">
	<div class="col-lg-5 col-lg-offset-1 col-sm-7 col-sm-offset-1 col-xs-12">
		<h3><i class="fa fa-fw fa-coffee"></i> Team</h3>
		<p>
			formr was mostly made by <a href="https://www.psych.uni-goettingen.de/en/biopers/team/arslan">Ruben C. Arslan</a> (2013) with funding from DFG and Friedrich-Schiller-University Jena (<a href="http://www.uni-jena.de/en/Faculties/Social+and+Behavioral+Sciences/Institutes+_+Departments/Institute+of+Psychology/Departments/Differential+Psychology+and+Personality+Psychology/Personality+Psychology+and+Psychological+Assessment/Julia+Zimmermann.html">Julia Zimmermann</a>, <a href="https://www.uni-jena.de/Fakult%C3%A4ten/Sozial_+und+Verhaltenswissenschaften/Institute_Lehrst%C3%BChle/Institut+f%C3%BCr+Psychologie/Abteilungen/Lehrstuhl+f%C3%BCr+Differentielle+Psychologie_+Pers%C3%B6nlichkeitspsychologie+und+Psychologische+Diagnostik/Franz+Neyer.html">Franz J. Neyer</a>) and Georg August University Göttingen (<a href="https://psych.uni-goettingen.de/en/biopers/team/penke">Lars Penke</a>).
		</p>
		
		<p>
			The current incarnation of the survey framework draws on prior work by Linus Neumann, prior funding by Jaap J. A. Denissen, ideas, testing, and feedback by Sarah Lennartz and Isabelle Habedank.
		</p>
		<p>
			formr is open source software and uses a lot of other free software, see the <a href="https://github.com/rubenarslan/formr">Github repository</a> for some due credit. Most importantly, formr uses <a href="http://opencpu.org">OpenCPU</a> as its R backend.
		</p>

	</div>
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
