conf = configuration_data()
conf.set('PYTHON', py.full_path())
conf.set('PKGDATADIR', pkgdatadir)
conf.set('VERSION', meson.project_version())

configure_file(
  input: 'tisu.in',
  output: 'tisu',
  configuration: conf,
  install: true,
  install_dir: get_option('bindir'),
  install_mode: 'rwxr-xr-x',
)

# const.py gets the real install paths; everything else is copied as is.
configure_file(
  input: 'tisu/const.py',
  output: 'const.py',
  configuration: conf,
  install: true,
  install_dir: moduledir,
)

install_data(
  'tisu/__init__.py',
  'tisu/__main__.py',
  'tisu/api.py',
  'tisu/fmt.py',
  'tisu/main.py',
  'tisu/prefs.py',
  'tisu/public_settings.py',
  'tisu/qr.py',
  'tisu/secret_store.py',
  'tisu/widgets.py',
  'tisu/window.py',
  install_dir: moduledir,
)

install_subdir('tisu/pages', install_dir: moduledir, exclude_directories: ['__pycache__'])
