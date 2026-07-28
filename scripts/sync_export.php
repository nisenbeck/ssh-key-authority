#!/usr/bin/env php
<?php
/**
* Sync Export
*
* Generates the same authorized_keys content as sync.php, but writes it to a local directory tree
* instead of connecting to the target servers - for hosts the SKA server can't reach directly.
* Deliver the resulting tree with sync_export_apply.sh.
*
* Layout (relative to --dir): sync_export_apply.sh (bundled copy, so the exported tree is
* self-contained), <hostname>/.ska-manifest.json (metadata) and <hostname>/<account> (keyfile
* content, mode 0600) per host. A host's folder is the sole record of its inclusion - delete a
* folder to exclude it from the next sync_export_apply.sh run.
*
* Read-only against the SKA database, so safe to run without network access to the servers.
* Does not touch sync_status/sync_request - the next scripts/sync.php run reconciles that itself
* via its own SHA1 comparison.
*/
chdir(__DIR__);
require '../vendor/autoload.php';
require('../core.php');
require('sync-common.php');

// Parse the command-line arguments
$options = getopt('h:i:ad:u:p', array('help', 'host:', 'id:', 'all', 'dir:', 'user:', 'preview'));
if(isset($options['help'])) {
	show_export_help();
	exit(0);
}
$short_to_long = array(
	'h' => 'host',
	'i' => 'id',
	'a' => 'all',
	'd' => 'dir',
	'u' => 'user',
	'p' => 'preview'
);
foreach($short_to_long as $short => $long) {
	if(isset($options[$short]) && isset($options[$long])) {
		echo "Error: short form -$short and long form --$long both specified\n";
		show_export_help();
		exit(1);
	}
	if(isset($options[$short])) $options[$long] = $options[$short];
}
$hostopts = 0;
if(isset($options['host'])) $hostopts++;
if(isset($options['id'])) $hostopts++;
if(isset($options['all'])) $hostopts++;
if($hostopts != 1) {
	echo "Error: must specify exactly one of --host, --id, or --all\n";
	show_export_help();
	exit(1);
}
$preview = isset($options['preview']);
if(!$preview && !isset($options['dir'])) {
	echo "Error: --dir is required (unless --preview is used)\n";
	show_export_help();
	exit(1);
}
$only_username = isset($options['user']) ? $options['user'] : null;
$export_dir = $preview ? null : rtrim($options['dir'], '/\\');

if(!$preview) {
	if(!is_dir($export_dir)) {
		if(!mkdir($export_dir, 0700, true)) {
			echo "Error: could not create export directory $export_dir\n";
			exit(1);
		}
	}
	// Export tree contains sensitive account data, keep it private
	chmod($export_dir, 0700);

	// Bundle the apply script so the export tree is self-contained - copy it (with --identity
	// FILE) to a host with network access and run it straight from there, no separate SKA
	// checkout or --dir needed.
	$apply_script_src = __DIR__.'/sync_export_apply.sh';
	if(file_exists($apply_script_src)) {
		copy($apply_script_src, "$export_dir/sync_export_apply.sh");
		chmod("$export_dir/sync_export_apply.sh", 0700);
	}
}

// Build list of servers to export
if(isset($options['all'])) {
	$servers = $server_dir->list_servers();
} elseif(isset($options['host'])) {
	$servers = array();
	$hostnames = explode(",", $options['host']);
	foreach($hostnames as $hostname) {
		$hostname = trim($hostname);
		try {
			$servers[] = $server_dir->get_server_by_hostname($hostname);
		} catch(ServerNotFoundException $e) {
			echo "Error: hostname '$hostname' not found\n";
			exit(1);
		}
	}
} else {
	try {
		$servers = array($server_dir->get_server_by_id($options['id']));
	} catch(ServerNotFoundException $e) {
		echo "Error: server id '{$options['id']}' not found\n";
		exit(1);
	}
}

$exit_code = 0;
foreach($servers as $server) {
	$hostname = $server->hostname;
	if($server->key_management != 'keys') {
		echo date('c')." {$hostname}: Skipped (key management is not set to 'keys').\n";
		continue;
	}
	echo date('c')." {$hostname}: Generating keyfiles.\n";
	try {
		$keyfiles = build_server_keyfiles($server, $only_username);
	} catch(Exception $e) {
		echo date('c')." {$hostname}: Failed to generate keyfiles: ".$e->getMessage()."\n";
		$exit_code = 1;
		continue;
	}

	if($preview) {
		foreach($keyfiles as $username => $keyfile) {
			echo date('c')." {$hostname}: account '$username':\n\n\033[1;34m{$keyfile['keyfile']}\033[0m\n\n";
		}
		continue;
	}

	$host_dir = "$export_dir/".sanitize_export_hostname($hostname);
	if(!is_dir($host_dir) && !mkdir($host_dir, 0700, true)) {
		echo date('c')." {$hostname}: Could not create directory $host_dir\n";
		$exit_code = 1;
		continue;
	}
	chmod($host_dir, 0700);

	// Remove stale account files from a previous run (keep manifest + audit fingerprint file)
	$non_account_files = array('.ska-manifest.json', '.ska-learned-fingerprint');
	foreach(scandir($host_dir) as $existing_file) {
		if($existing_file === '.' || $existing_file === '..') continue;
		if(in_array($existing_file, $non_account_files)) continue;
		if(!isset($keyfiles[$existing_file])) {
			unlink("$host_dir/$existing_file");
		}
	}

	$manifest_accounts = array();
	foreach($keyfiles as $username => $keyfile) {
		$target_file = "$host_dir/$username";
		file_put_contents($target_file, $keyfile['keyfile']);
		chmod($target_file, 0600);
		$manifest_accounts[$username] = array(
			'sha1' => sha1($keyfile['keyfile']),
			'check' => (bool) $keyfile['check']
		);
	}

	$manifest = array(
		'hostname' => $hostname,
		'server_id' => $server->id,
		'port' => (int) $server->port,
		'keydir' => '/var/local/keys-sync',
		'rsa_key_fingerprint' => $server->rsa_key_fingerprint,
		'generated_at' => gmdate('c'),
		'files' => $manifest_accounts
	);
	file_put_contents("$host_dir/.ska-manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	chmod("$host_dir/.ska-manifest.json", 0600);

	echo date('c')." {$hostname}: Exported ".count($manifest_accounts)." account file(s) to $host_dir\n";
}

exit($exit_code);

/**
* Sanitize a hostname for safe use as a directory name, preventing path traversal.
* @param string $hostname
* @return string sanitized directory name
*/
function sanitize_export_hostname($hostname) {
	return preg_replace('/[^A-Za-z0-9.\-_]/', '_', $hostname);
}

function show_export_help() {
?>
Usage: sync_export.php --dir=DIR [OPTIONS]
Generates authorized_keys files for the specified hosts and writes them to a
local directory tree, for later delivery with sync_export_apply.sh. Does not
connect to any target server.

Mandatory arguments to long options are mandatory for short options too.
  -d, --dir=DIR          directory to write the export tree to (required
                         unless --preview is used)
  -a, --all              export all active hosts in the database
  -h, --host=HOSTNAME    export only the specified host(s)
                         (specified by name, comma-separated)
  -i, --id=ID            export only the specified single host
                         (specified by id)
  -u, --user             export only the specified user/account name
  -p, --preview          perform no changes, display content of all
                         keyfiles instead of writing them to disk
      --help             display this help and exit
<?php
}
