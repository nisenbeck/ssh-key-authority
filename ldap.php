<?php
class LDAP {
	private $conn;
	private $host;
	private $starttls;
	private $bind_dn;
	private $bind_password;
	private $options;

	public function __construct($host, $starttls, $bind_dn, $bind_password, $options) {
		$this->conn = null;
		$this->host = $host;
		$this->starttls = $starttls;
		$this->bind_dn = $bind_dn;
		$this->bind_password = $bind_password;
		$this->options = $options;
	}

	private function connect() {
		// Keep the connection only after TLS and bind succeeded. Otherwise later searches
		// would use an unbound (anonymous) or unencrypted connection.
		// The global error handler converts warnings from the ldap functions to ErrorException.
		try {
			$conn = ldap_connect($this->host);
			if($conn === false) throw new LDAPConnectionFailureException('Invalid LDAP connection settings');
			if($this->starttls) {
				if(!ldap_start_tls($conn)) throw new LDAPConnectionFailureException('Could not initiate TLS connection to LDAP server');
			}
			foreach($this->options as $option => $value) {
				ldap_set_option($conn, $option, $value);
			}
			if(!empty($this->bind_dn)) {
				if(!ldap_bind($conn, $this->bind_dn, $this->bind_password)) throw new LDAPConnectionFailureException('Could not bind to LDAP server');
			}
		} catch(ErrorException $e) {
			// No previous exception: its stack trace can contain the bind password (PHP < 8.2)
			throw new LDAPConnectionFailureException($e->getMessage());
		}
		$this->conn = $conn;
	}

	public function search($basedn, $filter, $fields = array()) {
		if(is_null($this->conn)) $this->connect();
		try {
			if(empty($fields)) $r = @ldap_search($this->conn, $basedn, $filter);
			else $r = @ldap_search($this->conn, $basedn, $filter, $fields);
		} catch(ErrorException $e) {
			// The global error handler converts the warning, also with '@'. Without a bind_dn, or when
			// the server stops during a cron run, this is the first contact that fails. Stop then,
			// as connect() does, so that cron.php does not try each user until the network timeout.
			// -1 LDAP_SERVER_DOWN, -5 LDAP_TIMEOUT, -11 LDAP_CONNECT_ERROR
			if(in_array(ldap_errno($this->conn), array(-1, -5, -11))) {
				$this->conn = null;
				throw new LDAPConnectionFailureException($e->getMessage());
			}
			throw $e;
		}
		if($r) {
			// Fetch entries
			$result = @ldap_get_entries($this->conn, $r);
			unset($result['count']);
			$items = array();
			foreach($result as $item) {
				unset($item['count']);
				$itemResult = array();
				foreach($item as $key => $values) {
					if(!is_int($key)) {
						if(is_array($values)) {
							unset($values['count']);
							if(count($values) == 1) $values = $values[0];
						}
						$itemResult[$key] = $values;
					}
				}
				$items[] = $itemResult;
			}
			return $items;
		}
		return false;
	}

	public static function escape($str = '') {
		$metaChars = array("\\00", "\\", "(", ")", "*");
		$quotedMetaChars = array();
		foreach($metaChars as $key => $value) {
			$quotedMetaChars[$key] = '\\'. dechex(ord($value));
		}
		$str = str_replace($metaChars, $quotedMetaChars, $str);
		return $str;
	}
}

class LDAPConnectionFailureException extends RuntimeException {}
