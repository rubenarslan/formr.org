const assets = require('./assets.json');
const fs = require('fs');

/**
     * Returns the JS or CSS structure of an asset defined in assets.json
     * 
     * @param {Array|String} asset String representing asset key or an array of asset keys
     * @param {String} type. Strings 'css' or 'js'
     * @return {Array}
     */
function _asset(asset, type) {
    var res = [];
    if (Array.isArray(asset)) {
        return _asset_array(asset, type);
    }

    if (typeof assets[asset] != 'undefined') {
        var _asset = assets[asset], res = [];
        if (typeof _asset[type] != 'undefined') {
            if (Array.isArray(_asset[type])) {
                res = _asset[type];
            } else {
                res = [_asset[type]];
            }
        }
    }
    return res;
}

/**
 * Returns the JS or CSS structure of an array of assets
 * 
 * @param {Array} asset Array of asset keys
 * @param {String} type. Strings 'css' or 'js'
 * @return {Array}
 */
function _asset_array(asset, type) {
    var res = [];
    for (var a in asset) {
        res = res.concat(_asset(asset[a], type));
    }
    return res;
}

/**
 * Returns the CSS structure of an asset
 *
 * @see _asset
 */
function _css(asset) {
    return _asset(asset, 'css');
}

/**
 * Returns the JS structure of an asset
 *
 * @see _asset
 */
function _js(asset) {
    return _asset(asset, 'js');
}

/**
 * Gets an array of arrays and returns a single array
 *
 * @param {Array}
 * @return {Array}
 */
function _flattern(array) {
    return [].concat.apply([], array);
}

function _asset_path(path) {
    if (!path.startsWith('node_modules/')) {
        return 'webroot/assets/' + path;
    }
    return path;
}

function _delete_file(path) {
    if (fs.existsSync(path)) {
        fs.unlinkSync(path);
    }
}

module.exports = {
    _asset,
    _asset_array,
    _css,
    _js,
    _flattern,
    _asset_path,
    _delete_file
};