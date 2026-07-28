#!/usr/bin/env bash
#
# sync_export_apply.sh
#
# Delivers an export tree (from scripts/sync_export.php) to its target servers over SFTP - the
# same protocol scripts/sync.php uses. Logs in as "keys-sync" (or --user). Run this from a
# machine with network access to the targets when the main SKA host has none (e.g. a jump host).
#
# Per host: verifies the SSH host key against SKA's pinned fingerprint, checks that "check"-
# flagged (manual LDAP) accounts exist before upload, uploads the account files with correct
# permissions, and optionally (--prune) removes remote files no longer in the export.
#
# Does not report back to SKA's DB - once connectivity is restored, run scripts/sync.php (or
# click "resync") and it reconciles the sync status itself.
#
# Hosts with no pinned fingerprint yet are refused by default. Use --known-fingerprints FILE
# ("hostname FINGERPRINT" lines, out-of-band trust) or --allow-unpinned-hostkey (TOFU) to allow
# them. The trusted fingerprint is written to <host>/.ska-learned-fingerprint as a local audit
# record only - sync.php pins it in SKA itself on its first direct connection.
#
# Requires: bash, ssh, sftp, ssh-keyscan, jq, sha256sum, base64, awk.
# Exit code is non-zero if any host failed.

set -uo pipefail

SCRIPT_NAME=$(basename "$0")
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)
EXPORT_DIR=""
IDENTITY=""
REMOTE_USER="keys-sync"
HOST_FILTER=""
PRUNE=0
DRY_RUN=0
ALLOW_UNPINNED=0
KNOWN_FINGERPRINTS=""
JOBS=1
TIMEOUT=5

usage() {
	cat <<EOF
Usage: $SCRIPT_NAME --identity KEYFILE [OPTIONS]

  --dir DIR              export directory produced by sync_export.php (default: the
                          directory this script itself resides in, so a bundled copy
                          from the export tree works standalone)
  --identity FILE         SSH private key to authenticate as (required)
  --user NAME             remote user to log in as (default: keys-sync)
  --host HOSTNAMES        only process these hosts (comma-separated, matches the
                          hostname as recorded by SKA, not the sanitized folder name)
  --prune                 remove remote files that are no longer part of the export
                          (mirrors the cleanup step of the live sync.php)
  --known-fingerprints FILE  "hostname FINGERPRINT" lines (one per host) with fingerprints
                          obtained out-of-band, for hosts SKA has not pinned a host key
                          fingerprint for yet (e.g. newly provisioned servers). Takes
                          precedence over --allow-unpinned-hostkey.
  --allow-unpinned-hostkey  trust-on-first-use for hosts that SKA has not yet pinned
                          a host key fingerprint for and that are not listed in
                          --known-fingerprints. Use with caution.
  --jobs N                number of hosts to process in parallel (default: 1)
  --timeout SECONDS       SSH/host key scan connection timeout per host (default: 5)
  --dry-run               show what would be done without changing anything remote
  -h, --help              show this help

After connectivity to a host is restored, run scripts/sync.php against it (or click
"resync" in the web UI) to let SKA reconcile its recorded sync status - this script
does not update SKA itself. The same applies to host key pinning: sync.php pins the
fingerprint itself on its first direct connection, so nothing needs to be imported
from here.
EOF
}

while [ $# -gt 0 ]; do
	case "$1" in
		--dir) EXPORT_DIR="$2"; shift 2 ;;
		--identity) IDENTITY="$2"; shift 2 ;;
		--user) REMOTE_USER="$2"; shift 2 ;;
		--host) HOST_FILTER="$2"; shift 2 ;;
		--prune) PRUNE=1; shift ;;
		--allow-unpinned-hostkey) ALLOW_UNPINNED=1; shift ;;
		--known-fingerprints) KNOWN_FINGERPRINTS="$2"; shift 2 ;;
		--jobs) JOBS="$2"; shift 2 ;;
		--timeout) TIMEOUT="$2"; shift 2 ;;
		--dry-run) DRY_RUN=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 1 ;;
	esac
done

if [ -z "$EXPORT_DIR" ]; then
	EXPORT_DIR="$SCRIPT_DIR"
fi
if [ -z "$IDENTITY" ]; then
	echo "Error: --identity is required" >&2
	usage
	exit 1
