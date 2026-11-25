#!/usr/bin/env php
<?php
$options = getopt('', array('systemd', 'user:'));

/**
* Handle process control signals
*/
function sig_handler($signo) {
	global $signal;
	$signal = $signo;
}
/**
* Daemon log - write log message
*/
function dlog($txt) {
	global $options;
	if(isset($options['systemd'])) {
		echo "{$txt}\n";
	} else {
		echo date('c')." {$txt}\n";
	}
}

chdir(__DIR__);
error_reporting(E_ALL);
ini_set('display_errors', 1);
cli_set_process_title('keys-sync');

umask(027);

if(!isset($options['systemd'])) {
	$pidfile = '/var/run/keys/keys-sync.pid';
	$lockfile = '/var/run/keys/keys-sync.lock';
	$logfile = '/var/log/keys/sync.log';

	if(!isset($options['user'])) {
		fwrite(STDERR, "--user parameter must be provided");
		exit(1);
	}
	$username = $options['user'];

	if(posix_getuid() !== 0) {
		fwrite(STDERR, "This command must be run as root\n");
		exit(1);
	}
	if(!$user = posix_getpwnam($username)) {
		fwrite(STDERR, "Could not find $username user details\n");
		exit(1);
	}

	// /var/run/keys is writable by $username. Root must not open files in it (symlink attack).
	// As root, only create the directories and open the log file in /var/log/keys (owned by root),
	// then drop privileges before lock and pid file are opened.
	$rundir = dirname($pidfile);
	if(!is_dir($rundir) && !(mkdir($rundir) && chmod($rundir, 0755))) {
		fwrite(STDERR, "Could not create $rundir\n");
		exit(1);
	}
	if(fileowner($rundir) !== $user['uid'] && !chown($rundir, $user['uid'])) {
		fwrite(STDERR, "Could not change owner of $rundir to $username\n");
		exit(1);
	}
	if(!is_dir(dirname($logfile)) && !mkdir(dirname($logfile), 0755)) {
		fwrite(STDERR, "Could not create ".dirname($logfile)."\n");
		exit(1);
	}
	// Redirect STDOUT to the log file. fopen() uses the lowest free file descriptor, which is 1.
	fclose(STDOUT);
	$stdout = fopen($logfile, 'a');
	if(!$stdout) {
		fwrite(STDERR, "Could not open $logfile\n");
		exit(1);
	}
	if(!posix_initgroups($username, $user['gid']) || !posix_setgid($user['gid']) || !posix_setuid($user['uid'])) {
		fwrite(STDERR, "Could not drop privileges to $username\n");
		exit(1);
	}

	// Attempt to establish lock
	$lock = @fopen($lockfile, 'w+');
	if(!$lock) {
		fwrite(STDERR, "Could not open $lockfile (remove old files owned by root after the old process has stopped)\n");
		exit(1);
	}
	if(!flock($lock, LOCK_EX | LOCK_NB)) {
		fwrite(STDERR, "Could not establish lock, process already running?\n");
		exit(0);
	}
	// Fork process
	$pid = pcntl_fork();
	if($pid == -1) {
		// Something went wrong
		fwrite(STDERR, "Failed to fork\n");
		exit(1);
	} elseif($pid == 0) {
		// This is the child process
	} else {
		// This is the parent process
		// Write pidfile and exit. We hold the lock, so an existing pidfile is stale
		// (for example owned by root from an old version) and can be removed.
		@unlink($pidfile);
		$fh = @fopen($pidfile, 'w');
		if(!$fh) {
			fwrite(STDERR, "Could not write $pidfile\n");
			exit(1);
		}
		fwrite($fh, "$pid\n");
		fclose($fh);
		exit();
	}

	// We have now forked
	// Close STDIN/STDERR. STDOUT is already the log file.
	fclose(STDIN);
	fclose(STDERR);
	$stdin = fopen('/dev/null', 'r');
	$stderr = fopen('php://stdout', 'a');

	if(!isset($options['systemd'])) {
		// Make the current process a session leader
		if(posix_setsid() == -1) {
			die("Could not detach from terminal.");
		}
	}
}

// Set up signal handling
declare(ticks = 1);
$signal = null;
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGINT, "sig_handler");

require('../core.php');
require('sync-common.php');
dlog("Daemon started");
// Requests that are marked as in progress are from a daemon that stopped before the sync was done
$sync_request_dir->reset_sync_requests_in_progress();

$sync_procs = array();
$sync_running = array(); // request ID => array(server ID, account name or null) of each running sync
define('MAX_PROCS', 20);

