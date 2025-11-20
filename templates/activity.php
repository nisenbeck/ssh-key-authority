<h1>Activity</h1>
<table class="table table-searchable">
	<col>
	<col>
	<col>
	<col class="date">
	<thead>
		<tr>
			<th>Entity</th>
			<th>User</th>
			<th>Activity</th>
			<th>Date (<abbr title="Coordinated Universal Time">UTC</abbr>)</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach($this->get('events') as $event) {
			show_event($event);
		}
		?>
	</tbody>
</table>
