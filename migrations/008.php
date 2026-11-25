<?php
$migration_name = 'Keep access rules and access requests when the granting or requesting user is deleted';

// Before this migration, deleting a user also deleted all access rules that this user granted
// and all access requests that this user made for others (ON DELETE CASCADE).
// Now the reference is set to NULL, as migration 004 did for the event tables and group_member.added_by.

$this->database->query("ALTER TABLE `access` DROP FOREIGN KEY `FK_access_entity_3`");
$this->database->query("ALTER TABLE `access` MODIFY `granted_by` int(10) unsigned DEFAULT NULL");
$this->database->query("
	ALTER TABLE `access`
		ADD CONSTRAINT `FK_access_entity_3` FOREIGN KEY (`granted_by`) REFERENCES `entity` (`id`) ON DELETE SET NULL
");

$this->database->query("ALTER TABLE `access_request` DROP FOREIGN KEY `FK_access_request_entity_3`");
$this->database->query("ALTER TABLE `access_request` MODIFY `requested_by` int(10) unsigned DEFAULT NULL");
$this->database->query("
	ALTER TABLE `access_request`
		ADD CONSTRAINT `FK_access_request_entity_3` FOREIGN KEY (`requested_by`) REFERENCES `entity` (`id`) ON DELETE SET NULL
");
