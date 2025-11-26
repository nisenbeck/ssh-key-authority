#!/usr/bin/env bash
if [ "$(whoami)" = 'keys-sync' ]; then
  if [ ! -r /ska/config/config.ini ]; then
      echo "config.ini not found or incorrect permissions."
      echo "Permissions must be $(id -u keys-sync):$(id -g keys-sync) with at least 400"
      exit 1
  fi
  if [ ! -r /ska/config/keys-sync ]; then
      echo "Private key not found or incorrect permissions."
      echo "Create it with: ssh-keygen -t rsa -b 4096 -f ./config/keys-sync -N '' -C 'keys-sync'"
      echo "Permissions must be $(id -u keys-sync):$(id -g keys-sync) with 400"
      exit 1
  fi
  if [ ! -r /ska/config/keys-sync.pub ]; then
      echo "Public key not found or incorrect permissions."
      echo "Permissions must be $(id -u keys-sync):$(id -g keys-sync) with at least 400"
      exit 1
  fi
  if ! grep "^timeout_util = GNU coreutils$" /ska/config/config.ini > /dev/null; then
      echo "Timeout_util must be set to GNU coreutils."
      echo "Change it to: timeout_util = GNU coreutils"
      exit 1
  fi
elif [ "$(id -u)" -eq 0 ]; then
  if ! su - keys-sync -c /entrypoint.sh; then
    exit 1
  fi
  echo "Waiting for database..."
  for i in $(seq 1 10); do 
    if /ska/scripts/apply_migrations.php; then
      echo "Success"
      break
    fi
    echo "Trying again in 1 sec"
    sleep 1
  done
  
  /usr/sbin/cron
  /ska/scripts/syncd.php --user keys-sync
  apachectl -D FOREGROUND
else
  echo "Must be executed with root"
fi
