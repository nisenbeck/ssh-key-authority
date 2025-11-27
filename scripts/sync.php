#!/usr/bin/env php
<?php
chdir(__DIR__);
require '../vendor/autoload.php';
require('../core.php');
require('sync-common.php');
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

$required_files = array('config/keys-sync', 'config/keys-sync.pub');
foreach($required_files as $file) {
	if(!file_exists($file)) die("Sync cannot start - $file not found.\n");
}

// Parse the command-line arguments
$options = getopt('h:i:au:pn', array('help', 'host:', 'id:', 'all', 'user:', 'preview', 'no-password'));
if(isset($options['help'])) {
	show_help();
	exit(0);
}
$short_to_long = array(
	'h' => 'host',
	'i' => 'id',
	'a' => 'all',
	'u' => 'user',
	'p' => 'preview',
	'n' => 'no-password'
);
foreach($short_to_long as $short => $long) {
	if(isset($options[$short]) && isset($options[$long])) {
		echo "Error: short form -$short and long form --$long both specified\n";
		show_help();
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
	show_help();
	exit(1);
}
if(isset($options['user'])) {
	$username = $options['user'];
} else {
	$username = null;
}
$preview = isset($options['preview']);
$no_password = isset($options['no-password']);

// Use 'keys-sync' user as the active user (create if it does not yet exist)
try {
	$active_user = $user_dir->get_user_by_uid('keys-sync');
} catch(UserNotFoundException $e) {
	$active_user = new User;
	$active_user->uid = 'keys-sync';
	$active_user->name = 'Synchronization script';
	$active_user->email = '';
	$active_user->auth_realm = 'local';
	$active_user->active = 1;
	$active_user->admin = 1;
	$active_user->developer = 0;
	$user_dir->add_user($active_user);
}


// Build list of servers to sync
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
} elseif(isset($options['id'])) {
	sync_server($options['id'], $username, $preview, $no_password);
	exit(0);
}

$pending_syncs = array();
foreach($servers as $server) {
	if($server->key_management != 'keys') {
		continue;
	}
	$pending_syncs[$server->hostname] = $server;
}

$password = "";
$key = null;
if(!$no_password) {
	try {
		$key_content = file_get_contents('config/keys-sync');
	} catch(ErrorException $e) {
		echo date('c')." Unable to load keyfile\n";
		exit(1);
	}

	$success = false;
	try {
		$key = PublicKeyLoader::load($key_content);
		$success = true;
	} catch(\Exception $e) {}

	if(!$success) {
		if(!$no_password) {
			try {
				echo "Enter Key Password: \n";
				system('stty -echo 2> /dev/null');
				$password = rtrim(fgets(STDIN), "\n\r\0");
				system('stty echo 2> /dev/null');
				$key = PublicKeyLoader::load($key_content, $password);
				$success = true;
			} catch(\Exception $e) {}
		}
		if(!$success) {
			echo date('c')." Invalid Key or Password\n";
			exit(1);
		}
	}
}

$sync_procs = array();
define('MAX_PROCS', 20);
while(count($sync_procs) > 0 || count($pending_syncs) > 0) {
	while(count($sync_procs) < MAX_PROCS && count($pending_syncs) > 0) {
		$server = reset($pending_syncs);
		$hostname = key($pending_syncs);
		$args = array();
		$args[] = '--id';
		$args[] = $server->id;
		if(!is_null($username)) {
			$args[] = '--user';
			$args[] = $username;
		}
		if($preview) {
			$args[] = '--preview';
		}
		if($no_password) {
			$args[] = '--no-password';
		}
		$sync_procs[] = new SyncProcess(__FILE__, $args, $password);
		unset($pending_syncs[$hostname]);
	}
	foreach($sync_procs as $ref => $sync_proc) {
		$data = $sync_proc->get_data();
		if(!empty($data)) {
			echo $data['output'];
			unset($sync_procs[$ref]);
		}
	}
	usleep(200000);
}

function show_help() {
?>
Usage: sync.php [OPTIONS]
Syncs public keys to the specified hosts.

Mandatory arguments to long options are mandatory for short options too.
  -a, --all              sync with all active hosts in the database
  -h, --host=HOSTNAME    sync only the specified host(s)
                         (specified by name, comma-separated)
  -i, --id=ID            sync only the specified single host
                         (specified by id)
  -u, --user             sync only the specified user account
  -p, --preview          perform no changes, display content of all
                         keyfiles
  -n, --no-password      fail instead of prompting for password
      --help             display this help and exit
<?php
}

