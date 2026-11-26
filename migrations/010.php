<?php
$migration_name = 'Add a fixed IP address for servers';

// Some servers have no hostname that SSH Key Authority can resolve. If fixed_ip_address is set,
// sync.php connects to this address and does not resolve the hostname.
$this->database->query("ALTER TABLE `server` ADD COLUMN `fixed_ip_address` varchar(64) DEFAULT NULL AFTER `ip_address`");
