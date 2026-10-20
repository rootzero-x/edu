desktop_file = configure_file(
  input: app_id + '.desktop.in',
  output: app_id + '.desktop',
  copy: true,
  install: true,
  install_dir: datadir / 'applications',
)

metainfo_file = configure_file(
  input: app_id + '.metainfo.xml.in',
  output: app_id + '.metainfo.xml',
  copy: true,
  install: true,
  install_dir: datadir / 'metainfo',
)

desktop_utils = find_program('desktop-file-validate', required: false)
if desktop_utils.found()
  test('Validate desktop file', desktop_utils, args: [desktop_file])
endif

appstreamcli = find_program('appstreamcli', required: false)
if appstreamcli.found()
  test('Validate AppStream file', appstreamcli, args: ['validate', '--no-net', '--explain', metainfo_file])
endif

install_data(app_id + '.gschema.xml', install_dir: datadir / 'glib-2.0' / 'schemas')
compile_schemas = find_program('glib-compile-schemas', required: false)
if compile_schemas.found()
  test('Validate schema file', compile_schemas, args: ['--strict', '--dry-run', meson.current_source_dir()])
endif

install_data('style.css', install_dir: pkgdatadir)
install_subdir('icons/ui', install_dir: pkgdatadir / 'icons')
install_data('icons/hicolor/scalable/apps' / app_id + '.svg',
  install_dir: datadir / 'icons' / 'hicolor' / 'scalable' / 'apps')
install_data('icons/hicolor/symbolic/apps' / app_id + '-symbolic.svg',
  install_dir: datadir / 'icons' / 'hicolor' / 'symbolic' / 'apps')
