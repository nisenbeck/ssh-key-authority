<?php
$web_config = $this->get('web_config');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'");
?>
<!DOCTYPE html>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php out($this->get('title'))?></title>
<link rel="stylesheet" href="<?php outurl('/bootstrap/css/bootstrap.min.css')?>">
<link rel="stylesheet" href="<?php outurl('/style.css?'.filemtime('public_html/style.css'))?>">
<link rel="icon" href="<?php outurl('/key.png')?>">
<script src="<?php outurl('/header.js?'.filemtime('public_html/header.js'))?>"></script>
<?php out($this->get('head'), ESC_NONE) ?>
<div id="wrap">
<a href="#content" class="sr-only">Skip to main content</a>
<div class="navbar navbar-default navbar-fixed-top">
	<div class="container">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<?php if(!empty($web_config['logo'])) { ?>
			<a class="navbar-brand" href="<?php outurl('/')?>">
				<img src="<?php out($web_config['logo'])?>">
				SSH Key Authority
			</a>
			<?php } ?>
		</div>
		<div class="navbar-collapse collapse">
			<ul class="nav navbar-nav">
				<?php foreach($this->get('menu_items') as $url => $name) { ?>
				<li<?php if($url == $this->get('relative_request_url')) out(' class="active"', ESC_NONE); ?>><a href="<?php outurl($url)?>"><?php out($name)?></a></li>
				<?php } ?>
			</ul>
		</div>
	</div>
</div>
<div class="container" id="content">
<?php foreach($this->get('alerts') as $alert) { ?>
<div class="alert alert-<?php out($alert->class)?> alert-dismissable">
	<button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
	<?php out($alert->content, $alert->escaping)?>
</div>
<?php } ?>
<?php if($web_config['key_password_enabled']==1 ) { ?>
	<?php $timer = get_unlocked_timer(); $changes_exist = unsaved_changes_exist(); ?>
	<?php if($timer > 0 || $changes_exist) { ?>
	<form id="changes" method="post" action="<?php outurl($this->data->relative_request_url) ?>">
		<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
		<button type="submit" name="password_entry" value="1" class="btn btn-default btn-xs">
			<?php if($timer > 0) { ?>
			Key unlocked (<?php out($timer) ?>s) <span id="changes-icon-warn" class="glyphicon glyphicon-ok" aria-hidden="true"></span>
			<?php } else if($changes_exist) { ?>
			Unsaved Changes <span id="changes-icon-succ" class="glyphicon glyphicon-ok" aria-hidden="true"></span>
			<?php } ?>
		</button>
	</form>
	<?php } ?>
<?php } ?>
<?php out($this->get('content'), ESC_NONE) ?>
</div>
</div>
<div id="footer">
	<div class="container">
		<p class="text-muted credit"><?php out($web_config['footer'], ESC_NONE)?> (v2.1.4)</p>
		<?php if($this->get('active_user') && $this->get('active_user')->developer) { ?>
		<?php } ?>
	</div>
</div>
<script src="<?php outurl('/jquery/jquery-3.7.1.min.js')?>"></script>
<script src="<?php outurl('/bootstrap/js/bootstrap.min.js')?>"></script>
<script src="<?php outurl('/extra.js?'.filemtime('public_html/extra.js'))?>"></script>
