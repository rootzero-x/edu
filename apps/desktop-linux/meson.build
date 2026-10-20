project('tisu-desktop',
  version: '1.2.0',
  meson_version: '>= 0.62.0',
  license: 'MIT',
)

app_id = 'uz.uzstudents.tisu'
gnome = import('gnome')
python = import('python')
py = python.find_installation('python3')

prefix = get_option('prefix')
datadir = prefix / get_option('datadir')
pkgdatadir = datadir / 'tisu'
moduledir = pkgdatadir / 'tisu'

subdir('data')
subdir('src')

gnome.post_install(
  glib_compile_schemas: true,
  gtk_update_icon_cache: true,
  update_desktop_database: true,
)

test('unit tests', py, args: [meson.project_source_root() / 'tests' / 'test_core.py'],
  env: ['PYTHONDONTWRITEBYTECODE=1'])
