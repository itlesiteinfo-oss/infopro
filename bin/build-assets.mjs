// Minifies the plugin CSS/JS into .min.* files and generates the RTL stylesheet.
// Dev-only tool (esbuild). Run: node bin/build-assets.mjs
import { transform } from 'esbuild';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'horizon-press-news-bar', 'assets');
const files = [
  ['css/hprnb-bar.css', 'css'],
  ['css/hprnb-bar-rtl.css', 'css'],
  ['css/hprnb-admin.css', 'css'],
  ['css/hprnb-post.css', 'css'],
  ['js/hprnb-bootstrap.js', 'js'],
  ['js/hprnb-bar.js', 'js'],
  ['js/hprnb-admin.js', 'js'],
];

// The RTL stylesheet is a copy of the LTR one: every rule already uses logical
// properties, and the marquee direction is decided at runtime by the script from
// the computed direction of the bar (dir="auto"), not by the site direction.
// WordPress still needs the -rtl file to exist for wp_style_add_data( 'rtl', 'replace' ).
const ltr = await readFile(resolve(root, 'css/hprnb-bar.css'), 'utf8');
const rtl = ltr.replace(/\/\*\s*hprnb:ltr-file\s*\*\//, '/* hprnb:rtl-file (generated from hprnb-bar.css by bin/build-assets.mjs — identical rules, logical properties) */');
await writeFile(resolve(root, 'css/hprnb-bar-rtl.css'), rtl);

const sizes = {};
for (const [file, loader] of files) {
  const src = await readFile(resolve(root, file), 'utf8');
  const out = await transform(src, { loader, minify: true, target: loader === 'js' ? ['es2018'] : undefined, legalComments: 'none' });
  const minPath = resolve(root, file.replace(/\.(css|js)$/, '.min.$1'));
  await writeFile(minPath, out.code);
  sizes[file] = { source: Buffer.byteLength(src), min: Buffer.byteLength(out.code) };
}
console.table(sizes);
