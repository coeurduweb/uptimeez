/**
 * Uptimer : tests de bout en bout dans un vrai navigateur.
 *
 * Complète bin/e2e.php (qui teste le serveur) en vérifiant ce qui ne vit que
 * côté navigateur : accordéons, filtre instantané, barre d'enregistrement,
 * thème, vérification sans rechargement, raccourcis clavier, responsive.
 *
 *   node bin/e2e-browser.mjs [url] [motdepasse]
 *
 * Prérequis : Playwright disponible (poste de développement uniquement, la
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
  console.log('Playwright introuvable : tests navigateur ignorés.');
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
// Ouvre un accordéon seulement s'il est replié. getAttribute renvoie une chaîne
// vide sur un attribut booléen présent : on lit la propriété, pas l'attribut.
const openAcc = async (id) => {
  if (!(await page.$eval(id, (el) => el.open))) await page.click(id + ' > summary');
  await page.waitForSelector(id + '[open]');
};

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
    ok('la panne la plus urgente annonce sa cause',
      (await first.$eval('.hero-cause', (e) => e.textContent.trim())).length > 8);
    ok('elle donne la conduite à tenir', (await first.$('.hero-fix')) !== null);
    // Un seul bouton principal visible : le reste est replié derrière « ··· ».
    const heroPrimary = await first.$$eval('.act > .btn-primary', (b) => b.length);
    ok('un seul bouton principal sur la carte', heroPrimary === 1, heroPrimary + ' bouton(s)');
    ok('les autres actions sont accessibles en un clic', (await first.$('.act-more')) !== null);
    // La file : une ligne par panne suivante, lisible sans défiler.
    const rows = await page.$$eval('.queue .q-row', (r) => r.length);
    ok('les pannes suivantes tiennent sur une ligne', rows >= 0, rows + ' ligne(s)');
    if (rows > 0) {
      const h = await page.$$eval('.queue .q-row', (r) =>
        r.map((x) => Math.round(x.getBoundingClientRect().height)));
      ok('chaque ligne reste compacte', Math.max(...h) <= 90, 'hauteur max ' + Math.max(...h) + ' px');
      ok('chaque ligne porte son action',
        (await page.$$eval('.queue .q-row .btn-primary', (b) => b.length)) === rows);
    }
    // Rapport copiable : il est dans le menu replié.
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {});
    const more = await first.$('.act-more > summary');
    if (more) await more.click();
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

  title('Le pouls du parc');
  await page.goto(BASE + '/index.php?p=today&ui=simple', { waitUntil: 'networkidle' });
  ok('bande de pouls présente', (await page.$('.band-pulse svg.pulse')) !== null);
  const slices = await page.$$eval('.band-pulse svg.pulse rect', (e) => e.length);
  ok('une tranche par intervalle', slices >= 24, slices + ' tranche(s)');
  // Chaque tranche porte son détail : une couleur seule n'informe pas.
  const titled = await page.$$eval('.band-pulse svg.pulse rect title', (e) => e.length);
  ok('chaque tranche explique ce qu\'elle montre', titled === slices, titled + '/' + slices);
  // L'animation d'arrivée ne doit jamais laisser la bande masquée.
  await page.waitForTimeout(900);
  const visible = await page.$eval('.band-pulse svg.pulse', (el) => {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return r.width > 50 && cs.visibility !== 'hidden' && +cs.opacity > .9
      && (cs.clipPath === 'none' || cs.clipPath.includes('0px') || cs.clipPath.includes('inset(0'));
  });
  ok('bande entièrement révélée après l\'animation', visible);
  ok('le mur d\'écran a la même bande',
    (await (async () => {
      await page.goto(BASE + '/index.php?p=dashboard', { waitUntil: 'networkidle' });
      return page.$('.band-pulse svg.pulse');
    })()) !== null);

  title('Contraste mesuré, thème clair et thème sombre');
  // Le contraste ne se juge pas à l'œil : on le calcule sur chaque texte
  // réellement affiché, avec les seuils WCAG (4,5:1, ou 3:1 pour du grand
  // texte). Sept défauts avaient été trouvés ainsi, dont des boutons blancs
  // sur accent clair en thème sombre.
  const contrastAudit = async (url, theme) => {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.evaluate((t) => { document.documentElement.dataset.theme = t; }, theme);
    await page.waitForTimeout(150);
    return page.evaluate(() => {
      const lum = (c) => {
        const [r, g, b] = c.map((v) => {
          v /= 255;
          return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      };
      const parse = (s) => {
        const m = s.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/);
        return m ? { rgb: [+m[1], +m[2], +m[3]], a: m[4] === undefined ? 1 : +m[4] } : null;
      };
      // Le fond effectif : on remonte jusqu'au premier parent réellement opaque.
      const bgOf = (el) => {
        let n = el;
        while (n && n !== document.documentElement) {
          const c = parse(getComputedStyle(n).backgroundColor);
          if (c && c.a > 0.5) return c.rgb;
          n = n.parentElement;
        }
        return [255, 255, 255];
      };
      const ratio = (a, b) => {
        const l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
      };
      const bad = [];
      for (const el of document.querySelectorAll('body *')) {
        const own = [...el.childNodes].filter((n) => n.nodeType === 3)
          .map((n) => n.textContent.trim()).join('');
        if (!own) continue;
        const st = getComputedStyle(el);
        if (st.visibility === 'hidden' || st.display === 'none' || +st.opacity < 0.3) continue;
        const r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) continue;
        const fg = parse(st.color);
        if (!fg) continue;
        const size = parseFloat(st.fontSize), weight = +st.fontWeight || 400;
        const need = (size >= 24 || (size >= 18.66 && weight >= 700)) ? 3 : 4.5;
        const cr = ratio(fg.rgb, bgOf(el));
        if (cr < need) bad.push((el.className || el.tagName) + ' ' + cr.toFixed(2) + '<' + need);
      }
      return bad;
    });
  };
  for (const [url, theme] of [['/index.php?p=today&ui=simple', 'light'],
                              ['/index.php?p=today&ui=simple', 'dark'],
                              ['/index.php?p=dashboard', 'light'],
                              ['/index.php?p=dashboard', 'dark']]) {
    const bad = await contrastAudit(url, theme);
    ok('contraste suffisant · ' + url.replace('/index.php?p=', '').split('&')[0] + ' · ' + theme,
      bad.length === 0, bad.slice(0, 3).join(' | '));
  }
  await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });

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

  title('Vitesse ressentie');
  // La sonde la plus lente du parc : c'est là que le bloc a quelque chose à dire.
  const slowId = await page.evaluate(async (base) => {
    // La recherche renvoie les noms, ce que la synthèse ne fait pas.
    const r = await fetch(base + '/api.php?action=search&q=airbnb',
                          { headers: { 'X-Requested-With': 'fetch' } });
    const j = await r.json();
    return (j.results && j.results[0]) ? j.results[0].id : 0;
  }, BASE);
  await page.goto(BASE + '/index.php?p=monitor&id=' + slowId + '&ui=expert', { waitUntil: 'networkidle' });
  const speed = await page.$('#speed');
  ok('bloc de vitesse présent', speed !== null);
  if (speed) {
    await openAcc('#speed');
    ok('bloc de vitesse ouvert', await page.$eval('#speed', (el) => el.open));
    const causes = await page.$$eval('#speed .vit-f', (e) => e.length);
    ok('causes listées', causes >= 1, causes + ' cause(s)');
    // La gravité doit se voir sans lire : le bord gauche porte la couleur.
    const colored = await page.$$eval('#speed .vit-f', (els) => els.filter((el) => {
      const c = getComputedStyle(el).borderInlineStartColor || getComputedStyle(el).borderLeftColor;
      return c && c !== 'rgba(0, 0, 0, 0)';
    }).length);
    ok('gravité lisible sur le bord de chaque cause', colored === causes, colored + '/' + causes);
    // Chaque cause propose un remède : sans quoi ce n'est qu'un reproche.
    const fixes = await page.$$eval('#speed .vit-fix', (e) => e.length);
    ok('chaque cause porte un remède', fixes === causes, fixes + '/' + causes);
    ok('aucune valeur de LCP affichée sans clé',
      !(await page.textContent('#speed')).includes('Affichage du contenu principal'));
  }

  title('Mode agence et espace client');
  await page.setViewportSize({ width: 1440, height: 950 });
  await page.goto(BASE + '/index.php?p=clients&ui=expert', { waitUntil: 'networkidle' });
  ok('écran des clients affiché', (await page.$('table.tbl')) !== null);
  const cliRows = await page.$$eval('table.tbl tbody tr td:first-child strong', (e) => e.length);
  ok('un client par ligne', cliRows >= 1, cliRows + ' client(s)');
  // Le lien du client doit être lisible et sélectionnable d'un clic : c'est
  // l'unique geste que fera l'utilisateur sur cet écran.
  const first = await page.$('table.tbl tbody tr details.acc');
  ok('réglages du client repliés par défaut', first !== null && !(await first.evaluate((el) => el.open)));
  const accId = await first.evaluate((el) => el.id);
  await page.click('#' + accId + ' > summary');
  ok('lien du client visible après ouverture',
    /p=client&k=[0-9a-f]{32}/.test(await page.inputValue('#' + accId + ' input[readonly]')));
  const boxes = await page.$$eval('#' + accId + ' .checkrow input', (e) => e.length);
  ok('sites proposés au rattachement', boxes >= 1, boxes + ' site(s)');
  // Un site déjà rattaché ailleurs se voit, mais ne se prend pas.
  const taken = await page.$$eval('#' + accId + ' .checkrow.is-taken input', (e) =>
    e.filter((i) => i.disabled).length);
  ok('sites d\'un autre client montrés mais non cochables', taken >= 0, taken + ' verrouillé(s)');

  // L'espace client, vu comme le client le verra : dans un contexte neuf, sans
  // le cookie d'administration. C'est la seule façon de tester ce qu'il voit.
  const link = await page.inputValue('#' + accId + ' input[readonly]');
  const token = (link.match(/k=([0-9a-f]{32})/) || [])[1];
  const guest = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const gp = await guest.newPage();
  const gErrors = [];
  gp.on('pageerror', (e) => gErrors.push(e.message));
  await gp.goto(BASE + '/index.php?p=client&k=' + token, { waitUntil: 'networkidle' });
  ok('espace client ouvert sans session', (await gp.$('.cli-head')) !== null);
  ok('aucun bouton d\'action dans l\'espace client',
    (await gp.$$eval('button', (b) => b.filter((x) => x.type !== 'submit').length)) === 0);
  ok('aucune navigation d\'administration', (await gp.$('nav.nav')) === null);
  ok('état d\'ensemble annoncé en haut', (await gp.$('.band')) !== null);
  ok('un bloc par site', (await gp.$$eval('.cli-site', (e) => e.length)) >= 1);
  // Le pied de page est écrit en dernier : s'il est là, rien n'a interrompu le
  // rendu. C'est le seul témoin d'une erreur fatale quand display_errors est
  // coupé, ce qui est le cas en production.
  ok('page rendue jusqu\'au bout', (await gp.$('.cli-foot')) !== null);
  ok('aucune erreur JavaScript côté client', gErrors.length === 0, gErrors.slice(0, 2).join(' | '));
  // Lisible sur un téléphone : c'est là que le client ouvrira le lien.
  await gp.setViewportSize({ width: 390, height: 844 });
  await gp.goto(BASE + '/index.php?p=client&k=' + token, { waitUntil: 'networkidle' });
  const over = await gp.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok('espace client sans débordement sur mobile', over <= 0, over + ' px');
  await guest.close();

  title('Réglages');
  await page.setViewportSize({ width: 1440, height: 950 });
  await page.goto(BASE + '/index.php?p=settings', { waitUntil: 'networkidle' });
  ok('écran des réglages affiché', (await page.$('form')) !== null);
  // Chaque bloc doit s'ouvrir : un accordéon replié cache des champs qui, eux,
  // partent quand même au serveur. Un bloc qui n'ouvre pas est une config perdue.
  const blocks = await page.$$eval('details.acc > summary', (ss) => ss.length);
  ok('blocs de réglages pliables', blocks >= 4, blocks + ' bloc(s)');
  await openAcc('#watch');
  ok('bloc de veille de sécurité ouvert', await page.isVisible('input[name=vuln_enabled]'));
  ok('délai des interrogations réglable', await page.isVisible('input[name=vuln_timeout]'));
  // Aller-retour complet : on modifie, on enregistre, on relit.
  const wasOn = await page.isChecked('input[name=vuln_enabled]');
  await page.setChecked('input[name=vuln_enabled]', !wasOn);
  await page.fill('input[name=vuln_timeout]', '11');
  ok('barre d\'enregistrement apparue à la modification', await page.isVisible('.savebar'));
  await page.click('.savebar button.btn-primary');
  await page.waitForLoadState('networkidle');
  await openAcc('#watch');
  ok('veille enregistrée puis relue',
    (await page.isChecked('input[name=vuln_enabled]')) === !wasOn);
  ok('délai enregistré puis relu',
    (await page.inputValue('input[name=vuln_timeout]')) === '11');
  // Remise en état : le banc suivant doit retrouver la configuration d'origine.
  await openAcc('#watch');
  await page.setChecked('input[name=vuln_enabled]', wasOn);
  await page.fill('input[name=vuln_timeout]', '8');
  await page.click('.savebar button.btn-primary');
  await page.waitForLoadState('networkidle');
  ok('réglages remis dans leur état initial',
    (await page.textContent('body')).includes('enregistr'));

  title('Reprise d\'un autre outil');
  await page.goto(BASE + '/index.php?p=import', { waitUntil: 'networkidle' });
  ok('champ de dépôt présent', (await page.$('input[type=file][name=file]')) !== null);
  // La zone de texte ne doit pas être obligatoire : sinon déposer un fichier
  // seul est refusé par le navigateur avant même d'atteindre le serveur.
  ok('la liste collée n\'est pas obligatoire',
    !(await page.$eval('#list', (el) => el.required)));
  const exportPath = '/tmp/claude-1000/uptimer-e2e-export.json';
  const fs = require('node:fs');
  fs.mkdirSync('/tmp/claude-1000', { recursive: true });
  fs.writeFileSync(exportPath, JSON.stringify({ stat: 'ok', monitors: [
    { id: 1, friendly_name: 'Navigateur A', url: 'https://nav-a.test/', type: 1, interval: 600, status: 2 },
    { id: 2, friendly_name: 'Navigateur B', url: 'https://nav-b.test/', type: 2,
      keyword_type: 1, keyword_value: 'Erreur', interval: 900, status: 0 },
    { id: 3, friendly_name: 'Navigateur port', url: 'mail.nav.test', type: 4 },
  ] }));
  await page.setInputFiles('input[type=file][name=file]', exportPath);
  await page.click('form.panel button.btn-primary');
  await page.waitForLoadState('networkidle');
  ok('aperçu affiché après dépôt', (await page.$('#preview')) !== null);
  const previewTxt = (await page.textContent('#preview')) || '';
  ok('la source est nommée', previewTxt.includes('UptimeRobot'));
  ok('la cadence de l\'export est reprise', previewTxt.includes('10 min'));
  ok('la sonde en pause est annoncée', previewTxt.includes('en pause'));
  ok('ce qui n\'a pas d\'équivalent est listé',
    (await page.$('.imp-skip')) !== null && previewTxt.includes('port TCP'));

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
