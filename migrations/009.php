<?php
$migration_name = 'Remember the last key expiry notice';

// cron.php runs more than once a day, and the number of days until the expiry stays the same for
// about 24 hours. The column holds the number of days of the last notice, so that each notice
// (21 days, 14 days, 7 to 1 days) is sent only once.
$this->database->query("ALTER TABLE `public_key` ADD COLUMN `expiry_notice_days` int(11) DEFAULT NULL");
