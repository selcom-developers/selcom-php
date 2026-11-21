#!/usr/bin/env bash
# Check that tests/vectors is byte-identical to selcom-spec at the tag and commit in SPEC_VERSION.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tag="$(sed -n 1p "$root/SPEC_VERSION")"
want="$(sed -n 2p "$root/SPEC_VERSION")"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git clone --quiet --depth 1 --branch "$tag" https://github.com/selcom-developers/selcom-spec.git "$work/spec"
got="$(git -C "$work/spec" rev-parse HEAD)"
if [ "$got" != "$want" ]; then
  echo "SPEC_VERSION says $want but tag $tag is $got" >&2
  exit 1
fi

diff -r "$work/spec/vectors" "$root/tests/vectors"
echo "tests/vectors matches selcom-spec $tag ($got)"
