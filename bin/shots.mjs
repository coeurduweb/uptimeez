/**
 * UptimeEZ : captures d'écran pour la documentation.
 *
 * Produit les images du README (deux thèmes, plusieurs langues, mobile) et la
 * séquence d'images qui sert à monter l'animation de démonstration.
 *
 *   php -S 127.0.0.1:8390 -t .            # dans un autre terminal
 *   PLAYWRIGHT_PATH=/usr/share/nodejs/playwright-core node bin/shots.mjs
 *
 * Les fichiers sont écrits dans docs/img/.
 */
import { createRequire } from 'module';
import { mkdirSync } from 'fs';
const require = createRequire(import.meta.url);
// Mêmes emplacements que bin/e2e-browser.mjs : paquet local, puis système.
let chromium = null;
for (const candidate of [process.env.PLAYWRIGHT_PATH, 'playwright-core', 'playwright',
                         '/usr/share/nodejs/playwright-core',
                         '/usr/lib/node_modules/playwright-core'].filter(Boolean)) {
  try { chromium = require(candidate).chromium; if (chromium) break; } catch (e) { /* suivant */ }
}
if (!chromium) {
  console.log('Playwright introuvable : aucune capture produite.');
  console.log('Pour les activer : npm i -D playwright-core, ou PLAYWRIGHT_PATH=/chemin/vers/playwright-core');
  process.exit(0);
}

const BASE = process.env.BASE || 'http://127.0.0.1:8390';
const PASS = process.env.PASS || 'demo1234';
const CHROME = process.env.CHROME_PATH ||
  '/home/laurent/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome';