function sync_server($id, $only_username = null, $preview = false, $no_password = false) {
	global $config;
	global $server_dir;
	global $user_dir;

	$keydir = '/var/local/keys-sync';
	$header = "## Auto generated keys file for %s
## Do not edit this file! Modify at %s
";
	$header_no_link = "## Auto generated keys file for %s
## Do not edit this file!
";
	$ska_key = file_get_contents('config/keys-sync.pub');

	$server = $server_dir->get_server_by_id($id);
	$hostname = $server->hostname;
	echo date('c')." {$hostname}: Preparing sync.\n";
	$server->ip_address = gethostbyname($hostname);
	$server->update();
	if($server->key_management != 'keys') return;
	$accounts = $server->list_accounts();
	$keyfiles = array();
	$sync_warning = false;
	// Generate keyfiles for each account
	foreach($accounts as $account) {
		if($account->active == 0 || $account->sync_status == 'proposed') continue;
		$username = str_replace('/', '', $account->name);
		$keyfile = sprintf($header, "account '{$account->name}'", $config['web']['baseurl']."/servers/".urlencode($hostname)."/accounts/".urlencode($account->name));
		// Collect a set of all groups that the account is a member of (directly or indirectly) and the account itself
		$sets = $account->list_group_membership();
		$sets[] = $account;
		foreach($sets as $set) {
			if(get_class($set) == 'Group') {
				if($set->active == 0) continue; // Rules for inactive groups should be ignored
				$keyfile .= "# === Start of rules applied due to membership in {$set->name} group ===\n";
			}
			$access_rules = $set->list_access();
			$keyfile .= get_keys($access_rules, $account->name, $hostname);
			if(get_class($set) == 'Group') {
				$keyfile .= "# === End of rules applied due to membership in {$set->name} group ===\n\n";
			}
		}
		$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => false, 'account' => $account);
	}
	if($server->authorization == 'automatic LDAP' || $server->authorization == 'manual LDAP') {
		// Generate keyfiles for LDAP users
		$optiontext = array();
		foreach($server->list_ldap_access_options() as $option) {
			$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		$users = $user_dir->list_users();
		foreach($users as $user) {
			$username = str_replace('/', '', $user->uid);
			if(is_null($only_username) || $username == $only_username) {
				if(!isset($keyfiles[$username])) {
					$keyfile = sprintf($header, "LDAP user '{$user->uid}'", $config['web']['baseurl']);
					$keys = $user->list_public_keys($username, $hostname);
					if(count($keys) > 0) {
						if($user->active) {
							foreach($keys as $key) {
								$keyfile .= $prefix.$key->export()."\n";
							}
						} else {
							$keyfile .= "# Inactive account\n";
						}
						$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => ($server->authorization == 'manual LDAP'));
					}
				}
			}
		}
	}
	if(array_key_exists('keys-sync', $keyfiles)) {
		// keys-sync account should never be synced
		unset($keyfiles['keys-sync']);
	}
	if($preview) {
		foreach($keyfiles as $username => $keyfile) {
			echo date('c')." {$hostname}: account '$username':\n\n\033[1;34m{$keyfile['keyfile']}\033[0m\n\n";
		}
		return;
	}
	// IP address check
	if(!isset($config['security']) || !isset($config['security']['disable_ip_collision_protection']) || $config['security']['disable_ip_collision_protection'] != 1) {
		echo date('c')." {$hostname}: Checking IP address {$server->ip_address}.\n";
		$query = array('ip_address' => $server->ip_address, 'key_management' => array('keys'));
		if(isset($config['security']) && isset($config['security']['allow_different_ports_on_single_ip']) && $config['security']['allow_different_ports_on_single_ip'] == 1) {
			$query['port'] = $server->port;
		}
		$matching_servers = $server_dir->list_servers(array(), $query);
		if(count($matching_servers) > 1) {
			echo date('c')." {$hostname}: Multiple hosts with same IP address.\n";
			$server->sync_report('sync failure', 'Multiple hosts with same IP address');
			$server->delete_all_sync_requests();
			report_all_accounts_failed($keyfiles);
			return;
		}
	}

	$key = null;
	try {
		$key_content = file_get_contents('config/keys-sync');
	} catch(ErrorException $e) {
		echo date('c')." {$hostname}: Public key authentication failed. Unable to load keyfile\n";
		$server->sync_report('sync failure', 'SSH authentication failed: Unable to load keyfile');
		$server->delete_all_sync_requests();
		report_all_accounts_failed($keyfiles);
		return;
	}

	$success = false;
	try {
		$key = PublicKeyLoader::load($key_content);
		$success = true;
	} catch(\Exception $e) {}

	if(!$success) {
		if(!$no_password) {
			try {
				echo "Enter Key Password: \n";
				system('stty -echo 2> /dev/null');
				$password = rtrim(fgets(STDIN), "\n\r\0");
				system('stty echo 2> /dev/null');
				$key = PublicKeyLoader::load($key_content, $password);
				$success = true;
			} catch(\Exception $e) {}
		}
		if(!$success) {
			echo date('c')." {$hostname}: Public key authentication failed. Invalid Key or Password\n";
			$server->sync_report('sync failure', 'SSH authentication failed: Invalid Key or Password');
			$server->delete_all_sync_requests();
			report_all_accounts_failed($keyfiles);
			return;
		}
	}

	echo date('c')." {$hostname}: Attempting to connect.\n";
	$legacy = false;
	$sftp = null;
	try {
		$ssh_check = new SSH2($hostname, $server->port);
		$ssh_check->setTimeout(60);

		// Verifying Host Key
		// phpseclib 3 uses standard SSH fingerprinting (SHA256 of the full decoded key)
		$hostkeyRaw = $ssh_check->getServerPublicHostKey();
		if (!is_string($hostkeyRaw)) {
			throw new Exception("Unable to extract host key");
		}

		$parts = explode(' ', $hostkeyRaw, 3);
		if (count($parts) < 2) {
			throw new Exception("Unexpected host key format");
		}

		// Standard SSH-Fingerprint: SHA256 of the full Base64-decoded key
		// This matches the format of: ssh-keygen -l -E sha256
		$keyData = base64_decode($parts[1]);
		if ($keyData === false) {
			throw new Exception("Unable to decode host key");
		}
		$fingerprint = strtoupper(hash('sha256', $keyData));

		if(is_null($server->rsa_key_fingerprint)) {
			$server->rsa_key_fingerprint = $fingerprint;
			$server->update();
		} else {
			if(strcmp($server->rsa_key_fingerprint, $fingerprint) !== 0) {
				echo date('c')." {$hostname}: RSA key validation failed.\n";
				$server->sync_report('sync failure', 'SSH host key verification failed');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}

		// Host Key Collision Protection
		if(!isset($config['security']) || !isset($config['security']['host_key_collision_protection']) || $config['security']['host_key_collision_protection'] == 1) {
			$matching_servers = $server_dir->list_servers(array(), array('rsa_key_fingerprint' => $server->rsa_key_fingerprint, 'key_management' => array('keys')));
			if(count($matching_servers) > 1) {
				echo date('c')." {$hostname}: Multiple hosts with same host key.\n";
				$server->sync_report('sync failure', 'Multiple hosts with same host key');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}
	} catch(Exception $e) {
		echo date('c')." {$hostname}: Failed to verify host key.\n".$e->getMessage()."\n";
		$server->sync_report('sync failure', 'SSH host key verification failed');
		$server->delete_all_sync_requests();
		report_all_accounts_failed($keyfiles);
		return;
	}

	// Close the checking connection
	$ssh_check->disconnect();
	unset($ssh_check);

	// Stored fingerprint for comparison on second connection
	$expected_fingerprint = $fingerprint;

	// Login with SFTP (SFTP extends SSH2, so only one connection is needed)
	$attempts = array('keys-sync', 'root');
	foreach($attempts as $attempt) {
		try {
			$sftp = new SFTP($hostname, $server->port);
			$sftp->enableQuietMode();
			$sftp->setTimeout(60);

			// Verify host key of the second connection BEFORE login
			$hostkeyRaw2 = $sftp->getServerPublicHostKey();
			if (is_string($hostkeyRaw2)) {
				$parts2 = explode(' ', $hostkeyRaw2, 3);
				if (count($parts2) >= 2) {
					$keyData2 = base64_decode($parts2[1]);
					if ($keyData2 !== false) {
						$fingerprint2 = strtoupper(hash('sha256', $keyData2));
						if (strcmp($expected_fingerprint, $fingerprint2) !== 0) {
							echo date('c')." {$hostname}: Host key mismatch on second connection (possible MITM).\n";
							$server->sync_report('sync failure', 'Host key mismatch on second connection');
							$server->delete_all_sync_requests();
							report_all_accounts_failed($keyfiles);
							return;
						}
					}
				}
			}

			if (!$sftp->login($attempt, $key)) {
				if($attempt == 'root') {
					echo date('c')." {$hostname}: Public key authentication failed.\n";
					$server->sync_report('sync failure', 'SSH authentication failed');
					$server->delete_all_sync_requests();
					report_all_accounts_failed($keyfiles);
					return;
				}
				$legacy = true; // keys-sync login failed, try root next
				continue;
			}

			echo date('c')." {$hostname}: Logged in as $attempt.\n";
			break;

		} catch(Exception $e) {
			echo date('c')." {$hostname}: Connection or authentication error.\n".$e->getMessage()."\n";
			if($attempt == 'root') {
				$server->sync_report('sync failure', 'SSH connection/authentication failed');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}

		$legacy = true;
	}

	// $sftp is used from here on for both SSH commands (exec) and SFTP (put)
	$ssh = $sftp;
	$ssh->exec('/usr/bin/env test -d ' . $keydir);
	if(!is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0) {
		$dir = true;
	} else {
		echo date('c')." {$hostname}: Key directory does not exist.\n";
		$dir = null;
		$sync_warning = 'Key directory does not exist';
	}
	if($legacy) {
		$sync_warning = 'Using legacy sync method';
	}

	// From this point on, catch SIGTERM and ignore. SIGINT or SIGKILL is required to stop, so timeout wrapper won't
	// cause a partial sync
	pcntl_signal(SIGTERM, SIG_IGN);

	$account_errors = 0;
	$cleanup_errors = 0;

	if(isset($config['security']) && isset($config['security']['hostname_verification']) && $config['security']['hostname_verification'] >= 1) {
		// Verify that we have mutual agreement with the server that we sync to it with this hostname
		$allowed_hostnames = null;
		if($config['security']['hostname_verification'] >= 2) {
			// 2+ = Compare with /var/local/keys-sync/.hostnames
			$allowed_hostnames = explode("\n", trim($ssh->exec('/usr/bin/env cat /var/local/keys-sync/.hostnames')));
			if(is_bool($ssh->getExitStatus()) || $ssh->getExitStatus() != 0) {
				if($config['security']['hostname_verification'] >= 3) {
					// 3+ = Abort if file does not exist
					echo date('c')." {$hostname}: Hostnames file missing.\n";
					$server->sync_report('sync failure', 'Hostnames file missing');
					$server->delete_all_sync_requests();
					report_all_accounts_failed($keyfiles);
					return;
				} else {
					$allowed_hostnames = null;
				}
			}
		}
		if(is_null($allowed_hostnames)) {
			try {
				$allowed_hostnames = array(trim($ssh->exec('/usr/bin/env hostname -f')));
			} catch(ErrorException $e) {
				echo date('c')." {$hostname}: Cannot execute hostname -f.\n";
				$server->sync_report('sync failure', 'Cannot execute hostname -f');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}
		if(!in_array($hostname, $allowed_hostnames)) {
			echo date('c')." {$hostname}: Hostname check failed (allowed: ".implode(", ", $allowed_hostnames).").\n";
			$server->sync_report('sync failure', 'Hostname check failed');
			$server->delete_all_sync_requests();
			report_all_accounts_failed($keyfiles);
			return;
		}
	}

	if($legacy && isset($keyfiles['root'])) {
		// Legacy sync (only if using root account)
		$keyfile = $keyfiles['root'];
		$success = false;
		try {
			$local_filename = tempnam('/tmp', 'syncfile');
			$fh = fopen($local_filename, 'w');
			fwrite($fh, $keyfile['keyfile']."# SKA system key\n".$ska_key);
			fclose($fh);
			$ssh->exec('/usr/bin/env mkdir -p '.escapeshellarg('/root/.ssh'));
			if(!is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0) {
				if ($sftp->put('/root/.ssh/authorized_keys2', $local_filename, SFTP::SOURCE_LOCAL_FILE)) {
					$ssh->exec('/usr/bin/env chmod 600 '.escapeshellarg('/root/.ssh/authorized_keys2'));
					if(!is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0) {
						if(isset($keyfile['account'])) {
							$keyfile['account']->sync_report('sync success');
						}
						$success = true;
					}
				}
			}
			unlink($local_filename);
		} catch(ErrorException $e) {}
		if(!$success) {
			echo date('c')." {$hostname}: Sync command execution failed for legacy root.\n";
			$account_errors++;
			if(isset($keyfile['account'])) {
				$keyfile['account']->sync_report('sync failure');
			}
		}
	}

	// New sync
	if($dir) {
		try {
			$success = false;
			$entries = explode("\n", $ssh->exec('/usr/bin/env sha1sum '.escapeshellarg($keydir).'/*'));
			$sha1sums = array();
			if(!is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0) {
				foreach($entries as $entry) {
					if(preg_match('|^([0-9a-f]{40})  '.preg_quote($keydir, '|').'/(.*)$|', $entry, $matches)) {
						$sha1sums[$matches[2]] = $matches[1];
					}
				}
				$success = true;
			} elseif($ssh->getExitStatus() == 1) {
				// No files in directory
				$success = true;
			}
		} catch(ErrorException $e) {}
		if(!$success) {
			echo date('c')." {$hostname}: Cannot execute sha1sum.\n";
			$server->sync_report('sync failure', 'Cannot execute sha1sum');
			$server->delete_all_sync_requests();
			report_all_accounts_failed($keyfiles);
			return;
		}
		foreach($keyfiles as $username => $keyfile) {
			if(is_null($only_username) || $username == $only_username) {
				try {
					$remote_filename = "$keydir/$username";
					$create = true;
					$success = false;
					if($keyfile['check']) {
						$output = $ssh->exec('/usr/bin/env id '.escapeshellarg($username));
						$success = !is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0;
						if(!$success) {
							echo "Unable to call id";
						}
						if(empty($output)) $create = false;
					} else {
						$success = true;
					}
					if($success && $create) {
						if(isset($sha1sums[$username]) && $sha1sums[$username] == sha1($keyfile['keyfile'])) {
							echo date('c')." {$hostname}: No changes required for {$username}\n";
						} else {
							$local_filename = tempnam('/tmp', 'syncfile');
							$fh = fopen($local_filename, 'w');
							fwrite($fh, $keyfile['keyfile']);
							fclose($fh);
							$success = $sftp->put($remote_filename, $local_filename, SFTP::SOURCE_LOCAL_FILE);
							if(!$success) {
								echo "Unable to transfer file using scp";
							}
							if($success) {
								$ssh->exec('/usr/bin/env chmod 644 '.escapeshellarg($remote_filename));
								$success = !is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0;
								if(!$success) {
									echo "Unable to change permission";
								}
							}
							if($success) {
								$ssh->exec('/usr/bin/env chown keys-sync: '.escapeshellarg($remote_filename));
								$success = !is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0;
								if(!$success) {
									echo "Unable to change ownership";
								}
							}
							if($success) {
								echo date('c')." {$hostname}: Updated {$username}\n";
							}
							unlink($local_filename);
						}
						if(isset($sha1sums[$username])) {
							unset($sha1sums[$username]);
						}
					} else if($success) {
						$ssh->exec('/usr/bin/env rm -f '.escapeshellarg($remote_filename));
						$success = !is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0;
						if(!$success) {
							echo "Unable to remove file using rm";
						}
					}
					if($success && isset($keyfile['account'])) {
						if($sync_warning && $username != 'root') {
							// File was synced, but will not work due to configuration on server
							$keyfile['account']->sync_report('sync warning');
						} else {
							$keyfile['account']->sync_report('sync success');
						}
					}
				} catch(ErrorException $e) {}
				if(!$success) {
					$account_errors++;
					echo "{$hostname}: Sync command execution failed for $username.\n";
					if(isset($keyfile['account'])) {
						$keyfile['account']->sync_report('sync failure');
					}
				}
			}
		}
		if(is_null($only_username)) {
			// Clean up directory
			foreach($sha1sums as $file => $sha1sum) {
				if($file != '' && $file != 'keys-sync' && $file != '.hostnames') {
					try {
						$remote_filename = "$keydir/$file";
						$success = false;
						$ssh->exec('/usr/bin/env rm -f '.escapeshellarg($remote_filename));
						$success = !is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0;
					} catch(ErrorException $e) {}
					if($success) {
						echo date('c')." {$hostname}: Removed unknown file: {$file}\n";
					} else {
						$cleanup_errors++;
						echo date('c')." {$hostname}: Couldn't remove unknown file: {$file}.\n";
					}
				}
			}
		}
	}
	try {
		$output = trim($ssh->exec('/usr/bin/env cat /etc/uuid'));
		if(!is_bool($ssh->getExitStatus()) && $ssh->getExitStatus() == 0) {
			$server->uuid = $output;
		}
	} catch(ErrorException $e) {
		// If the /etc/uuid file does not exist, silently ignore
	}
	if($cleanup_errors > 0) {
		$server->sync_report('sync failure', 'Failed to clean up '.$cleanup_errors.' file'.($cleanup_errors == 1 ? '' : 's'));
	} elseif($account_errors > 0) {
		$server->sync_report('sync failure', $account_errors.' account'.($account_errors == 1 ? '' : 's').' failed to sync');
	} elseif($sync_warning) {
		$server->sync_report('sync warning', $sync_warning);
	} else {
		$server->sync_report('sync success', 'Synced successfully');
	}

	// Close the sftp connection
	if($sftp) {
		$sftp->disconnect();
	}

	echo date('c')." {$hostname}: Sync finished\n";
}

function get_keys($access_rules, $account_name, $hostname) {
	$keyfile = '';
	foreach($access_rules as $access) {
		$grant_date = new DateTime($access->grant_date);
		$grant_date_full = $grant_date->format('c');
		$entity = $access->source_entity;
		$optiontext = array();
		foreach($access->list_options() as $option) {
            if ($option->option === 'environment') {
                foreach (explode(',', $option->value) as $env_variable) {
                    $optiontext[] = $option->option . (is_null($option->value) ? '' : '="' . str_replace('"', '\\"', ltrim($env_variable)) . '"');
                }
            } else {
                $optiontext[] = $option->option . (is_null($option->value) ? '' : '="' . str_replace('"', '\\"', $option->value) . '"');
            }
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		switch(get_class($entity)) {
		case 'User':
			$keyfile .= "# {$entity->uid}";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->active) {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Inactive account\n";
			}
			break;
		case 'ServerAccount':
			$keyfile .= "# {$entity->name}@{$entity->server->hostname}";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->server->key_management != 'decommissioned') {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Decommissioned server\n";
			}
			break;
		case 'Group':
			// Recurse!
			$seen = array($entity->name => true);
			$keyfile .= "# {$entity->name} group";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->active) {
				$keyfile .= "# == Start of {$entity->name} group members ==\n";
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen);
				$keyfile .= "# == End of {$entity->name} group members ==\n";
			} else {
				$keyfile .= "# Inactive group\n";
			}
			break;
		}
	}
	return $keyfile;
}

function get_group_keys($entities, $account_name, $hostname, $prefix, &$seen) {
	$keyfile = '';
	foreach($entities as $entity) {
		switch(get_class($entity)) {
		case 'User':
			$keyfile .= "# {$entity->uid}";
			$keyfile .= "\n";
			if($entity->active) {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Inactive account\n";
			}
			break;
		case 'ServerAccount':
			$keyfile .= "# {$entity->name}@{$entity->server->hostname}";
			$keyfile .= "\n";
			if($entity->server->key_management != 'decommissioned') {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Decommissioned server\n";
			}
			break;
		case 'Group':
			// Recurse!
			if(!isset($seen[$entity->name])) {
				$seen[$entity->name] = true;
				$keyfile .= "# {$entity->name} group";
				$keyfile .= "\n";
				$keyfile .= "# == Start of {$entity->name} group members ==\n";
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen);
				$keyfile .= "# == End of {$entity->name} group members ==\n";
			}
			break;
		}
	}
	return $keyfile;
}

function report_all_accounts_failed($keyfiles) {
	foreach($keyfiles as $keyfile) {
		if(isset($keyfile['account'])) {
			$keyfile['account']->sync_report('sync failure');
		}
	}
}
