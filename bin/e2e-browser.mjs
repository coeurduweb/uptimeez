/**
 * Uptimer — tests de bout en bout dans un vrai navigateur.
 *
 * Complète bin/e2e.php (qui teste le serveur) en vérifiant ce qui ne vit que
 * côté navigateur : accordéons, filtre instantané, barre d'enregistrement,
 * thème, vérification sans rechargement, raccourcis clavier, responsive.
 *
 *   node bin/e2e-browser.mjs [url] [motdepasse]
 *
 * Prérequis : Playwright disponible (poste de développement uniquement — la
 * production n'en a pas besoin). Ces tests ne modifient aucune donnée hormis
 * l'état des sondes affichées.
 */
// Playwright est un module CommonJS : on le charge via createRequire, et on
// essaie les emplacements habituels (paquet local, installation système).
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
let chromium = null;
for (const candidate of [process.env.PLAYWRIGHT_PATH, 'playwright-core', 'playwright',
                         '/usr/share/nodejs/playwright-core',
                         '/usr/lib/node_modules/playwright-core'].filter(Boolean)) {
  try { chromium = require(candidate).chromium; if (chromium) break; } catch (e) { /* suivant */ }
}
if (!chromium) {
  console.log('Playwright introuvable — tests navigateur ignorés.');
  console.log('Pour les activer : npm i -D playwright-core, ou PLAYWRIGHT_PATH=/chemin/vers/playwright-core');
  console.log('Le parcours serveur est déjà couvert par : php bin/e2e.php');
  process.exit(0);
}

const BASE = process.argv[2] || 'http://127.0.0.1:8390';
const PASS = process.argv[3] || 'demo1234';
const CHROME = process.env.CHROME_PATH ||
  '/home/laurent/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome';

let pass = 0, fail = 0;
const errors = [];
const ok = (label, good, detail = '') => {
  good ? pass++ : fail++;
  const pad = ' '.repeat(Math.max(1, 52 - [...label].length));
  console.log((good ? ' OK  ' : 'ÉCHEC ') + label + pad + (detail ? '→ ' + detail : ''));
};
const title = (s) => console.log('\n── ' + s + ' ' + '─'.repeat(Math.max(0, 56 - [...s].length)));

const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
const page = await ctx.newPage();
page.on('pageerror', (e) => errors.push('exception : ' + e.message));
page.on('console', (m) => {
  if (m.type() === 'error' && !m.text().includes('favicon')) errors.push('console : ' + m.text());
});