fi
if [ ! -d "$EXPORT_DIR" ]; then
	echo "Error: export directory $EXPORT_DIR does not exist" >&2
	exit 1
fi
if [ ! -f "$IDENTITY" ]; then
	echo "Error: identity file $IDENTITY does not exist" >&2
	exit 1
fi
if [ -n "$KNOWN_FINGERPRINTS" ] && [ ! -f "$KNOWN_FINGERPRINTS" ]; then
	echo "Error: known-fingerprints file $KNOWN_FINGERPRINTS does not exist" >&2
	exit 1
fi
if ! [[ "$JOBS" =~ ^[0-9]+$ ]] || [ "$JOBS" -lt 1 ]; then
	echo "Error: --jobs must be a positive integer, got '$JOBS'" >&2
	exit 1
fi
if ! [[ "$TIMEOUT" =~ ^[0-9]+$ ]] || [ "$TIMEOUT" -lt 1 ]; then
	echo "Error: --timeout must be a positive integer (seconds), got '$TIMEOUT'" >&2
	exit 1
fi
for cmd in ssh sftp ssh-keyscan jq sha256sum base64 awk; do
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "Error: required command '$cmd' not found in PATH" >&2
		exit 1
	fi
done

EXPORT_DIR=$(cd "$EXPORT_DIR" && pwd)
if [ -n "$KNOWN_FINGERPRINTS" ]; then
	KNOWN_FINGERPRINTS=$(cd "$(dirname "$KNOWN_FINGERPRINTS")" && pwd)/$(basename "$KNOWN_FINGERPRINTS")
fi
if [ -n "$HOST_FILTER" ]; then
	# Normalize once (trim whitespace around commas) so every later comparison against
	# HOST_FILTER - including process_host's own case-match - agrees on the same spelling.
	IFS=',' read -ra _host_filter_parts <<<"$HOST_FILTER"
	_normalized=()
	for _p in "${_host_filter_parts[@]}"; do
		_p=$(printf '%s' "$_p" | awk '{$1=$1};1')
		[ -n "$_p" ] && _normalized+=("$_p")
	done
	HOST_FILTER=$(IFS=','; echo "${_normalized[*]}")
fi
WORKDIR=$(mktemp -d)
trap 'rm -rf "$WORKDIR"' EXIT

log() {
	printf '%s %s\n' "$(date -u +%FT%TZ)" "$1"
}

# Mirrors sanitize_export_hostname() in sync_export.php, so --host can resolve straight to a
# folder without opening every exported host's manifest first.
sanitize_hostname() {
	printf '%s' "$1" | sed -E 's/[^A-Za-z0-9._-]/_/g'
}

# SKA-style host key fingerprint: uppercase hex SHA256 of the raw decoded key (matches sync.php).
fingerprint_of_key() {
	local b64="$1"
	printf '%s' "$b64" | base64 -d 2>/dev/null | sha256sum | awk '{print toupper($1)}'
}

# Look up a hostname's fingerprint in --known-fingerprints, if given.
lookup_known_fingerprint() {
	local hostname="$1"
	if [ -z "$KNOWN_FINGERPRINTS" ]; then
		return 0
	fi
	awk -v h="$hostname" '$1 == h { print toupper($2); exit }' "$KNOWN_FINGERPRINTS"
}

# Fetch host keys via ssh-keyscan and find the one matching expected_fp (or TOFU the first key
# if none pinned and --allow-unpinned-hostkey). Prints "keytype base64key", or nothing on failure.
find_trusted_host_key() {
	local hostname="$1" port="$2" expected_fp="$3"
	local keyscan_output
	keyscan_output=$(ssh-keyscan -T "$TIMEOUT" -p "$port" "$hostname" 2>/dev/null)
	if [ -z "$keyscan_output" ]; then
		return 1
	fi
	if [ -z "$expected_fp" ]; then
		if [ "$ALLOW_UNPINNED" != "1" ]; then
			return 1
		fi
		# TOFU: accept the first key offered
		echo "$keyscan_output" | head -n1 | awk '{print $2, $3}'
		return 0
	fi
	while IFS= read -r line; do
		[ -z "$line" ] && continue
		local b64 keytype fp
		keytype=$(awk '{print $2}' <<<"$line")
		b64=$(awk '{print $3}' <<<"$line")
		fp=$(fingerprint_of_key "$b64")
		if [ "$fp" = "$expected_fp" ]; then
			echo "$keytype $b64"
			return 0
		fi
	done <<<"$keyscan_output"
	return 1
}

