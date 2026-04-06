# Foundation: Zero Mass

Foundation: Zero Mass is Inkfire's WordPress media optimization plugin for safe background image compression, modern format generation, and accessibility-aware media cleanup.

## What it does

- Queues image optimization jobs instead of forcing every upload to process inline
- Compresses JPEG, PNG, GIF, and WebP uploads with safer write/compare logic
- Generates AVIF and WebP alternatives when the generated files are actually smaller
- Regenerates WordPress attachment metadata after successful rewrites
- Creates optional backup copies for restore workflows
- Generates concise alt text from filenames and parent context
- Provides a React-powered admin dashboard for settings and queue visibility

## Development

The admin settings app uses a very small React shell with Tailwind-generated CSS.

### Build admin CSS

```bash
npm install
npm run build:admin-css
```

### Smoke checks

```bash
php -l zero-mass-media.php
node --check assets/admin-app.js
```

## Update delivery

The plugin includes the same GitHub + Plugin Update Checker approach used by other Foundation plugins. Once the GitHub repository is live and tagged, WordPress can discover updates through the configured GitHub repo.
