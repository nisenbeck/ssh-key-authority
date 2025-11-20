<div class="clearfix">
	<h1 class="pull-left">Activity</h1>
	<form class="form-inline pull-right">
		<div class="form-group">
			<label for="event-limit">Show:</label>
			<select id="event-limit" class="form-control input-sm" onchange="window.location.href='?limit=' + this.value">
				<option value="50" <?php if($this->get('limit') == 50) echo 'selected'; ?>>50</option>
				<option value="100" <?php if($this->get('limit') == 100) echo 'selected'; ?>>100</option>
				<option value="200" <?php if($this->get('limit') == 200) echo 'selected'; ?>>200</option>
				<option value="500" <?php if($this->get('limit') == 500) echo 'selected'; ?>>500</option>
				<option value="1000" <?php if($this->get('limit') == 1000) echo 'selected'; ?>>1000</option>
			</select>
		</div>
	</form>
</div>
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
