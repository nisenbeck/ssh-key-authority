#!/usr/bin/env bash
for PID_FILE in /var/run/crond.pid /var/run/keys/keys-sync.pid /var/run/apache2/apache2.pid; do
    PID=$(cat "${PID_FILE}" 2>/dev/null || true)
    if ! [ -n "${PID}" ] || ! [ -d "/proc/${PID}" ]; then
        exit 1
    fi
done
