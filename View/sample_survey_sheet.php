<h3>Sample survey sheet</h3>

You can <a href="<?=WEBROOT?>assets/example_surveys/all_widgets.xlsx">download a survey Excel file</a> to get started.
<table class='table table-striped'>
	<thead>
		<tr>
			<th>
				type
			</th>
			<th>
				name
			</th>
			<th>
				label
			</th>
			<th>
				optional
			</th>
			<th>
				showif
			</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td>
				text
			</td>
			<td>
				name
			</td>
			<td>
				Please enter your name
			</td>
			<td>
				*
			</td>
			<td>
			</td>
		</tr>
		
		<tr>
			<td>
				number 1,130,1
			</td>
			<td>
				age
			</td>
			<td>
				How old are you?
			</td>
			<td>
			</td>
			<td>
			</td>
		</tr>

		<tr>
			<td>
				mc agreement
			</td>
			<td>
				emotional_stability1R
			</td>
			<td>
				I worry a lot.
			</td>
			<td>
			</td>
			<td>age >= 18
			</td>
		</tr>

		<tr>
			<td>
				mc agreement
			</td>
			<td>
				emotional_stability2R
			</td>
			<td>
				I easily get nervous and unsure of myself.
			</td>
			<td>
			</td>
			<td>
				age >= 18
			</td>
		</tr>
		<tr>
			<td>
				mc agreement
			</td>
			<td>
				emotional_stability3
			</td>
			<td>
				I am relaxed and not easily stressed.
			</td>
			<td>
			</td>
			<td>
				age >= 18
			</td>
		</tr>
	</tbody>
</table>
