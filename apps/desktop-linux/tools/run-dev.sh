#!/bin/sh
# Run the app straight from the source tree (no install).
#   tools/run-dev.sh                         # production API
#   TISU_API_URL=http://127.0.0.1:8000 tools/run-dev.sh
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
schemas=${XDG_CACHE_HOME:-$HOME/.cache}/tisu-dev-schemas
mkdir -p "$schemas"
cp "$here/data/uz.uzstudents.tisu.gschema.xml" "$schemas/"
glib-compile-schemas "$schemas"
export GSETTINGS_SCHEMA_DIR="$schemas"
export PYTHONPATH="$here/src${PYTHONPATH:+:$PYTHONPATH}"
exec python3 -m tisu "$@"
