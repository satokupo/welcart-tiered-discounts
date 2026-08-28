#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${ROOT_DIR}/docker/local.env"
INIT_SCRIPT="${SCRIPT_DIR}/init-local-env.sh"

usage() {
    cat <<'USAGE'
Usage: ./scripts/dev.sh <recommended|latest|minimum> <command> [args...]

Commands:
  init       Create docker/local.env once with local-only values.
  config     Validate the selected Compose definition without printing secrets.
  up         Start the selected environment in the background.
  down       Stop the selected environment and keep its volumes.
  reset      Stop the selected environment and remove its volumes.
  bootstrap  Install WordPress, Welcart 2.12.1, and local options.
  wp         Run WP-CLI in the selected environment.
  logs       Show logs for the selected environment.
  versions   Show runtime and installed plugin versions.
  quality    Run project-local Composer and quality checks (recommended only).
USAGE
}

if [[ "$#" -eq 1 && "$1" == "init" ]]; then
    exec "${INIT_SCRIPT}"
fi

if [[ "$#" -lt 2 ]]; then
    usage >&2
    exit 64
fi

environment="$1"
command="$2"
shift 2

case "${environment}" in
    recommended)
        compose_file="${ROOT_DIR}/compose.yaml"
        wp_service="wordpress"
        cli_service="cli"
        wp_url_variable="RECOMMENDED_WP_URL"
        wp_user_variable="RECOMMENDED_WP_ADMIN_USER"
        wp_password_variable="RECOMMENDED_WP_ADMIN_PASSWORD"
        wp_email_variable="RECOMMENDED_WP_ADMIN_EMAIL"
        ;;
    latest)
        compose_file="${ROOT_DIR}/compose.latest.yaml"
        wp_service="wordpress"
        cli_service="cli"
        wp_url_variable="LATEST_WP_URL"
        wp_user_variable="LATEST_WP_ADMIN_USER"
        wp_password_variable="LATEST_WP_ADMIN_PASSWORD"
        wp_email_variable="LATEST_WP_ADMIN_EMAIL"
        ;;
    minimum)
        compose_file="${ROOT_DIR}/compose.minimum.yaml"
        wp_service="wordpress"
        cli_service="wordpress"
        wp_url_variable="MINIMUM_WP_URL"
        wp_user_variable="MINIMUM_WP_ADMIN_USER"
        wp_password_variable="MINIMUM_WP_ADMIN_PASSWORD"
        wp_email_variable="MINIMUM_WP_ADMIN_EMAIL"
        ;;
    *)
        printf '%s\n' "Unknown environment: ${environment}" >&2
        usage >&2
        exit 64
        ;;
esac

if [[ "${command}" == "init" ]]; then
    exec "${INIT_SCRIPT}"
fi

if [[ ! -f "${ENV_FILE}" ]]; then
    printf '%s\n' 'docker/local.env is missing. Run: ./scripts/dev.sh init' >&2
    exit 78
fi

if [[ ! -f "${compose_file}" ]]; then
    printf '%s\n' "Compose file is missing: ${compose_file}" >&2
    exit 78
fi

compose=(docker compose --env-file "${ENV_FILE}" -f "${compose_file}")

run_wp() {
    if [[ "${environment}" == "minimum" ]]; then
        "${compose[@]}" exec -T "${wp_service}" wp --allow-root "$@"
    else
        "${compose[@]}" run --rm "${cli_service}" wp "$@"
    fi
}

load_local_env() {
    set -a
    # shellcheck disable=SC1090
    . "${ENV_FILE}"
    set +a
}

case "${command}" in
    config)
        "${compose[@]}" config --quiet
        printf '%s\n' "Compose config is valid: ${environment}"
        ;;
    up)
        "${compose[@]}" up -d "$@"
        ;;
    down)
        "${compose[@]}" down "$@"
        ;;
    reset)
        "${compose[@]}" down --volumes --remove-orphans "$@"
        ;;
    bootstrap)
        load_local_env
        "${compose[@]}" up -d
        if ! run_wp core is-installed >/dev/null 2>&1; then
            run_wp core install \
                --url="${!wp_url_variable}" \
                --title='Welcart local development' \
                --admin_user="${!wp_user_variable}" \
                --admin_password="${!wp_password_variable}" \
                --admin_email="${!wp_email_variable}" \
                --locale=ja \
                --skip-email
        fi
        run_wp config set WP_DEBUG true --raw
        run_wp config set WP_DEBUG_LOG true --raw
        run_wp config set WP_DEBUG_DISPLAY false --raw
        run_wp config set WP_ENVIRONMENT_TYPE local
        if ! run_wp plugin is-installed usc-e-shop >/dev/null 2>&1; then
            run_wp plugin install \
                https://downloads.wordpress.org/plugin/usc-e-shop.2.12.1.zip
        fi
        run_wp plugin activate usc-e-shop
        if [[ "${environment}" == "recommended" ]]; then
            if ! run_wp plugin is-installed plugin-check >/dev/null 2>&1; then
                run_wp plugin install plugin-check --version=2.1.0
            fi
            run_wp plugin activate plugin-check
        fi
        run_wp language core install ja --activate
        run_wp option update WPLANG ja
        run_wp option update timezone_string Asia/Tokyo
        ;;
    wp)
        if [[ "$#" -eq 0 ]]; then
            printf '%s\n' 'wp requires a WP-CLI command.' >&2
            exit 64
        fi
        run_wp "$@"
        ;;
    logs)
        "${compose[@]}" logs "$@"
        ;;
    versions)
        printf '%s\n' "Environment: ${environment}"
        if [[ "${environment}" == "minimum" ]]; then
            "${compose[@]}" exec -T "${wp_service}" php -r 'printf("PHP %s\n", PHP_VERSION);'
            run_wp --info | sed -n '1,12p'
        else
            "${compose[@]}" run --rm "${cli_service}" php -r 'printf("PHP %s\n", PHP_VERSION);'
            run_wp --info | sed -n '1,12p'
        fi
        printf '%s\n' 'WordPress:'
        run_wp core version
        printf '%s\n' 'Welcart:'
        run_wp plugin get usc-e-shop --field=version 2>/dev/null || true
        ;;
    quality)
        if [[ "${environment}" != "recommended" ]]; then
            printf '%s\n' 'The quality service is available only in the recommended environment.' >&2
            exit 64
        fi
        "${compose[@]}" run --rm quality bash -lc '
            set -Eeuo pipefail
            composer install --no-interaction --prefer-dist --no-progress
            composer validate --strict
            composer check-platform-reqs
            composer quality
        '
        ;;
    *)
        printf '%s\n' "Unknown command: ${command}" >&2
        usage >&2
        exit 64
        ;;
esac