const OUT = new URL('../docs/img/', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

/** Nouvelle page connectée, à la taille et dans le thème demandés. */
async function session({ width = 1440, height = 950, theme = 'light', scale = 2 } = {}) {
  const ctx = await browser.newContext({
    viewport: { width, height }, deviceScaleFactor: scale,
    colorScheme: theme === 'dark' ? 'dark' : 'light',
  });
  const page = await ctx.newPage();
  await page.goto(BASE + '/index.php?p=login');
  await page.fill('input[type=password]', PASS);
  await page.press('input[type=password]', 'Enter');
  await page.waitForLoadState('networkidle');
  await page.addInitScript(t => {
    try { localStorage.setItem('uptimeez-theme', t); } catch (e) {}
  }, theme);
  await ctx.addCookies([{ name: 'uptimeez-theme', value: theme, url: BASE }]);
  return { ctx, page };
}

async function shot(page, url, file, { full = false, wait = 450, before = null } = {}) {
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.evaluate(t => { document.documentElement.dataset.theme = t; },
                      page.__theme || 'light');
  if (before) await before(page);
  await page.waitForTimeout(wait);
  await page.screenshot({ path: OUT + file, fullPage: full });
  console.log('  ' + file);
}

// =========================================================================
// Écrans principaux, thème clair
// =========================================================================
console.log('Thème clair :');
{
  const { ctx, page } = await session();
  page.__theme = 'light';
  await shot(page, '/index.php?p=today&lang=fr&ui=simple', 'today.png');
  await shot(page, '/index.php?p=today&lang=fr&ui=simple', 'today-full.png', { full: true });
  await shot(page, '/index.php?p=dashboard&lang=fr', 'wall.png');
  await shot(page, '/index.php?p=monitors&lang=fr', 'monitors.png');
  await shot(page, '/index.php?p=incidents&lang=fr', 'incidents.png');
  await shot(page, '/index.php?p=settings&lang=fr&ui=expert', 'settings.png');
  await shot(page, '/index.php?p=import&lang=fr', 'import.png');

  // Vitesse ressentie : la fiche de la page la plus lourde, bloc déplié.
  const slowId = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api.php?action=search&q=airbnb',
                          { headers: { 'X-Requested-With': 'fetch' } });
    const j = await r.json();
    return (j.results && j.results[0]) ? j.results[0].id : 0;
  }, BASE);
  if (slowId) {
    await shot(page, `/index.php?p=monitor&id=${slowId}&lang=fr&ui=expert`, 'vitals.png', {
      before: async p => {
        await p.evaluate(() => {
          const d = document.getElementById('speed');
          if (!d) return;
          d.open = true;
          d.scrollIntoView({ block: 'start' });
          // L'en-tête est fixe : sans ce décalage, il recouvre le titre du bloc.
          const h = document.querySelector('header');
          window.scrollBy(0, -((h ? h.getBoundingClientRect().height : 60) + 14));
        });
        await p.waitForTimeout(300);
      },
    });
  }

  // Mode agence : la liste des clients, premier bloc ouvert pour montrer le
  // rattachement des sites et le lien à envoyer.
  // On ouvre le bloc du client qui a le plus de sites : une capture avec une
  // seule ligne ne montre pas ce que fait l'écran.
  const richest = async p => p.evaluate(() => {
    let best = null, bestN = -1;
    for (const det of document.querySelectorAll('table.tbl tbody tr details.acc')) {
      const owner = det.closest('tr')?.previousElementSibling?.textContent || '';
      if (owner.includes('accès fermé')) continue;
      const n = parseInt((det.querySelector('.acc-note')?.textContent || '').replace(/\D+/g, ''), 10) || 1;
      if (n > bestN) { bestN = n; best = det.id; }
    }
    return best;
  });
  await shot(page, '/index.php?p=clients&lang=fr&ui=expert', 'clients.png', {
    before: async p => {
      const id = await richest(p);
      if (id) await p.evaluate(i => { document.getElementById(i).open = true; }, id);
      await p.waitForTimeout(200);
    },
  });
  // L'espace client tel que le client le voit : le lien se lit sur l'écran
  // précédent, personne n'a besoin de connaître le jeton pour produire l'image.
  const cliTok = await page.evaluate(() => {
    let best = '', bestN = -1;
    for (const det of document.querySelectorAll('table.tbl tbody tr details.acc')) {
      const owner = det.closest('tr')?.previousElementSibling?.textContent || '';
      if (owner.includes('accès fermé')) continue;
      const n = parseInt((det.querySelector('.acc-note')?.textContent || '').replace(/\D+/g, ''), 10) || 1;
      const m = (det.querySelector('input[readonly]')?.value || '').match(/k=([0-9a-f]{32})/);
      if (m && n > bestN) { bestN = n; best = m[1]; }
    }
    return best;
  });
  if (cliTok) await shot(page, `/index.php?p=client&k=${cliTok}&lang=fr`, 'client-space.png');
  await shot(page, '/index.php?p=report&lang=fr', 'report.png', { full: true });

  // Fiche d'une sonde en panne de mise en page, accordéon ouvert.
  const brokenId = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api.php?action=summary', { headers: { 'X-Requested-With': 'fetch' } });
    const j = await r.json();
    const bad = (j.monitors || []).find(m => m.css === 'broken') || (j.monitors || []).find(m => m.status === 'down');
    return bad ? bad.id : 0;
  }, BASE);
  if (brokenId) {
    await shot(page, `/index.php?p=monitor&id=${brokenId}&lang=fr&ui=expert`, 'monitor.png', { full: true });
    await shot(page, `/index.php?p=monitor&id=${brokenId}&lang=fr&ui=expert`, 'css-broken.png', {
      before: async p => {
        const acc = await p.$('#res');
        if (acc) { await acc.evaluate(el => { el.open = true; el.scrollIntoView({ block: 'center' }); }); }
      },
    });
  }

  // Palette de commandes ouverte.
  await shot(page, '/index.php?p=today&lang=fr', 'palette.png', {
    before: async p => { await p.keyboard.press('Control+k'); await p.waitForTimeout(250);
                         await p.fill('#pal-q', 'camp'); await p.waitForTimeout(400); },
  });

  // Aperçu avant création à l'import.
  await shot(page, '/index.php?p=import&lang=fr', 'import-preview.png', {
    full: true,
    before: async p => {
      await p.fill('#list', 'exemple-client.fr\nboutique-dupont.fr | Boutique Dupont\napi.exemple.fr/health ; API interne ; "status":"ok"');
      const btn = await p.$('button[value=preview], button[name=action][value=preview]');
      if (btn) { await btn.click(); await p.waitForLoadState('networkidle'); }
      else { await p.evaluate(() => {
        const f = document.querySelector('#list').closest('form');
        const a = f.querySelector('[name=action]'); if (a) a.value = 'preview'; f.submit();
      }); await p.waitForLoadState('networkidle'); }
    },
  });

  // Aide contextuelle ouverte.
  await shot(page, '/index.php?p=today&lang=fr', 'hint.png', {
    before: async p => { const h = await p.$('[data-hint]'); if (h) await h.click(); },
  });
  await ctx.close();
}

// =========================================================================
// Thème sombre, mode complet
// =========================================================================
console.log('Thème sombre :');
{
  const { ctx, page } = await session({ theme: 'dark' });
  page.__theme = 'dark';
  await shot(page, '/index.php?p=today&lang=fr&ui=expert', 'today-dark.png');
  await shot(page, '/index.php?p=dashboard&lang=fr', 'wall-dark.png');
  await ctx.close();
}

