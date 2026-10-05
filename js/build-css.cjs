/**
 * Compiles less/pages.less (the group pages' styles) into a JS module the lazy
 * page chunk imports, so those rules ship with the pages instead of forum.css.
 */
const fs = require('fs');
const path = require('path');
const less = require('less');

const src = path.resolve(__dirname, '../less/pages.less');
const out = path.resolve(__dirname, 'src/forum/generated/pageStyles.js');

less
  .render(fs.readFileSync(src, 'utf8'), { filename: src, compress: true })
  .then(({ css }) => {
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, `export default ${JSON.stringify(css)};\n`);
  })
  .catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
