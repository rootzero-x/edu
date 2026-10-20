"""Build-time constants. meson overwrites PKGDATADIR/VERSION in the installed copy."""

import os

APP_ID = "uz.uzstudents.tisu"
APP_NAME = "TISU Edu"
VERSION = "1.0.0"
# Directory with data files (ui icons, css). meson replaces this; when running
# from the source tree it points at apps/desktop-linux/data.
PKGDATADIR = "@PKGDATADIR@"
if PKGDATADIR.startswith("@"):
    PKGDATADIR = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "..", "data"))

DEFAULT_API_URL = "https://api.tisu.uzstudents.uz"
DEFAULT_WEB_URL = "https://tisu.uzstudents.uz"
WEBSITE = DEFAULT_WEB_URL
ISSUE_URL = "https://github.com/rootzero-x/edu/issues"
