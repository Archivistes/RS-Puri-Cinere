import { copyFile, mkdir } from 'node:fs/promises';

const assets = ['style.css', 'vendor/tabler/tabler.min.css', 'vendor/tabler/LICENSE'];
await mkdir('dist/vendor/tabler', { recursive: true });
for (const asset of assets) {
    await copyFile('public/' + asset, 'dist/' + asset);
}