$pipefile = '/var/run/keys/keys-sync.pipe';
$timerfile = '/var/run/keys/keys-sync.timer';
$key_password_enabled = isset($config["web"]["key_password_enabled"]) && $config["web"]["key_password_enabled"] == 1;
$password = null;
if($key_password_enabled) {
	if (!file_exists($pipefile)) { 
		if(!posix_mkfifo($pipefile, 0600)) {
			die("Could not create named pipe.");
		}
	} 
	$pipe = fopen($pipefile, "r+");
	if(!$pipe) {
		die("Could not open named pipe.");
	}
	if(!stream_set_blocking($pipe, 0)) {
		die("Could not unblock named pipe.");
	}
	$timer = fopen($timerfile, "w+");
	if(!$timer) {
		die("Could not open/create timer file.");
	}
	fprintf($timer, '%04d', 0);
	fseek($timer, 0);
}
$timeout = 0;

// Primary loop
while(is_null($signal)) {
	if($key_password_enabled && !feof($pipe)) {
		$read = rtrim(fgets($pipe), "\n\r\0");
		if (!empty($read)) {
			$password = $read;
			$timeout = 900; // 15 * 60
			fprintf($timer, '%04d', $timeout);
			fseek($timer, 0);
		}
	}
	try {
		$reqs = $sync_request_dir->list_pending_sync_requests();
		$tmp_password = $password;
		if(!$key_password_enabled || !is_null($password)) {
			$waiting_servers = array(); // servers with a sync of the whole server that waits
			foreach($reqs as $req) {
				if(count($sync_procs) > MAX_PROCS) break;
				// Two syncs that write the same keyfile must not run at the same time, otherwise the older
				// sync can upload its file after the newer one. A sync of the whole server (account name
				// null) runs alone on its server. Syncs of different accounts can run in parallel. This
				// also waits for a request that add_sync_request() set to pending again while its sync runs.
				if(isset($waiting_servers[$req->server_id])) continue;
				$blocked = false;
				foreach($sync_running as $running) {
					if($running[0] == $req->server_id && (is_null($running[1]) || is_null($req->account_name) || $running[1] === $req->account_name)) {
						$blocked = true;
						break;
					}
				}
				if($blocked) {
					// A waiting sync of the whole server must not wait for ever behind new account syncs
					if(is_null($req->account_name)) $waiting_servers[$req->server_id] = true;
					continue;
				}
				$args = array();
				$args[] = '--id';
				$args[] = $req->server_id;
				if(!is_null($req->account_name)) {
					$args[] = '--user';
					$args[] = $req->account_name;
				}
				if(!$key_password_enabled) {
					$args[] = '--no-password';
				}
				$req->set_in_progress();
				dlog("Sync process spawning for: {$req->server_id}/{$req->account_name}");
				$sync_procs[$req->id] = new SyncProcess(__DIR__.'/sync.php', $args, $password, $req);
				$sync_running[$req->id] = array($req->server_id, $req->account_name);
			}
		}
	} catch(mysqli_sql_exception $e) {
		if($e->getMessage() == 'MySQL server has gone away') {
			dlog("MySQL server has gone away");
			$connected = false;
			while(!$connected) {
				try {
					setup_database();
					$connected = true;
					dlog("MySQL connection re-established");
				} catch(mysqli_sql_exception $e2) {
					dlog("Attempt to reconnect failed: ".$e2->getMessage());
					sleep(5);
				}
			}
		}
	}
	foreach($sync_procs as $ref => &$sync_proc) {
		$data = $sync_proc->get_data();
		if(!empty($data)) {
			dlog($data['output']);
			unset($sync_proc);
			unset($sync_procs[$ref]);
			unset($sync_running[$ref]);
		}
	}
	sleep(1);
	if($timeout > 0) {
		$timeout -= 1;
		if($timeout == 0) {
			$password = null;
		}
		if($timeout % 10 == 0) {
			fprintf($timer, '%04d', $timeout);
			fseek($timer, 0);
		}
	}
}
dlog("Received exit signal");
// The running syncs may not finish, for example when systemd stops all processes of the unit.
// Set their requests to pending first, so that the destructors do not delete them and the next
// start runs them again.
$sync_request_dir->reset_sync_requests_in_progress();
// Then wait for the running syncs. sync.php ignores SIGTERM after the login, so that a sync is not
// stopped in the middle (also not by timeout). Until syncd ends, it keeps its lock, so no second
// daemon can start the same request while an old sync still runs. With systemd, TimeoutStopSec
// limits the wait.
unset($sync_proc); // The foreach above left a reference to the last element
while(count($sync_procs) > 0) {
	foreach($sync_procs as $ref => $proc) {
		$data = $proc->get_data();
		if(!empty($data)) {
			dlog($data['output']);
			unset($sync_procs[$ref]);
		}
	}
	unset($proc);
	sleep(1);
}

if(!isset($options['systemd'])) {
	// Release lock
	flock($lock, LOCK_UN);
	fclose($lock);
}
