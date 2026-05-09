import * as esbuild from 'esbuild'

const watch = process.argv.includes('--watch')

const buildOptions = {
  entryPoints: ['./src/main.js'],
  bundle: true,
  format: 'iife',
  platform: 'browser',
  target: 'es2020',
  outfile: '../../public/administration/js/invoice-api-xhub.js',
  loader: {
    '.html': 'text',
    '.twig': 'text',
    '.scss': 'text',
    '.json': 'json',
  },
  define: {
    'process.env.NODE_ENV': '"production"',
  },
  logLevel: 'info',
}

if (watch) {
  const ctx = await esbuild.context(buildOptions)
  await ctx.watch()
  console.log('Watching for changes...')
} else {
  await esbuild.build(buildOptions)
  console.log('Build complete.')
}
