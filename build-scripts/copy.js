const fs = require('fs-extra');
const assets = require('./assets.json');
const helpers = require('./helpers');
const { _asset_path } = helpers;

const copyFiles = async () => {
  // Define paths for copying
  const copies = [
    { src: 'node_modules/webshim/js-webshim/minified/shims/', dest: 'build/js/shims/' },
    { src: 'node_modules/ace-builds/src-min-noconflict/', dest: 'build/js/ace/' },

    { src: 'node_modules/font-awesome/fonts/', dest: 'build/fonts/' },

    { src: 'node_modules/select2/select2.png', dest: 'build/css/select2.png' },
    { src: 'node_modules/select2/select2x2.png', dest: 'build/css/select2x2.png' },
    { src: 'node_modules/select2/select2-spinner.gif', dest: 'build/css/select2-spinner.gif' },

    { src: 'common/fonts/', dest: 'build/fonts/' },
    { src: 'site/img/', dest: 'build/img/' },
    { src: 'admin/img/', dest: 'build/img/' },
  ];

  for (let { src, dest } of copies) {
    src = _asset_path(src);
    dest = _asset_path(dest);

    await fs.copy(src, dest);
    console.log(`Copied from ${src} to ${dest}`);
  }
};

copyFiles();