process_host() {
	local host_dir="$1"
	local manifest="$host_dir/.ska-manifest.json"
	local folder_name
	folder_name=$(basename "$host_dir")

	if [ ! -f "$manifest" ]; then
		return 0
	fi

	local hostname port keydir expected_fp
	hostname=$(jq -r '.hostname' "$manifest")
	port=$(jq -r '.port' "$manifest")
	keydir=$(jq -r '.keydir' "$manifest")
	expected_fp=$(jq -r '.rsa_key_fingerprint // empty' "$manifest")

	if [ -n "$HOST_FILTER" ]; then
		case ",$HOST_FILTER," in
			*",$hostname,"*) ;;
			*) return 0 ;;
		esac
	fi

	# --known-fingerprints takes precedence but must not contradict a fingerprint SKA already
	# has pinned. new_pin_needed = SKA has no fingerprint yet (decides whether to audit-log below).
	local oob_fp new_pin_needed=0
	oob_fp=$(lookup_known_fingerprint "$hostname")
	if [ -n "$oob_fp" ] && [ -n "$expected_fp" ] && [ "$oob_fp" != "$expected_fp" ]; then
		log "$hostname: FAILED - fingerprint in --known-fingerprints does not match the one already pinned in SKA (possible MITM or stale entry, resolve manually)"
		return 1
	fi
	if [ -z "$expected_fp" ]; then
		new_pin_needed=1
	fi
	if [ -n "$oob_fp" ]; then
		expected_fp="$oob_fp"
	fi
	if [ -z "$expected_fp" ]; then
		log "$hostname: no host key fingerprint pinned in SKA and none supplied via --known-fingerprints"
	fi

	log "$hostname: verifying host key"
	local trusted_key
	if ! trusted_key=$(find_trusted_host_key "$hostname" "$port" "$expected_fp"); then
		log "$hostname: FAILED host key verification (expected fingerprint: ${expected_fp:-<none pinned>})"
		return 1
	fi
	local keytype b64
	keytype=$(awk '{print $1}' <<<"$trusted_key")
	b64=$(awk '{print $2}' <<<"$trusted_key")
	local known_hosts_line
	if [ "$port" = "22" ]; then
		known_hosts_line="$hostname $keytype $b64"
	else
		known_hosts_line="[$hostname]:$port $keytype $b64"
	fi
	local known_hosts_file="$WORKDIR/${folder_name}.known_hosts"
	printf '%s\n' "$known_hosts_line" > "$known_hosts_file"

	if [ "$new_pin_needed" = "1" ] && [ "$DRY_RUN" != "1" ]; then
		local actual_fp
		actual_fp=$(fingerprint_of_key "$b64")
		printf '%s\n' "$actual_fp" > "$host_dir/.ska-learned-fingerprint"
		log "$hostname: trusted new fingerprint $actual_fp (recorded to .ska-learned-fingerprint for audit purposes only)"
	fi

	local ssh_opts=(-i "$IDENTITY" -p "$port" -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts_file" -o ConnectTimeout="$TIMEOUT" -o BatchMode=yes)
	local sftp_opts=(-i "$IDENTITY" -P "$port" -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts_file" -o ConnectTimeout="$TIMEOUT" -o BatchMode=yes)

	if ! ssh "${ssh_opts[@]}" "$REMOTE_USER@$hostname" "test -d $(printf '%q' "$keydir")" ; then
		log "$hostname: FAILED - remote key directory $keydir does not exist or login failed"
		return 1
	fi

	local files_to_send=()
	local account
	for account in "$host_dir"/*; do
		local base
		base=$(basename "$account")
		[ -f "$account" ] || continue

		local check
		check=$(jq -r --arg u "$base" '.files[$u].check // false' "$manifest")
		if [ "$check" = "true" ]; then
			if ! ssh "${ssh_opts[@]}" "$REMOTE_USER@$hostname" "id $(printf '%q' "$base")" >/dev/null 2>&1; then
				log "$hostname: account '$base' does not exist remotely, skipping (LDAP manual authorization)"
				continue
			fi
		fi
		files_to_send+=("$account")
	done

	local upload_failed=0
	if [ ${#files_to_send[@]} -gt 0 ]; then
		if [ "$DRY_RUN" = "1" ]; then
			log "$hostname: [dry-run] would upload: $(printf '%s ' "${files_to_send[@]##*/}")"
		else
			# sftp batch upload, then chmod 644 (put doesn't carry over local permission bits)
			local batch_file="$WORKDIR/${folder_name}.sftp_batch"
			: > "$batch_file"
			local f remote_name
			for f in "${files_to_send[@]}"; do
				remote_name=$(basename "$f")
				printf 'put "%s" "%s"\n' "$f" "$remote_name" >> "$batch_file"
				printf 'chmod 644 "%s"\n' "$remote_name" >> "$batch_file"
			done
			if sftp -b "$batch_file" "${sftp_opts[@]}" "$REMOTE_USER@$hostname:$keydir" >/dev/null 2>"$WORKDIR/${folder_name}.err"; then
				log "$hostname: uploaded ${#files_to_send[@]} account file(s)"
			else
				upload_failed=1
				log "$hostname: FAILED to upload account files: $(cat "$WORKDIR/${folder_name}.err")"
			fi
		fi
	fi

	local prune_failed=0
	if [ "$PRUNE" = "1" ] && [ "$DRY_RUN" != "1" ]; then
		local remote_listing
		remote_listing=$(ssh "${ssh_opts[@]}" "$REMOTE_USER@$hostname" "cd $(printf '%q' "$keydir") && ls -1" 2>/dev/null || true)
		local remote_file
		while IFS= read -r remote_file; do
			[ -z "$remote_file" ] && continue
			case "$remote_file" in
				keys-sync) continue ;;
			esac
			if [ ! -f "$host_dir/$remote_file" ]; then
				if ssh "${ssh_opts[@]}" "$REMOTE_USER@$hostname" "rm -f -- $(printf '%q' "$keydir/$remote_file")"; then
					log "$hostname: pruned stale remote file '$remote_file'"
				else
					prune_failed=1
					log "$hostname: FAILED to prune stale remote file '$remote_file'"
				fi
			fi
		done <<<"$remote_listing"
	fi

	if [ "$upload_failed" = "1" ] || [ "$prune_failed" = "1" ]; then
		return 1
	fi
	log "$hostname: done"
	return 0
}