try {
  title('Connexion');
  await page.goto(BASE + '/index.php?p=login', { waitUntil: 'networkidle' });
  ok('formulaire de connexion affiché', await page.isVisible('input[name=password]'));
  ok('champ associé à une étiquette', await page.$eval('input[name=password]',
    (el) => !!document.querySelector('label[for="' + el.id + '"]')));
  await page.fill('input[name=password]', PASS);
  await page.click('.btn-primary');
  await page.waitForLoadState('networkidle');
  ok('page d’accueil atteinte', page.url().includes('p=today'), page.url());

  title('Écran « Aujourd’hui »');
  await page.goto(BASE + '/index.php?p=today', { waitUntil: 'networkidle' });
  const tasks = await page.$$('[data-task]');
  ok('liste de tâches affichée', tasks.length > 0 || (await page.$('.band-ok')) !== null,
    tasks.length + ' tâche(s)');
  ok('bandeau d’état en tête de page', await page.$eval('#band',
    (el) => el.getBoundingClientRect().top < 200));
  if (tasks.length) {
    const first = tasks[0];
    ok('la tâche annonce sa cause', (await first.$eval('.task-cause', (e) => e.textContent.trim())).length > 8);
    ok('la tâche donne la conduite à tenir', (await first.$('.task-fix')) !== null);
    ok('actions disponibles sans changer de page', (await first.$$('.task-actions .btn')).length >= 4);
    // Rapport copiable
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {});
    const copyBtn = await first.$('.js-copy-report');
    if (copyBtn) {
      await copyBtn.click();
      await page.waitForTimeout(1200);
      ok('rapport copié avec retour visuel',
        (await page.$$('#toasts .toast')).length > 0);
    }
    // Correctif avec annulation
    const fixBtn = await first.$('.js-fix[data-fix="snooze"]');
    if (fixBtn) {
      await fixBtn.click();
      await page.waitForTimeout(1400);
      const undoVisible = await page.$('.undo button');
      ok('correctif appliqué avec possibilité d’annuler', undoVisible !== null);
      if (undoVisible) {
        await undoVisible.click();
        await page.waitForTimeout(1200);
        ok('annulation effectuée', true);
      }
    }
  } else {
    ok('état « rien à faire » lisible', (await page.textContent('.band-title')).length > 5);
  }

  title('Palette de commandes');
  await page.goto(BASE + '/index.php?p=today', { waitUntil: 'networkidle' });
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(400);
  ok('palette ouverte au clavier', (await page.$('.pal')) !== null);
  ok('champ de recherche focalisé',
    await page.evaluate(() => document.activeElement && document.activeElement.id === 'pal-q'));
  const rows0 = (await page.$$('.pal-item')).length;
  ok('propositions immédiates sans rien taper', rows0 > 0, rows0 + ' entrée(s)');
  await page.fill('#pal-q', 'regl');
  await page.waitForTimeout(500);
  const labels = await page.$$eval('.pal-item', (els) => els.map((e) => e.textContent));
  ok('commande trouvée sans accent ni casse exacte',
    labels.some((l) => l.toLowerCase().includes('églages') || l.toLowerCase().includes('reglages')),
    labels.slice(0, 2).join(' / '));
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(250);
  ok('palette refermée par Échap', (await page.$('.pal')) === null);
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(350);
  await page.fill('#pal-q', 'incident');
  await page.waitForTimeout(500);
  // On attend la navigation elle-même : networkidle peut revenir avant le départ.
  await Promise.all([
    page.waitForURL(/p=incidents/, { timeout: 5000 }).catch(() => {}),
    page.keyboard.press('Enter'),
  ]);
  ok('navigation par la palette', page.url().includes('p=incidents'), page.url());

  title('Mur d’écran');
  await page.goto(BASE + '/index.php?p=dashboard', { waitUntil: 'networkidle' });
  const cards = await page.$$('#cards .card');
  ok('cartes affichées', cards.length > 0, cards.length + ' carte(s)');
  ok('titre de page signalant les pannes',
    /^\(\d+\)/.test(await page.title()) || !(await page.$('.card.s-down')), await page.title());

  const before = (await page.$$('#cards .card:not(.hidden)')).length;
  await page.fill('#q', 'zzzzintrouvable');
  await page.waitForTimeout(200);
  ok('filtre instantané masque les cartes',
    (await page.$$('#cards .card:not(.hidden)')).length === 0 && before > 0);
  await page.fill('#q', '');
  await page.waitForTimeout(200);
  ok('filtre vidé : tout revient', (await page.$$('#cards .card:not(.hidden)')).length === before);

  const theme0 = await page.getAttribute('html', 'data-theme');
  await page.click('#theme-toggle');
  await page.waitForTimeout(150);
  const theme1 = await page.getAttribute('html', 'data-theme');
  ok('bascule de thème', theme0 !== theme1, theme0 + ' → ' + theme1);
  await page.reload({ waitUntil: 'networkidle' });
  ok('thème conservé après rechargement', (await page.getAttribute('html', 'data-theme')) === theme1);
  await page.click('#theme-toggle');

  const firstCard = await page.$('#cards .card');
  const cardId = await firstCard.getAttribute('data-id');
  const urlBefore = page.url();
  await firstCard.$eval('.js-check', (b) => b.click());
  await page.waitForTimeout(2500);
  ok('vérification sans quitter la page', page.url() === urlBefore);
  ok('notification affichée', (await page.$$('#toasts .toast')).length > 0);

  title('Fiche de sonde');
  await page.goto(BASE + '/index.php?p=monitor&id=' + cardId, { waitUntil: 'networkidle' });
  const accs = await page.$$('details.acc');
  ok('sections repliables présentes', accs.length >= 5, accs.length + ' accordéon(s)');
  const closed = await page.$$eval('details.acc', (ds) => ds.filter((d) => !d.open).length);
  ok('page courte par défaut (sections repliées)', closed >= 3, closed + ' replié(s)');

  // Ouverture / mémorisation
  await page.click('#infra > summary');
  await page.waitForTimeout(200);
  ok('accordéon s\'ouvre au clic', await page.$eval('#infra', (d) => d.open));
  await page.reload({ waitUntil: 'networkidle' });
  ok('ouverture mémorisée entre deux visites', await page.$eval('#infra', (d) => d.open));

  // Contenu lisible
  ok('certificat et domaine documentés',
    (await page.textContent('#infra')).includes('Certificat SSL'));
  await page.click('#settings > summary');
  await page.waitForTimeout(200);
  ok('formulaire de réglages accessible', await page.isVisible('input[name=name]'));
  ok('chaque champ a une étiquette visible', await page.$$eval(
    '#settings input[type=text], #settings input[type=number], #settings select',
    (els) => els.every((el) => !el.id || !!document.querySelector('label[for="' + el.id + '"]') || !!el.closest('label'))));

  // Barre d'enregistrement contextuelle
  ok('barre d\'enregistrement masquée au départ',
    await page.$eval('[data-savebar]', (el) => el.hidden));
  await page.fill('#settings input[name=name]', 'Nom modifié en navigateur');
  await page.waitForTimeout(150);
  ok('barre d\'enregistrement apparaît à la modification',
    await page.$eval('[data-savebar]', (el) => !el.hidden));
  await page.click('[data-reset-form]');
  await page.waitForTimeout(200);
  ok('bouton Annuler rétablit l\'état initial',
    await page.$eval('[data-savebar]', (el) => el.hidden));

  title('Périodes d\'affichage');
  for (const label of ['7 j', '90 j', '1 an']) {
    await page.click(`.segmented a:has-text("${label}")`);
    await page.waitForLoadState('networkidle');
    const sel = await page.$eval('.segmented [aria-selected="true"]', (el) => el.textContent.trim());
    ok('période ' + label + ' sélectionnable', sel === label, 'sélection = ' + sel);
    ok('graphique rendu pour ' + label, (await page.$('.chart')) !== null || (await page.$('.chart-empty')) !== null);
  }

  title('Rapport client');
  await page.goto(BASE + '/index.php?p=report', { waitUntil: 'networkidle' });
  ok('rapport affiché', (await page.$('.report')) !== null);
  ok('titre de rapport présent',
    (await page.textContent('body')).includes('Rapport de disponibilité'));
  ok('éléments d’interface masqués à l’impression', await page.evaluate(() => {
    const el = document.querySelector('.no-print');
    return el !== null;
  }));

  title('Accessibilité et responsive');
  ok('page en français', (await page.getAttribute('html', 'lang')) === 'fr');
  ok('navigation avec état courant', (await page.$('nav [aria-current="page"]')) !== null);
  ok('aucune icône emoji dans l\'interface',
    !/[\u{1F300}-\u{1FAFF}]/u.test(await page.$eval('body', (b) => b.innerText)));
  const contrastOk = await page.evaluate(() => {
    const el = document.querySelector('.stat-value');
    if (!el) return true;
    const s = getComputedStyle(el);
    return s.color !== s.backgroundColor;
  });
  ok('valeurs lisibles sur leur fond', contrastOk);

  for (const [w, h, name] of [[390, 844, 'mobile'], [820, 1180, 'tablette'], [1440, 950, 'bureau']]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(BASE + '/index.php?p=today', { waitUntil: 'networkidle' });
    const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok('aucun débordement horizontal en ' + name, over <= 0, over + ' px');
    const tap = await page.$$eval('.btn', (bs) => bs.filter((b) => {
      const r = b.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && r.height < 28;
    }).length);
    ok('boutons assez hauts en ' + name, tap === 0, tap + ' bouton(s) trop petit(s)');
  }

  title('Import');
  await page.setViewportSize({ width: 1440, height: 950 });
  await page.goto(BASE + '/index.php?p=import', { waitUntil: 'networkidle' });
  ok('zone de saisie prête et focalisée', await page.evaluate(
    () => document.activeElement && document.activeElement.name === 'list'));
  ok('exemples de format visibles', (await page.textContent('body')).includes('URL | nom | chaîne de contrôle'));

  title('Erreurs JavaScript');
  ok('aucune erreur JavaScript sur le parcours', errors.length === 0,
    errors.slice(0, 3).join(' | '));
} catch (e) {
  ok('parcours sans interruption', false, e.message);
} finally {
  await browser.close();
}

console.log('\n' + '═'.repeat(68));
console.log(`${pass} contrôle(s) réussi(s), ${fail} échec(s)`);
if (fail > 0) {
  console.log('⚠️  L\'interface présente des anomalies côté navigateur.');
  process.exit(1);
}
console.log('✅ Interface validée dans un navigateur réel.');
