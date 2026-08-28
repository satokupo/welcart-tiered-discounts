#!/bin/sh
set -eu

# Consume the message without storing or forwarding its content.
cat >/dev/null
printf '%s\n' 'mail_suppressed_by_local_development_environment' >>/tmp/dev-mail.log
