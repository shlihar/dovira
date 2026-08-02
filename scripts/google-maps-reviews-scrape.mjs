#!/usr/bin/env node
import fs from 'node:fs/promises';
import process from 'node:process';
import puppeteer from 'puppeteer';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function argValue(name, fallback = null) {
  const idx = process.argv.indexOf(name);
  if (idx === -1) return fallback;
  return process.argv[idx + 1] ?? fallback;
}

const url = process.argv[2];
if (!url || !/^https?:\/\//i.test(url)) {
  console.error('Usage: node scripts/google-maps-reviews-scrape.mjs <google-maps-url> [--limit 30] [--out storage/app/google-reviews.json]');
  process.exit(1);
}

const limit = Math.max(1, Math.min(200, Number(argValue('--limit', '30')) || 30));
const outFile = argValue('--out', 'storage/app/google-reviews.json');
const debugDir = argValue('--debug-dir', 'storage/app/google-reviews-debug');
const headful = process.argv.includes('--headful');
const manual = process.argv.includes('--manual');

async function resolveShortMapsUrl(inputUrl) {
  try {
    const res = await fetch(inputUrl, { method: 'HEAD', redirect: 'manual' });
    const loc = res.headers.get('location');
    return loc || inputUrl;
  } catch {
    return inputUrl;
  }
}

const browser = await puppeteer.launch({
  headless: headful ? false : true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});

try {
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 2200 });
  const resolvedUrl = await resolveShortMapsUrl(url);
  await page.goto(resolvedUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await sleep(2500);

  if (manual) {
    console.log('Manual mode: open reviews panel in browser, then press Enter in terminal...');
    await new Promise((resolve) => process.stdin.once('data', () => resolve()));
  }

  const consentSelectors = [
    'button[aria-label*="Accept"]',
    'button[aria-label*="Прийняти"]',
    'button[aria-label*="I agree"]',
  ];
  for (const s of consentSelectors) {
    const btn = await page.$(s);
    if (btn) {
      await btn.click().catch(() => {});
      await sleep(1000);
      break;
    }
  }

  // Open reviews tab if not already open.
  const reviewTabSelectors = [
    '[jsaction*="pane.rating.moreReviews"]',
    'button[jsaction*="pane.rating.moreReviews"]',
    'button[aria-label*="відгук"]',
    'button[aria-label*="reviews"]',
    'button[jsaction*="pane.rating.moreReviews"]',
    'a[aria-label*="відгук"]',
    'a[aria-label*="reviews"]',
  ];

  for (const selector of reviewTabSelectors) {
    const el = await page.$(selector);
    if (el) {
      await el.click().catch(() => {});
      break;
    }
  }

  await sleep(2500);

  // Close "Write a review" modal if opened by mistake.
  const closeModalBtn = await page.$('button[aria-label*="Закрити"], button[aria-label*="Close"]');
  if (closeModalBtn) {
    await closeModalBtn.click().catch(() => {});
    await sleep(800);
  }

  const pickScrollable = async () => {
    const handle = await page.evaluateHandle(() => {
      const candidates = Array.from(document.querySelectorAll('div, [role=\"feed\"], [aria-label*=\"відгук\"], [aria-label*=\"review\"]'));
      let best = null;
      let bestScore = 0;
      for (const el of candidates) {
        const style = window.getComputedStyle(el);
        if (!/(auto|scroll)/.test(style.overflowY || '')) continue;
        const h = el.clientHeight || 0;
        const sh = el.scrollHeight || 0;
        if (sh <= h + 80) continue;

        const reviewCards = el.querySelectorAll('div.jftiEf, div[data-review-id], article, [data-review-id], [jslog*=\"review\"]').length;
        const score = reviewCards * 10 + (sh - h);
        if (score > bestScore) {
          best = el;
          bestScore = score;
        }
      }
      return best;
    });
    const asEl = handle.asElement();
    if (!asEl) {
      await handle.dispose();
      return null;
    }
    return asEl;
  };

  const scrollBox = await pickScrollable();
  if (!scrollBox) {
    await fs.mkdir(debugDir, { recursive: true });
    await page.screenshot({ path: `${debugDir}/no-scroll-container.png`, fullPage: true }).catch(() => {});
    const html = await page.content();
    await fs.writeFile(`${debugDir}/no-scroll-container.html`, html, 'utf8');
    throw new Error(`Could not find reviews scroll container. Debug saved to ${debugDir}`);
  }

  const seen = new Map();
  let stableIters = 0;

  for (let i = 0; i < 120; i++) {
    const batch = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('div.jftiEf, div[data-review-id], article, [data-review-id], [jslog*=\"review\"]'));
      const starTextToNumber = (s) => {
        if (!s) return null;
        const m = String(s).match(/([0-5](?:[\.,][0-9])?)/);
        return m ? Number(m[1].replace(',', '.')) : null;
      };

      return cards.map((card) => {
        const author = card.querySelector('.d4r55, .TSUbDb, [class*="author"], [aria-label*="Автор"]')?.textContent?.trim() || null;
        const text = card.querySelector('.wiI7pd, .MyEned, .review-full-text, [data-expandable-section]')?.textContent?.trim() || null;
        const date = card.querySelector('.rsqaWe, .xRkPPb, [class*="date"]')?.textContent?.trim() || null;
        const starLabel = card.querySelector('[aria-label*="зір"], [aria-label*="star"], .kvMYJc')?.getAttribute('aria-label') || card.textContent;
        const rating = starTextToNumber(starLabel);

        const reviewLink = card.querySelector('a[href*="/maps/reviews/"]')?.href || null;
        return { author, text, date, rating, review_link: reviewLink };
      });
    });

    const before = seen.size;
    for (const row of batch) {
      const key = [row.author || '', row.rating || '', row.date || '', row.text || ''].join('|').toLowerCase();
      if (key.replace(/\|/g, '') === '') continue;
      if (!seen.has(key)) seen.set(key, row);
    }

    if (seen.size >= limit) break;

    if (seen.size === before) {
      stableIters += 1;
    } else {
      stableIters = 0;
    }

    await scrollBox.evaluate((el) => {
      el.scrollBy({ top: Math.max(800, el.clientHeight * 0.9), behavior: 'instant' });
    });

    await sleep(1200);

    if (stableIters >= 8) break;
  }

  const reviews = Array.from(seen.values()).slice(0, limit);
  const payload = {
    source_url: url,
    collected_at: new Date().toISOString(),
    count: reviews.length,
    reviews,
  };

  await fs.mkdir(outFile.split('/').slice(0, -1).join('/') || '.', { recursive: true });
  await fs.writeFile(outFile, JSON.stringify(payload, null, 2), 'utf8');

  console.log(`Collected ${reviews.length} reviews -> ${outFile}`);
} finally {
  await browser.close();
}
