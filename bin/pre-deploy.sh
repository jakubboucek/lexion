# shellcheck disable=SC2086
set -eu;

SCRIPT_DIR="$(dirname $0)";
SOURCE_DIR="$(dirname $SCRIPT_DIR)";

cd "$SOURCE_DIR";

COMPOSER_PROCESS_TIMEOUT=0 docker compose exec web composer -d web install --no-dev;
