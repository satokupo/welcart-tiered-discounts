#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"
EXAMPLE_FILE="${ROOT_DIR}/docker/local.env.example"
ENV_FILE="${ROOT_DIR}/docker/local.env"

if [[ -f "${ENV_FILE}" ]]; then
    printf '%s\n' 'docker/local.env already exists; leaving it unchanged.'
    exit 0
fi

if [[ ! -f "${EXAMPLE_FILE}" ]]; then
    printf '%s\n' "Missing environment template: ${EXAMPLE_FILE}" >&2
    exit 1
fi

random_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 24
    else
        od -An -N24 -tx1 /dev/urandom | tr -d ' \n'
    fi
}

local_uid="$(id -u)"
local_gid="$(id -g)"
recommended_db_password="$(random_secret)"
recommended_db_root_password="$(random_secret)"
recommended_wp_admin_password="$(random_secret)"
latest_db_password="$(random_secret)"
latest_db_root_password="$(random_secret)"
latest_wp_admin_password="$(random_secret)"
minimum_db_password="$(random_secret)"
minimum_db_root_password="$(random_secret)"
minimum_wp_admin_password="$(random_secret)"

umask 077
awk \
    -v local_uid="${local_uid}" \
    -v local_gid="${local_gid}" \
    -v recommended_db_password="${recommended_db_password}" \
    -v recommended_db_root_password="${recommended_db_root_password}" \
    -v recommended_wp_admin_password="${recommended_wp_admin_password}" \
    -v latest_db_password="${latest_db_password}" \
    -v latest_db_root_password="${latest_db_root_password}" \
    -v latest_wp_admin_password="${latest_wp_admin_password}" \
    -v minimum_db_password="${minimum_db_password}" \
    -v minimum_db_root_password="${minimum_db_root_password}" \
    -v minimum_wp_admin_password="${minimum_wp_admin_password}" \
    '{
        gsub("__LOCAL_UID__", local_uid)
        gsub("__LOCAL_GID__", local_gid)
        gsub("__RECOMMENDED_DB_PASSWORD__", recommended_db_password)
        gsub("__RECOMMENDED_DB_ROOT_PASSWORD__", recommended_db_root_password)
        gsub("__RECOMMENDED_WP_ADMIN_PASSWORD__", recommended_wp_admin_password)
        gsub("__LATEST_DB_PASSWORD__", latest_db_password)
        gsub("__LATEST_DB_ROOT_PASSWORD__", latest_db_root_password)
        gsub("__LATEST_WP_ADMIN_PASSWORD__", latest_wp_admin_password)
        gsub("__MINIMUM_DB_PASSWORD__", minimum_db_password)
        gsub("__MINIMUM_DB_ROOT_PASSWORD__", minimum_db_root_password)
        gsub("__MINIMUM_WP_ADMIN_PASSWORD__", minimum_wp_admin_password)
        print
    }' "${EXAMPLE_FILE}" >"${ENV_FILE}"

chmod 600 "${ENV_FILE}"
printf '%s\n' 'Created docker/local.env with local-only values (credentials suppressed).'
