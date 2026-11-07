// Builds the admin bundle and copies the editor styles and the font.
// Run on your computer: cd editor-src && npm install && npm run build
import { build } from 'esbuild';
import { copyFileSync, mkdirSync } from 'node:fs';

const out = '../public/assets/admin';
mkdirSync(out, { recursive: true });
mkdirSync('../public/assets/fonts', { recursive: true });

await build({
  entryPoints: ['src/admin.js'],
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  outfile: out + '/admin.js',
  legalComments: 'eof',
  logLevel: 'info',
});

copyFileSync('node_modules/quill/dist/quill.snow.css', out + '/quill.snow.css');
copyFileSync(
  'node_modules/@fontsource-variable/source-serif-4/files/source-serif-4-latin-wght-normal.woff2',
  '../public/assets/fonts/source-serif-4-latin-wght-normal.woff2'
);
copyFileSync('node_modules/@fontsource-variable/source-serif-4/LICENSE', '../public/assets/fonts/LICENSE-source-serif-4.txt');
console.log('Admin bundle built.');
