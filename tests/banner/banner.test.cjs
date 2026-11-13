/*
 * Drives the banner's script in Chromium through its states, with the status
 * check answered by Playwright. Run through ./run.sh.
 */
const { chromium, devices } = require('playwright');
const base = 'http://127.0.0.1:' + (process.env.PORT || 8778);
let failures = 0;
const check = (cond, what) => { console.log((cond ? '  ok    ' : '  FAIL  ') + what); if (!cond) failures++; };

(async () => {
  const browser = await chromium.launch();
  const out = process.argv[2];

  // 1. Just paid: "being prepared" turns into "ready" by itself, then stops asking.
  {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const answers = [
      { state: 'preparing', key: 'service-7', inviteUrl: '' },
      { state: 'preparing', key: 'service-7', inviteUrl: '' },
      { state: 'ready', key: 'service-7', inviteUrl: 'https://join.example.com/j/NEW123' },
    ];
    let calls = 0;
    await page.route('**/wizarr/status', r => r.fulfill({ json: answers[Math.min(calls++, answers.length - 1)] }));
    await page.goto(base + '/dark-preparing.html');
    check(await page.isVisible('text=Your access is being prepared'), 'starts as "being prepared"');
    await page.waitForSelector('#wizarr-banner[data-state="ready"]', { timeout: 12000 });
    check(await page.isVisible('text=Your access is ready'), 'switches to "ready" without reloading');
    check(await page.getAttribute('[data-invite]', 'href') === 'https://join.example.com/j/NEW123', 'button points at the new invitation');
    check(!(await page.isVisible('text=Your access is being prepared')), 'old text hidden');
    const before = calls; await page.waitForTimeout(4000);
    check(calls === before, 'stops asking once ready (' + calls + ' calls)');
    await page.close();
  }

  // 2. Customer already created the user: banner disappears.
  {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.route('**/wizarr/status', r => r.fulfill({ json: { state: 'done' } }));
    await page.goto(base + '/dark-ready.html');
    await page.waitForTimeout(500);
    check(!(await page.$('#wizarr-banner')), 'removed when the link has been used');
    await page.close();
  }

  // 3. Closing it keeps it closed for this state, but a new state shows again.
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    let state = 'ready';
    await page.route('**/wizarr/status', r => r.fulfill({ json: { state, key: 'service-7', inviteUrl: state === 'ready' ? 'https://invite.example.com/j/AB12CD' : '' } }));
    await page.goto(base + '/dark-ready.html');
    await page.click('#wizarr-banner [data-close]');
    check(!(await page.isVisible('#wizarr-banner')), 'close button hides it');
    await page.goto(base + '/dark-ready.html');
    await page.waitForTimeout(300);
    check(!(await page.isVisible('#wizarr-banner')), 'stays hidden on the next page');
    state = 'expired';
    await page.goto(base + '/dark-ready.html');
    await page.waitForSelector('#wizarr-banner[data-state="expired"]', { timeout: 3000 }).catch(() => {});
    check(await page.isVisible('text=Your invitation link has expired'), 'shows again when the link has expired');
    await ctx.close();
  }

  // 4. The check fails while preparing: keeps trying.
  {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    let calls = 0;
    await page.route('**/wizarr/status', r => { calls++; return calls < 3 ? r.fulfill({ status: 500, body: 'oops' }) : r.fulfill({ json: { state: 'ready', key: 'service-7', inviteUrl: 'https://join.example.com/j/LATE' } }); });
    await page.goto(base + '/dark-preparing.html');
    await page.waitForSelector('#wizarr-banner[data-state="ready"]', { timeout: 12000 }).catch(() => {});
    check(await page.isVisible('text=Your access is ready'), 'recovers after failed checks (' + calls + ' calls)');
    await page.close();
  }

  // 5. Trial over: stays (the status check confirms it) and offers the shop.
  {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.route('**/wizarr/status', r => r.fulfill({ json: { state: 'trial_over', key: 'trial-7', inviteUrl: '' } }));
    await page.goto(base + '/dark-trial_over.html');
    await page.waitForTimeout(500);
    check(await page.isVisible('text=Your trial has ended'), '"trial over" shown');
    check(await page.getAttribute('#wizarr-banner [data-when~="trial_over"] a', 'href') === '/#subscriptions', 'button goes to the subscriptions');
    check(!(await page.isVisible('text=Create your user')), 'no sign-up button');
    await page.close();
  }

  // 6. During a trial: time left, kept up to date by the status check, shop button.
  {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.route('**/wizarr/status', r => r.fulfill({ json: { state: 'trial', key: 'service-7', inviteUrl: '', remaining: '4 days left' } }));
    await page.goto(base + '/dark-trial.html');
    await page.waitForTimeout(500);
    check(await page.isVisible('text=Trial: 4 days left'), 'time left shown and refreshed');
    check(await page.getAttribute('#wizarr-banner [data-when~="trial"] a', 'href') === '/#subscriptions', 'button to the subscriptions');
    check(!(await page.isVisible('text=Create your user')), 'no sign-up button');
    await page.screenshot({ path: process.argv[2] + '/../trial-box.png' }).catch(() => {});
    await page.close();
  }

  // Screenshots for the person: ready + preparing, dark + light, and on a phone.
  for (const [mode, st] of [['dark', 'ready'], ['default', 'ready'], ['dark', 'preparing']]) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    await page.route('**/wizarr/status', r => r.fulfill({ json: { state: st, key: 'service-7', inviteUrl: 'https://invite.example.com/j/AB12CD' } }));
    await page.goto(`${base}/${mode}-${st}.html`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${out}/banner-${mode}-${st}.png` });
    await page.close();
  }
  const ctx = await browser.newContext({ ...devices['iPhone 13'] });
  const phone = await ctx.newPage();
  await phone.route('**/wizarr/status', r => r.fulfill({ json: { state: 'ready', key: 'service-7', inviteUrl: 'https://invite.example.com/j/AB12CD' } }));
  await phone.goto(base + '/dark-ready.html', { waitUntil: 'networkidle' });
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check(overflow === 0, 'no sideways scrolling on a phone');
  await phone.screenshot({ path: `${out}/banner-phone.png` });
  await browser.close();
  console.log(failures ? `${failures} failed` : 'all passed');
  process.exit(failures ? 1 : 0);
})();
