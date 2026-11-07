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
// Public site fonts (self-hosted): Fraunces for headings, Lexend for text.
const fonts = [
  ['@fontsource-variable/fraunces/files/fraunces-latin-wght-normal.woff2', 'fraunces-latin-wght-normal.woff2'],
  ['@fontsource-variable/fraunces/LICENSE', 'LICENSE-fraunces.txt'],
  ['@fontsource-variable/lexend/files/lexend-latin-wght-normal.woff2', 'lexend-latin-wght-normal.woff2'],
  ['@fontsource-variable/lexend/LICENSE', 'LICENSE-lexend.txt'],
];
for (const [from, to] of fonts) copyFileSync('node_modules/' + from, '../public/assets/fonts/' + to);
console.log('Admin bundle built.');
