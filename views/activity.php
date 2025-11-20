<?php
if(!$active_user->admin && count($active_user->list_admined_servers()) == 0 && count($active_user->list_admined_groups()) == 0) {
	require('views/error403.php');
	die;
}

// Get limit from URL parameter, default to 100
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
// Validate limit (must be one of the allowed values)
$allowed_limits = array(50, 100, 200, 500, 1000);
if (!in_array($limit, $allowed_limits)) {
	$limit = 100;
}

$content = new PageSection('activity');
$content->set('limit', $limit);
if($active_user->admin) {
	$content->set('events', $event_dir->list_events(array(), array(), $limit));
} else {
	$content->set('events', $active_user->list_events(array(), array(), $limit));
}

$page = new PageSection('base');
$page->set('title', 'Activity');
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
