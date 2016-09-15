<?php
    Template::load('header_nav');
?>
<div class="row">
	<div class="col-lg-5">
		<h3><i class="fa fa-fw fa-quote-left"></i> Citation</h3>
		<p>
			If you are publishing research conducted using formr, <strong>please cite</strong> 
		</p>
		<p>
			Arslan, R.C., &amp; Tata, C.S. (<?= date("Y") ?>). formr.org survey software (Version <?=Config::get('version') ?>). <a href="https://zenodo.org/badge/latestdoi/11849439"><img src="https://zenodo.org/badge/11849439.svg" alt="DOI"></a>
		</p>
		<p>
			Cite the version that was active while you ran your study. Zenodo will keep backups of each major release, so that the software used for your study is preserved when we update it and if Github ceases to exist. This ensures reproducibility and allows us to trace papers affected by major bugs, should we discover any in the future. 
		</p>
		<p>
			If you used the accompanying R package, you should cite it too, because it is independent of the rest of the software and independently versioned.
		</p>
		<p>
			Arslan, R.C. (<?= date("Y") ?>). formr R package (Version 0.4.1). <a href="https://zenodo.org/badge/latestdoi/19236374"><img src="https://zenodo.org/badge/19236374.svg" alt="DOI"></a>
		</p>


		<h3><i class="fa fa-fw fa-server"></i> Hosting</h3>
		<p>
			This instance of the formr.org is hosted on servers at the Georg August University Göttingen. It implements a security model that individually and uniquely protects the various entities of the platform, the application, it's data and the R interface (OpenCPU). These entities communicate only within a local network whose access is restricted to the IT administators of the Georg-Elias-Müller-Institute of Psychology.
		</p>
		<p>
			Our entire database is backed up nightly. Whenever real data is deleted by you in bulk, formr backs it up as well, right before deletion. No extra backup is made, if you delete single users/single survey entries. We do not back up specifically back up run units and survey files, but you can redownload the most recently uploaded version of a survey file.
		</p>

		<h3><i class="fa fa-fw fa-lock"></i> Security</h3>
		<p>
			Your (and your participants') connection to this site is encrypted using state-of-the-art security using <a href="https://en.wikipedia.org/wiki/HTTPS">HTTPS (also called HTTPS over TLS)</a>. This protects against eavesdropping on survey responses and tampering with the content of our site.
		</p>
		<p>
			We have taken several measures to make it very unlikely that sensitive participant's data is divulged. It is not possible for participants to retrieve their answers to past responses, unless those are incorporated in a feedback somewhere by you, the researcher. Therefore, care should be taken not to incorporate sensitive information into the feedback and to alert participants to any possible privacy gray areas in the feedback (e.g. incorporating participant responses about their employer in a feedback mailed to a work email address or incorporating feedback on romantic activity in a study where it's likely that the participant's partner has access to their device).
		</p>
		<p>	
			Participants get an access token for the study, which functions as a strong password. However, an access token is stored on participant's devices/browsers by default and (if you set this up) emails can be sent to their email addresses, so the protection is only as strong as security for access to their device or their email account.
		</p>
		<p>
			It is very important that you, as the study administator, choose a strong password for the admin account and the email address that it is linked to. Here's <a href="https://xkcd.com/936/">some good advice</a> on choosing a strong password. Do not share the password with your collaborators via unencrypted channels (e.g. email) and don't share the password via any medium together with the information for which account and website it is. Keep your password in a safe place (your mind, a good password manager) and make sure your collaborators do the same.
		</p>
		<p>
			The same precautions, of course, should be respected for the data that you collected.<br>
			Should you plan to release the collected data openly, please make sure that the data are not sensitive and not (re-)identifiable.
		</p>

	</div>
	<div class="col-lg-5 col-lg-offset-1">

			<h3><i class="fa fa-fw fa-coffee"></i> Team</h3>
		<p>
			formr was made by <a href="https://www.psych.uni-goettingen.de/en/biopers/team/arslan">Ruben C. Arslan</a> and <a href="https://www.psych.uni-goettingen.de/de/it/team/cyril-tata/cyril-s-tata">Cyril S. Tata</a>.
		<p>The current incarnation of the survey framework draws on prior work by Linus Neumann, prior funding by Jaap J. A. Denissen, ideas, testing, and feedback by Sarah J. Lennartz, Isabelle Habedank, and <a href="https://www.psych.uni-goettingen.de/en/biopers/team/gerlach">Tanja M. Gerlach</a>.</p>
		</p>

		
		<div class="row">
			
			<div class="col-md-6">
				<p class="lead">
					<img src="<?=WEBROOT?>assets/img/goettingen_uni.png" alt="Uni Göttingen logo"><br>
					<small><small>Georg August University Göttingen</small></small>
				</p>
			</div>
			
			<div class="col-md-6">
				<p class="lead">
					<img src="<?=WEBROOT?>assets/img/jena_uni.png" alt="Uni Jena logo"><br>
					<small><small>Friedrich Schiller University Jena</small></small>
				</p>
			</div>
		</div>

				<h3><i class="fa fa-fw fa-money"></i> Funding</h3>
		<p>Friedrich-Schiller-University Jena – <a href="http://dfg.de/">DFG</a> <a href="http://www.kompass.uni-jena.de">project "Kompass"</a>, PIs: <a href="http://www.uni-jena.de/en/Faculties/Social+and+Behavioral+Sciences/Institutes+_+Departments/Institute+of+Psychology/Departments/Differential+Psychology+and+Personality+Psychology/Personality+Psychology+and+Psychological+Assessment/Julia+Zimmermann.html">Julia Zimmermann</a>, <a href="https://www.uni-jena.de/Fakult%C3%A4ten/Sozial_+und+Verhaltenswissenschaften/Institute_Lehrst%C3%BChle/Institut+f%C3%BCr+Psychologie/Abteilungen/Lehrstuhl+f%C3%BCr+Differentielle+Psychologie_+Pers%C3%B6nlichkeitspsychologie+und+Psychologische+Diagnostik/Franz+Neyer.html">Franz J. Neyer</a>
		</p>
		<p>Georg August University Göttingen – <a href="https://psych.uni-goettingen.de/en/biopers/team/penke">Lars Penke</a>, current hosting</p>
		<p><a href="https://cos.io">Center for Open Science</a> – <a href="https://cos.io/pr/2015-09-24/">Open Contributor Grant</a> to Ruben Arslan and Cyril Tata. 

		<h3><i class="fa fa-fw fa-github"></i> Other credit</h3>
		<p>
			formr is open source software and uses a lot of other free software, see the <a href="https://github.com/rubenarslan/formr.org">Github repository</a> for some due credit. Most importantly, formr uses <a href="http://opencpu.org">OpenCPU</a> as its R backend.
		</p>
	</div>
</div>
<?php
    Template::load('footer');
?>
