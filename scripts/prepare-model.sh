#!/usr/bin/env bash
set -euo pipefail

if command -v python3.12 >/dev/null && python3.12 -m pip --version >/dev/null 2>&1; then
    model_python=(python3.12)
elif command -v mise >/dev/null; then
    model_python=(mise exec python@3.12 -- python)
else
    printf '%s\n' 'Model export requires Python 3.12 with pip, or mise to provision it.' >&2
    exit 1
fi

dependencies=$(mktemp -d)
trap 'find "$dependencies" -depth -delete' EXIT
"${model_python[@]}" -m pip install --only-binary=:all: --target "$dependencies" -r requirements-model.txt
PYTHONPATH="$dependencies" "${model_python[@]}" scripts/export-model.py
