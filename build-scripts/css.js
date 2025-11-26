const helpers = require('./helpers.js');
const CSSLint = require('csslint').CSSLint;
const fs = require('fs');
const { _flattern, _css, _asset_path, _delete_file } = helpers;
const assets = require('./assets.json');
const concat = require('concat');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');

const commonAssets = assets.config.common;

const cssFilesMap = {
    'build/css/bootstrap-material-design.css': _flattern([_css('bootstrap-material-design')]),
    'build/css/formr.css': _flattern([_css(commonAssets), ['site/css/style.css', 'common/css/custom_item_classes.css']]),
    'build/css/formr-material.css': _flattern([_css(commonAssets), ['site/css/style.css', 'build/css/bootstrap-material-design.css', 'common/css/custom_item_classes.css']]),
    'build/css/formr-admin.css': _flattern([_css(commonAssets), ['admin/css/AdminLTE.css', 'admin/css/style.css']]),
}

function lintCSS() {

    cssFiles = _flattern([_css('site:custom'), _css('site'), _css('admin')]);

    const options = {
        "adjoining-classes": false,
        "overqualified-elements": false,
        "qualified-headings": false,
        "unique-headings": false,
        "important": false,
        "duplicate-background-images": false,
        "box-model": false,
        "box-sizing": false,
        "floats": false,
        "font-sizes": false,
        "vendor-prefix": false,
        "compatible-vendor-prefixes": false,
        "fallback-colors": false,
        "gradients": false,
        "zero-units": false,
        "ids": false
    };

    cssFiles.forEach(file => {
        file = _asset_path(file);
        console.log(`Linting ${file}`);
        const result = CSSLint.verify(fs.readFileSync(file, 'utf8'), options);
        result.messages.forEach(message => {
            console.log(`${message.type.toUpperCase()} [${file}]: ${message.message} (line ${message.line}, col ${message.col})`);
        });

        if (result.messages.length >= 1) {
            throw new Error(`Linting errors found in ${file}`);
        }
    });

}

async function processCSS() {
    for (let file in cssFilesMap) {
        try {
            let files = cssFilesMap[file].map(_asset_path);
            file = _asset_path(file);
            await concat(files, file);
            console.log(`Files successfully concatenated into ${file}`);
        } catch (error) {
            throw new Error(`Error concatenating files: ${error}`);
        }
    }

    prefexAndMinify();
}

function prefexAndMinify() {
    const filesToProcess = Object.keys(cssFilesMap).map(file => ({
        src: _asset_path(file),
        dest: _asset_path(file).replace('.css', '.min.css')
    }));

    const browsersList = [
        "android 2.3",
        "android >= 4",
        "chrome >= 20",
        "ff > 25",
        "ie >= 8",
        "ios >= 6",
        "opera >= 12",
        "safari >= 6",
    ];

    filesToProcess.forEach(({ src, dest }) => {
        fs.readFile(src, async (err, css) => {
            if (err) {
                console.error(`Error reading file ${src}:`, err);
                return;
            }

            // Banner to be added to the minified CSS file
            const banner = `/* formr ${new Date().toISOString()} */\n`;

            //  Process CSS with PostCSS, Autoprefixer, and cssnano
            const result = await postcss([
                autoprefixer({ overrideBrowserslist: browsersList }),
                cssnano()
            ]).process(css, { from: src, to: dest });

            fs.writeFile(dest, banner + result.css, (error) => {
                if (error) {
                    console.error(error);
                    throw new Error(`Error writing minified CSS file ${dest}`);
                } else {
                    console.log(`Processed and minified CSS written to ${dest}`);
                    _delete_file(src);
                }
            });

        });
    });
}


lintCSS();
processCSS();
