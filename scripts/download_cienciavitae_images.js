#!/usr/bin/env node

/*
  Compliant Ciência Vitae public image downloader.
  - Uses transparent fixed pacing (no randomization/evasion behavior).
  - Reads people from CSV and searches by full name.
  - Downloads each matched profile image into output directory.
*/

const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const { chromium } = require('playwright');

const SEARCH_URL = 'https://www.cienciavitae.pt/portal/pesquisa?lang=pt';

function parseArgs(argv) {
  const args = {
    input: '/opt/homebrew/var/www/drupal/web/modules/custom/sir/reports/kgr_persons_with_organizations.csv',
    output: '/opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/imgpeople',
    delaySeconds: 8,
    limit: 0,
    headful: false,
    overrides: '',
  };

  for (let i = 2; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === '--input' && argv[i + 1]) args.input = argv[++i];
    else if (a === '--output' && argv[i + 1]) args.output = argv[++i];
    else if (a === '--delay-seconds' && argv[i + 1]) args.delaySeconds = Number(argv[++i]);
    else if (a === '--limit' && argv[i + 1]) args.limit = Number(argv[++i]);
    else if (a === '--overrides' && argv[i + 1]) args.overrides = argv[++i];
    else if (a === '--headful') args.headful = true;
    else if (a === '--help') {
      console.log('Usage: node scripts/download_cienciavitae_images.js [options]');
      console.log('  --input <csvPath>');
      console.log('  --output <dirPath>');
      console.log('  --delay-seconds <n>   fixed pause between searches (default 8)');
      console.log('  --limit <n>           process only first n rows (default all)');
      console.log('  --overrides <csvPath> CSV with full_name,cv_id columns');
      console.log('  --headful             show browser UI');
      process.exit(0);
    }
  }

  if (!Number.isFinite(args.delaySeconds) || args.delaySeconds < 0) {
    throw new Error('Invalid --delay-seconds value');
  }
  if (!Number.isFinite(args.limit) || args.limit < 0) {
    throw new Error('Invalid --limit value');
  }

  return args;
}

function parseCsvLine(line) {
  const cells = [];
  let current = '';
  let inQuotes = false;

  for (let i = 0; i < line.length; i += 1) {
    const ch = line[i];
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        current += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
    } else if (ch === ',' && !inQuotes) {
      cells.push(current);
      current = '';
    } else {
      current += ch;
    }
  }
  cells.push(current);
  return cells.map((v) => v.trim());
}

function readCsv(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  const lines = raw.split(/\r?\n/).filter((l) => l.trim() !== '');
  if (lines.length < 2) return [];

  const headers = parseCsvLine(lines[0]);
  return lines.slice(1).map((line) => {
    const values = parseCsvLine(line);
    const row = {};
    headers.forEach((h, i) => {
      row[h.replace(/^"|"$/g, '')] = (values[i] || '').replace(/^"|"$/g, '');
    });
    return row;
  });
}

function readOverrides(filePath) {
  if (!filePath || !fs.existsSync(filePath)) return new Map();
  const rows = readCsv(filePath);
  const map = new Map();
  for (const row of rows) {
    const name = row.full_name || row.name || '';
    const cvId = row.cv_id || row.cvId || row.id || '';
    if (name && cvId) {
      map.set(normalizeText(name), cvId.trim());
    }
  }
  return map;
}

