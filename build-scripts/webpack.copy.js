const fs = require('fs');
const path = require('path');
const { promisify } = require('util');

// Promisify fs functions
const copyFile = promisify(fs.copyFile);
const mkdir = promisify(fs.mkdir);
const stat = promisify(fs.stat);
const readdir = promisify(fs.readdir);

class FormrCopyPlugin {
  constructor(options) {
    // Options should specify `from` and `to` directories
    this.options = options || {};
  }

  async copyFiles(src, dest) {
    try {
      const files = await readdir(src);
      for (const file of files) {
        const srcPath = path.join(src, file);
        const destPath = path.join(dest, file);
        const fileStat = await stat(srcPath);

        if (fileStat.isDirectory()) {
          await mkdir(destPath, { recursive: true });
          await this.copyFiles(srcPath, destPath); // Recursive copy for directories
        } else {
          await copyFile(srcPath, destPath);
          console.log(`Copied: ${srcPath} -> ${destPath}`);
        }
      }
    } catch (err) {
      console.error(`Error copying files: ${err.message}`);
    }
  }

  apply(compiler) {
    compiler.hooks.afterEmit.tapPromise('FormrCopyPlugin', async (compilation) => {
      const { from, to } = this.options;

      if (!from || !to) {
        console.error('FormrCopyPlugin: `from` and `to` options are required.');
        return;
      }

      const src = path.resolve(from);
      const dest = path.resolve(to);

      try {
        await mkdir(dest, { recursive: true }); // Ensure destination directory exists
        await this.copyFiles(src, dest); // Copy files
        console.log(`All files copied from ${src} to ${dest}`);
      } catch (err) {
        console.error(`FormrCopyPlugin Error: ${err.message}`);
      }
    });
  }
}

module.exports = FormrCopyPlugin;
