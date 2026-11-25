<?php
try {
	$user = $user_dir->get_user_by_uid($router->vars['username']);
} catch(UserNotFoundException $e) {
	require('views/error404.php');
	die;
}
$access = $user->list_remote_access();
$admined_servers = $user->list_admined_servers(array('pending_requests'));
$admined_groups = $user->list_admined_groups(array('members', 'admins'));
$groups = $user->list_group_memberships(array('members', 'admins'));
usort($admined_servers, function($a, $b) {return strnatcasecmp($a->hostname, $b->hostname);});

if(isset($_POST['reassign_servers']) && is_array($_POST['servers']) && $active_user->admin) {
	try {
		$new_admin = $user_dir->get_user_by_uid($_POST['reassign_to']);
	} catch(UserNotFoundException $e) {
		try {
			$new_admin = $group_dir->get_group_by_name($_POST['reassign_to']);
		} catch(GroupNotFoundException $e) {
			$content = new PageSection('user_not_found');
		}
	}
	if(isset($new_admin)) {
		foreach($admined_servers as $server) {
			if(in_array($server->hostname, $_POST['servers'])) {
				$server->add_admin($new_admin);
				$server->delete_admin($user);
			}
		}
		redirect('#details');
	}
} elseif(isset($_POST['edit_user']) && $active_user->admin) {
	$user->force_disable = empty($_POST['force_disable']) ? 0 : 1;
	// Disable: at once, also when LDAP is not available. update() starts the resync.
	// Back to the LDAP status: read it from LDAP. With LDAP disabled, keep the status
	// (cron.php sets LDAP users inactive then).
	if($user->force_disable) {
		$user->active = 0;
	} elseif($user->auth_realm == 'LDAP' && $config['ldap']['enabled'] == 1) {
		try {
			$user->get_details_from_ldap();
		} catch(UserNotFoundException $e) {
			// Same as cron.php: a user that is not in LDAP is inactive
			$user->active = 0;
		}
	}
	$user->update();
	redirect('#settings');
} elseif(isset($_POST['delete_user']) && $active_user->admin) {
	if($user->auth_realm == 'local' && $user->uid != 'keys-sync' ) {
		$user->delete();
	}
	redirect('/users');
} else {
	$content = new PageSection('user');
	$content->set('user', $user);
	$content->set('user_access', $access);
	$content->set('user_admined_servers', $admined_servers);
	$content->set('user_admined_groups', $admined_groups);
	$content->set('user_groups', $groups);
	$content->set('user_keys', $user->list_public_keys());
	$content->set('admin', $active_user->admin);
}

$page = new PageSection('base');
$page->set('title', $user->name);
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
