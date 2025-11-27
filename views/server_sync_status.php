<?php
try {
	$server = $server_dir->get_server_by_hostname($router->vars['hostname']);
} catch(ServerNotFoundException $e) {
	require('views/error404.php');
	die;
}

$server_admin = $active_user->admin_of($server);
$account_admin_on_this_server = false;

if (!$active_user->admin && !$server_admin) {
	// If not global or server admin, check if user is admin of at least one account on this server
	foreach ($server->list_accounts() as $acct) {
		if ($active_user->admin_of($acct)) {
			$account_admin_on_this_server = true;
			break;
		}
	}
}

if (!$active_user->admin && !$server_admin && !$account_admin_on_this_server) {
	require('views/error403.php');
	die;
}

$page = new PageSection('server_sync_status_json');
$page->set('sync_status', $server->sync_status);
$page->set('last_sync_event', $server->get_last_sync_event());
$page->set('pending', $server->sync_is_pending());

// Only show account inventory to privileged viewers.
// - Global admin or server admin: full list
// - Account admin: only accounts they administer
if ($active_user->admin || $server_admin) {
	$accounts = $server->list_accounts();
} else {
	$accounts = array_filter($server->list_accounts(), function ($acct) use ($active_user) {
		return $active_user->admin_of($acct);
	});
}
$page->set('accounts', $accounts);

header('Content-type: application/json; charset=utf-8');
echo $page->generate();
