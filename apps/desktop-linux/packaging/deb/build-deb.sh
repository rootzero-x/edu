#!/bin/sh
# Build an architecture-independent .deb (Debian 13+, Ubuntu 24.04+).
#   packaging/deb/build-deb.sh [output-dir]
# Needs: meson, ninja, dpkg-deb, glib-compile-schemas (libglib2.0-bin).
set -eu
here=$(cd "$(dirname "$0")/../.." && pwd)
out=${1:-"$here/dist"}
mkdir -p "$out"
out=$(cd "$out" && pwd)
version=$(sed -n "s/^  version: '\(.*\)',/\1/p" "$here/meson.build")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

meson setup "$work/build" "$here" --prefix=/usr --buildtype=release >/dev/null
meson test -C "$work/build" --print-errorlogs
DESTDIR="$work/root" meson install -C "$work/build" --no-rebuild >/dev/null
find "$work/root" -name __pycache__ -prune -exec rm -rf {} +

install -d -m 755 "$work/root/DEBIAN" "$work/root/usr/share/doc/tisu-desktop"
install -m 644 "$here/README.md" "$work/root/usr/share/doc/tisu-desktop/README.md"
size=$(du -sk "$work/root/usr" | cut -f1)
cat > "$work/root/DEBIAN/control" <<CONTROL
Package: tisu-desktop
Version: $version
Section: education
Priority: optional
Architecture: all
Installed-Size: $size
Depends: python3 (>= 3.10), python3-gi (>= 3.42), gir1.2-gtk-4.0 (>= 4.12), gir1.2-adw-1 (>= 1.5), gir1.2-soup-3.0, gir1.2-secret-1
Recommends: gnome-keyring | kwalletmanager, xdg-desktop-portal-gnome | xdg-desktop-portal-gtk
Maintainer: TISU Edu <rootzero.xz@gmail.com>
Homepage: https://tisu.uzstudents.uz
Description: TISU Edu education center desktop app
 Native GNOME (GTK4 + libadwaita) client for the TISU Edu platform:
 courses and lessons, quizzes, groups and attendance, news with desktop
 notifications, certificates and reports.
CONTROL
# Schemas, icon cache and desktop database are refreshed by dpkg triggers.
dpkg-deb --root-owner-group --build "$work/root" "$out/tisu-desktop_${version}_all.deb"
echo "$out/tisu-desktop_${version}_all.deb"