export -f process_host find_trusted_host_key fingerprint_of_key lookup_known_fingerprint log
export IDENTITY REMOTE_USER HOST_FILTER PRUNE DRY_RUN ALLOW_UNPINNED KNOWN_FINGERPRINTS WORKDIR TIMEOUT

host_dirs=()
not_found=0
if [ -n "$HOST_FILTER" ]; then
	# Resolve the requested hostnames directly to their export folder instead of scanning (and
	# jq-reading the manifest of) every exported host - keeps --host fast in a large export tree.
	IFS=',' read -ra want_hosts <<<"$HOST_FILTER"
	for h in "${want_hosts[@]}"; do
		[ -z "$h" ] && continue
		d="$EXPORT_DIR/$(sanitize_hostname "$h")"
		if [ -d "$d" ]; then
			host_dirs+=("$d")
		else
			log "$h: no export folder found in $EXPORT_DIR, skipping"
			not_found=$((not_found + 1))
		fi
	done
else
	for d in "$EXPORT_DIR"/*/; do
		[ -d "$d" ] || continue
		host_dirs+=("${d%/}")
	done
fi

if [ ${#host_dirs[@]} -eq 0 ]; then
	if [ "$not_found" -gt 0 ]; then
		log "None of the requested --host entries were found in $EXPORT_DIR"
		exit 1
	fi
	log "No host directories found in $EXPORT_DIR"
	exit 0
fi

failures=0
if [ "$JOBS" -le 1 ]; then
	for d in "${host_dirs[@]}"; do
		process_host "$d" || failures=$((failures + 1))
	done
else
	printf '%s\n' "${host_dirs[@]}" | xargs -P "$JOBS" -I{} bash -c 'process_host "$@"' _ {} || failures=1
fi
failures=$((failures + not_found))

if [ "$failures" -gt 0 ]; then
	log "Completed with failures on $failures host(s)"
	exit 1
fi
log "Completed successfully - remember to re-run scripts/sync.php against these hosts once direct connectivity is restored, so SKA's recorded sync status catches up"
exit 0
