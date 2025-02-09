const helpers = require('./helpers.js');
const fs = require('fs');
const path = require('path');
const { _flattern, _js, _asset_path, _delete_file } = helpers;
const assets = require('./assets.json');
const concat = require('concat');
const { ESLint } = require("eslint");
const Terser = require('terser');

const commonAssets = assets.config.common;
const siteAssets = assets.config.site;
const adminAssets = assets.config.admin;

const jsFiles = {
    'build/js/bootstrap-material-design.js': _flattern([_js('bootstrap-material-design')]),
    'build/js/formr.js': _flattern([_js(commonAssets), _js(siteAssets)]),
    'build/js/formr-material.js': _flattern([_js(commonAssets), _js(siteAssets), 'build/js/bootstrap-material-design.js']),
    'build/js/formr-admin.js': _flattern([_js(commonAssets), _js(adminAssets)]),
}

async function processJS() {
    // 1. Lint the JS files
    const eslint = new ESLint({ 
        fix: false,
        overrideConfigFile: "./.eslintrc.js"
    });

    const results = await eslint.lintFiles([
        'common/js/webshim.js',
        'common/js/main.js',
        'common/js/survey.js',
        'common/js/run.js',
        'common/js/run_settings.js',
        'common/js/run_users.js',
        'common/js/cookieconsent.js',
        'site/js/main.js',
        'admin/js/main.js',
        'admin/js/admin.js'
    ].map(_asset_path));

    // Format and display the results
    const formatter = await eslint.loadFormatter("stylish");
    const resultText = formatter.format(results);

    console.log(resultText);

    // Optionally, if you want to exit with a non-zero code if errors are found:
    if (results.some(result => result.errorCount > 0)) {
        process.exit(1);
    }

    // 2. Concatenate the JS files
    concatJsFiles();

    // 3. Uglify the JS files
    uglifyJsFiles();
}

const concatJsFiles = () => {
    const separator = ';\n';

    for (let outputFile in jsFiles) {
        const inputFiles = jsFiles[outputFile].map(_asset_path);
        let concatenatedContent = '';
        outputFile = _asset_path(outputFile);

        inputFiles.forEach((file, index) => {
            const fileContent = fs.readFileSync(path.resolve(file), 'utf-8');
            concatenatedContent += fileContent;

            // Add separator after every file except the last one
            if (index < inputFiles.length - 1) {
                concatenatedContent += separator;
            }
        });

        // Write the concatenated content to the output file
        fs.writeFileSync(outputFile, concatenatedContent, 'utf-8');
        console.log(`${outputFile} created successfully.`);
    }
};

const uglifyJsFiles = async () => {
    const banner = `/* formr ${new Date().toISOString()} */\n`;

    const options = {
        warnings: true,
        compress: {
            drop_console: true,
        },
        output: {
            comments: false
        },
        mangle: true
    };

    for (let file in jsFiles) {
        file = _asset_path(file);
        
        
        const minifiedFile = file.replace('.js', '.min.js');
        const code = fs.readFileSync(file, 'utf-8');
        const result = await Terser.minify(code, options);
        console.log(`Minifying ${file}`);

        if (result.error) {
            console.log(result.error);
            throw new Error(`Error minifying ${file}:`, result.error);
        }

        // Ensure the result code is not undefined
        if (!result.code) {
            throw new Error('Minification produced no output.');
        }

        fs.writeFileSync(minifiedFile, banner + result.code, 'utf-8');
        console.log(`${minifiedFile} created successfully.`);
        _delete_file(file);
    }
}


processJS();