function normalizeText(s) {
  return (s || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function slugify(s) {
  const t = normalizeText(s).replace(/\s+/g, '_');
  return t || 'unknown_person';
}

function nameSimilarityScore(target, candidate) {
  const nt = normalizeText(target);
  const nc = normalizeText(candidate);
  if (!nt || !nc) return 0;
  if (nt === nc) return 100;
  if (nc.startsWith(nt) || nt.startsWith(nc)) return 80;

  const tTokens = nt.split(' ').filter(Boolean);
  const cTokens = new Set(nc.split(' ').filter(Boolean));
  const hit = tTokens.filter((tok) => cTokens.has(tok)).length;
  const ratio = hit / Math.max(1, tTokens.length);
  return Math.round(ratio * 70);
}

function isAvatarUrl(url) {
  return /\/avatar\.(png|jpg|jpeg)$/i.test(url || '');
}

async function resolveProfilePhotoUrl(page, cvId) {
  const profileUrl = `https://www.cienciavitae.pt/${cvId}`;
  try {
    await page.goto(profileUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForTimeout(800);
    const imgUrl = await page.evaluate(() => {
      const imgs = Array.from(document.querySelectorAll('img'))
        .map((img) => img.getAttribute('src') || '')
        .filter(Boolean)
        .map((src) => src.startsWith('/') ? `https://www.cienciavitae.pt${src}` : src)
        .filter((src) => /\/fotos\/publico\//i.test(src));

      const preferred = imgs.find((src) => !/\/avatar\.(png|jpg|jpeg)(\?|$)/i.test(src));
      return preferred || imgs[0] || '';
    });
    return imgUrl || '';
  } catch (_) {
    return '';
  }
}

async function dismissCookieBanner(page) {
  const ok = page.locator('a:has-text("ok")').first();
  if (await ok.count()) {
    try {
      await ok.click({ timeout: 2000 });
    } catch (_) {
      // Non-fatal.
    }
  }
}

async function searchCandidates(page, fullName) {
  await page.goto(SEARCH_URL, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await dismissCookieBanner(page);

  const input = page.locator('input[placeholder*="nome" i], input[placeholder*="CIÊNCIA ID" i]').first();
  await input.fill('');
  await input.fill(fullName);

  const searchButton = page.locator('button:has-text("Pesquisar")').first();
  await searchButton.click();

  await page.waitForTimeout(1200);
  await page.waitForSelector('a[href]', { timeout: 15000 });

  const candidates = await page.evaluate(() => {
    const rx = /^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/i;
    const anchors = Array.from(document.querySelectorAll('a[href]'));
    const out = [];

    for (const a of anchors) {
      const href = (a.getAttribute('href') || '').trim();
      if (!rx.test(href)) continue;

      const title = (a.textContent || '').replace(/\s+/g, ' ').trim();
      if (!title.includes('(') || !title.includes(')')) continue;

      const displayName = title.replace(/\s*\([A-F0-9\-]+\)\s*$/i, '').trim();
      const card = a.closest('.ui-container-card, .ui-outputpanel, .container') || a.parentElement;
      const cardText = (card?.textContent || '').replace(/\s+/g, ' ').trim();
      const imgSrc = card?.querySelector('img')?.getAttribute('src') || '';

      out.push({ id: href, title, displayName, cardText, imgSrc });
    }

    const dedup = new Map();
    for (const row of out) {
      if (!dedup.has(row.id)) dedup.set(row.id, row);
    }
    return Array.from(dedup.values());
  });

  return candidates;
}

async function downloadImage(context, imageUrl, outputPath) {
  const response = await context.request.get(imageUrl, { timeout: 30000 });
  if (!response.ok()) {
    return { ok: false, status: response.status() };
  }

  const contentType = response.headers()['content-type'] || '';
  if (!contentType.startsWith('image/')) {
    return { ok: false, status: response.status(), error: `Not image content-type: ${contentType}` };
  }

  const body = await response.body();
  await fsp.writeFile(outputPath, body);
  return { ok: true, status: response.status(), contentType, bytes: body.length };
}

async function main() {
  const args = parseArgs(process.argv);
  await fsp.mkdir(args.output, { recursive: true });

  const rows = readCsv(args.input);
  const people = args.limit > 0 ? rows.slice(0, args.limit) : rows;
  const overrides = readOverrides(args.overrides);

  const browser = await chromium.launch({ headless: !args.headful });
  const context = await browser.newContext({
    locale: 'pt-PT',
    // Some local environments have incomplete CA chains; keep this scoped to this script.
    ignoreHTTPSErrors: true,
  });
  const page = await context.newPage();

  const results = [];

  for (let idx = 0; idx < people.length; idx += 1) {
    const p = people[idx];
    const fullName = p.full_name || '';
    const org = p.organization || '';

    process.stdout.write(`[${idx + 1}/${people.length}] Searching: ${fullName}\\n`);

    try {
      const overrideCvId = overrides.get(normalizeText(fullName));

      if (overrideCvId) {
        const fromProfile = await resolveProfilePhotoUrl(page, overrideCvId);
        let imageUrl = fromProfile || `https://www.cienciavitae.pt/fotos/publico/${overrideCvId}.jpg`;
        const baseName = `${slugify(fullName)}__${overrideCvId}`;
        let targetPath = path.join(args.output, `${baseName}.jpg`);

        let dl = await downloadImage(context, imageUrl, targetPath);
        if (!dl.ok && imageUrl.endsWith('.jpg')) {
          const pngUrl = imageUrl.replace(/\.jpg$/i, '.png');
          targetPath = path.join(args.output, `${baseName}.png`);
          dl = await downloadImage(context, pngUrl, targetPath);
          if (dl.ok) imageUrl = pngUrl;
        }

        if (!dl.ok) {
          results.push({
            fullName,
            org,
            personUri: p.person_uri || '',
            cvId: overrideCvId,
            imageUrl,
            file: '',
            status: `override_download_failed_${dl.status || 'unknown'}`,
          });
        } else {
          results.push({
            fullName,
            org,
            personUri: p.person_uri || '',
            cvId: overrideCvId,
            imageUrl,
            file: targetPath,
            status: isAvatarUrl(imageUrl) ? 'downloaded_avatar' : 'downloaded',
          });
        }

        if (idx < people.length - 1 && args.delaySeconds > 0) {
          await page.waitForTimeout(args.delaySeconds * 1000);
        }
        continue;
      }

      const candidates = await searchCandidates(page, fullName);

      if (!candidates.length) {
        results.push({ fullName, org, personUri: p.person_uri || '', cvId: '', imageUrl: '', file: '', status: 'no_candidates' });
        continue;
      }

      const ranked = candidates
        .map((c) => {
          const nameScore = nameSimilarityScore(fullName, c.displayName);
          const orgHit = org && normalizeText(c.cardText).includes(normalizeText(org)) ? 15 : 0;
          const avatarPenalty = isAvatarUrl(c.imgSrc) ? -5 : 0;
          return { ...c, score: nameScore + orgHit + avatarPenalty };
        })
        .sort((a, b) => b.score - a.score);

      const best = ranked[0];
      let imageUrl = best.imgSrc || '';
      if (imageUrl && imageUrl.startsWith('/')) {
        imageUrl = `https://www.cienciavitae.pt${imageUrl}`;
      }

      if (!imageUrl || isAvatarUrl(imageUrl)) {
        const fromProfile = await resolveProfilePhotoUrl(page, best.id);
        if (fromProfile) {
          imageUrl = fromProfile;
        }
      }

      if (!imageUrl) {
        imageUrl = `https://www.cienciavitae.pt/fotos/publico/${best.id}.jpg`;
      }

      const baseName = `${slugify(fullName)}__${best.id}`;
      let targetPath = path.join(args.output, `${baseName}.jpg`);

      let dl = await downloadImage(context, imageUrl, targetPath);
      if (!dl.ok && imageUrl.endsWith('.jpg')) {
        const pngUrl = imageUrl.replace(/\.jpg$/i, '.png');
        targetPath = path.join(args.output, `${baseName}.png`);
        dl = await downloadImage(context, pngUrl, targetPath);
        if (dl.ok) imageUrl = pngUrl;
      }

      if (!dl.ok) {
        results.push({
          fullName,
          org,
          personUri: p.person_uri || '',
          cvId: best.id,
          imageUrl,
          file: '',
          status: `download_failed_${dl.status || 'unknown'}`,
        });
      } else {
        results.push({
          fullName,
          org,
          personUri: p.person_uri || '',
          cvId: best.id,
          imageUrl,
          file: targetPath,
          status: isAvatarUrl(imageUrl) ? 'downloaded_avatar' : 'downloaded',
        });
      }
    } catch (err) {
      results.push({
        fullName,
        org,
        personUri: p.person_uri || '',
        cvId: '',
        imageUrl: '',
        file: '',
        status: `error_${String(err.message || err).replace(/\s+/g, '_').slice(0, 80)}`,
      });
    }

    if (idx < people.length - 1 && args.delaySeconds > 0) {
      await page.waitForTimeout(args.delaySeconds * 1000);
    }
  }

  const logPath = path.join(args.output, 'download_log.csv');
  const header = ['person_uri', 'full_name', 'organization', 'cv_id', 'image_url', 'file', 'status'];
  const esc = (v) => `"${String(v || '').replace(/"/g, '""')}"`;
  const lines = [header.map(esc).join(',')];
  for (const r of results) {
    lines.push([
      r.personUri,
      r.fullName,
      r.org,
      r.cvId,
      r.imageUrl,
      r.file,
      r.status,
    ].map(esc).join(','));
  }
  await fsp.writeFile(logPath, `${lines.join('\n')}\n`, 'utf8');

  const summary = results.reduce((acc, r) => {
    acc[r.status] = (acc[r.status] || 0) + 1;
    return acc;
  }, {});

  process.stdout.write('\nSummary:\n');
  Object.entries(summary).forEach(([k, v]) => {
    process.stdout.write(`  ${k}: ${v}\n`);
  });
  process.stdout.write(`Log: ${logPath}\n`);

  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
