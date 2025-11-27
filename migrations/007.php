<?php
$migration_name = 'Upgrade host key fingerprint format for phpseclib3 compatibility';

// Migration: Switch from proprietary MD5 format to standard SHA256 format
// 
// Background:
// - phpseclib 1 used a non-standard fingerprint format: md5(substr(rawKey, 8))
// - phpseclib 3 calculates fingerprints according to SSH standard: sha256(base64_decode(keyData))
// 
// This migration:
// 1. Extends the field from 32 to 64 characters (SHA256 hex)
// 2. Resets all existing fingerprints to NULL
// 3. Fingerprints will be automatically saved in the new format on next sync
//
// Note: After this migration, all servers must be synced once
// to store the host key fingerprints in the new format.
// Please run 'php scripts/sync.php --all' to recalculate the fingerprints.

$this->database->query("
    ALTER TABLE `server` 
    MODIFY COLUMN `rsa_key_fingerprint` char(64) DEFAULT NULL
");

// Reset all existing fingerprints - they will be recalculated on next sync
$this->database->query("
    UPDATE `server` SET `rsa_key_fingerprint` = NULL WHERE `rsa_key_fingerprint` IS NOT NULL
");
