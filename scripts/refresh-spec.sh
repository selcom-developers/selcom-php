#!/usr/bin/env bash
# Refresh the copy of the shared test vectors in tests/vectors from selcom-developers/selcom-spec.
#
#   scripts/refresh-spec.sh            # use the tag recorded in SPEC_VERSION
#   scripts/refresh-spec.sh v0.2.0     # move to another tag
#
# The tag and the commit it points to are written back to SPEC_VERSION.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tag="${1:-$(head -n 1 "$root/SPEC_VERSION")}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git clone --quiet --depth 1 --branch "$tag" https://github.com/selcom-developers/selcom-spec.git "$work/spec"
sha="$(git -C "$work/spec" rev-parse HEAD)"

rm -rf "$root/tests/vectors"
mkdir -p "$root/tests/vectors"
cp "$work/spec/vectors/signing.json" "$root/tests/vectors/signing.json"
cp -r "$work/spec/vectors/responses" "$root/tests/vectors/responses"
printf '%s\n%s\n' "$tag" "$sha" > "$root/SPEC_VERSION"

echo "tests/vectors refreshed from selcom-spec $tag ($sha)"
