// Читає Google-рейтинг (панель знань) для списку профілів через справжній
// Chrome зі стелс-прапорцями. Вхід/вихід — JSON-файли.
//   node scripts/google_ratings.js <input.json> <output.json>
// input:  [{ "id": 1, "query": "Адвокат X Вінниця" }, ...]
// output: [{ "id": 1, "rating": "4.9", "count": 264 }, ...]  (rating=null якщо не знайдено)

const fs = require('fs');
const path = require('path');
const puppeteer = require(path.join(__dirname, '..', 'node_modules', 'puppeteer'));

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const DELAY_MS = 5000; // пауза між запитами, щоб не ловити "нетиповий трафік"

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const [, , inPath, outPath] = process.argv;
  const items = JSON.parse(fs.readFileSync(inPath, 'utf8'));

  const browser = await puppeteer.launch({
    headless: false,
    executablePath: CHROME,
    args: ['--lang=uk-UA,uk', '--disable-blink-features=AutomationControlled', '--window-size=1200,900'],
    ignoreDefaultArgs: ['--enable-automation'],
  });

  const results = [];
  try {
    const page = await browser.newPage();
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'uk-UA,uk;q=0.9' });

    for (const item of items) {
      let out = { id: item.id, rating: null, count: null };
      try {
        await page.goto('https://www.google.com/search?hl=uk&gl=ua&q=' + encodeURIComponent(item.query),
          { waitUntil: 'domcontentloaded', timeout: 40000 });
        await sleep(3500);
        const text = await page.evaluate(() => document.body.innerText);

        if (/нетиповий трафік|unusual traffic|Про цю сторінку/i.test(text) && text.length < 1500) {
          out.blocked = true;
        } else {
          const m = text.match(/(\d[.,]\d)\s*[★\s\S]{0,40}?(\d[\d\s]*)\s*Google\s*(відгук|reviews|отзыв)/i);
          if (m) {
            out.rating = m[1].replace(',', '.');
            out.count = parseInt(m[2].replace(/\s/g, ''), 10);
          }
        }
      } catch (e) {
        out.error = e.message;
      }
      results.push(out);
      process.stderr.write(`#${item.id}: ${out.rating ?? (out.blocked ? 'BLOCKED' : '—')} (${out.count ?? ''})\n`);
      await sleep(DELAY_MS);
    }
  } finally {
    await browser.close();
  }

  fs.writeFileSync(outPath, JSON.stringify(results, null, 2));
  process.stderr.write(`Готово: ${results.filter((r) => r.rating).length}/${results.length} з рейтингом\n`);
})();