// =========================================================================
// Langues : anglais par défaut, arabe de droite à gauche
// =========================================================================
console.log('Langues :');
{
  const { ctx, page } = await session();
  page.__theme = 'light';
  await shot(page, '/index.php?p=today&lang=en&ui=simple', 'today-en.png');
  await shot(page, '/index.php?p=today&lang=ar&ui=simple', 'today-ar.png');
  await shot(page, '/index.php?p=today&lang=zh&ui=simple', 'today-zh.png');
  await ctx.close();
}

// =========================================================================
// Mobile
// =========================================================================
console.log('Mobile :');
{
  const { ctx, page } = await session({ width: 390, height: 844, scale: 3 });
  page.__theme = 'light';
  await shot(page, '/index.php?p=today&lang=fr&ui=simple', 'mobile-today.png');
  await shot(page, '/index.php?p=dashboard&lang=fr', 'mobile-wall.png');
  await ctx.close();
}

// =========================================================================
// Simple contre complet, côte à côte
// =========================================================================
console.log('Niveau de détail :');
{
  const { ctx, page } = await session({ width: 1200, height: 1400 });
  page.__theme = 'light';
  const id = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api.php?action=summary', { headers: { 'X-Requested-With': 'fetch' } });
    const j = await r.json();
    const bad = (j.monitors || []).find(m => m.status === 'down');
    return bad ? bad.id : 1;
  }, BASE);
  await shot(page, `/index.php?p=monitor&id=${id}&lang=fr&ui=simple`, 'detail-simple.png', { full: true });
  await shot(page, `/index.php?p=monitor&id=${id}&lang=fr&ui=expert`, 'detail-expert.png', { full: true });
  await ctx.close();
}

// =========================================================================
// Séquence pour l'animation : on répare une panne sans quitter la page
// =========================================================================
console.log('Séquence animée :');
{
  const { ctx, page } = await session({ width: 1280, height: 760, scale: 1 });
  page.__theme = 'light';
  mkdirSync(OUT + 'seq/', { recursive: true });
  const frames = [];
  const grab = async (n) => {
    await page.screenshot({ path: OUT + `seq/f${String(n).padStart(2, '0')}.png` });
    frames.push(n);
  };
  // 1. l'écran d'accueil : la liste de ce qu'il y a à faire
  await page.goto(BASE + '/index.php?p=today&lang=fr&ui=simple', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await grab(1); await grab(2);
  // 2. on ouvre une aide contextuelle
  const h = await page.$('[data-hint]');
  if (h) { await h.click(); await page.waitForTimeout(350); await grab(3); await grab(4);
           await page.keyboard.press('Escape'); }
  // 3. la palette de commandes
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(300); await grab(5);
  await page.fill('#pal-q', 'camping');
  await page.waitForTimeout(500); await grab(6); await grab(7);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(200);
  // 4. on applique un correctif depuis la carte, sans changer de page.
  // Les actions secondaires vivent derrière le bouton « ··· » : on l'ouvre,
  // ce qui fait aussi partie de ce que la démonstration doit montrer.
  const more = await page.$('.hero-task .act-more > summary');
  if (more) { await more.click(); await page.waitForTimeout(300); }
  const fix = await page.$('.js-fix[data-fix=relearn]');
  if (fix) {
    await fix.scrollIntoViewIfNeeded();
    await page.waitForTimeout(250); await grab(8);
    await fix.click();
    await page.waitForTimeout(900); await grab(9); await grab(10);
  } else {
    await grab(8); await grab(9); await grab(10);
  }
  // 5. le mur d'écran
  await page.goto(BASE + '/index.php?p=dashboard&lang=fr', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600); await grab(11); await grab(12);
  // 6. la même chose en anglais
  await page.goto(BASE + '/index.php?p=today&lang=en&ui=simple', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600); await grab(13); await grab(14);
  await page.goto(BASE + '/index.php?p=today&lang=fr&ui=simple', { waitUntil: 'networkidle' });
  console.log('  ' + frames.length + ' image(s) dans seq/');
  await ctx.close();
}

await browser.close();
console.log('\nCaptures écrites dans docs/img/');
// L'animation du README se monte depuis la séquence. La commande vit ici pour
// être reproductible, plutôt que dans un historique de terminal.
console.log("\nPour remonter l'animation du README (ffmpeg requis) :");
console.log("  ffmpeg -y -framerate 1.1 -pattern_type glob -i 'docs/img/seq/*.png' \\");
console.log('    -vf "scale=1100:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=128[p];'
          + '[b][p]paletteuse=dither=bayer:bayer_scale=3" -loop 0 docs/img/tour.gif');
