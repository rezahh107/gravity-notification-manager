const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const base = process.env.GNM_BASE_URL || 'http://127.0.0.1:8888';
const user = process.env.GNM_ADMIN_USER || 'evidence_admin';
const pass = process.env.GNM_ADMIN_PASS || 'Evidence-Only-Password-340!';
const outDir = process.env.GNM_EVIDENCE_DIR || path.join(process.cwd(), 'browser-evidence');
const locale = process.argv[2] || 'en_US';
const expectedDir = locale === 'fa_IR' ? 'rtl' : 'ltr';
fs.mkdirSync(outDir, { recursive: true });

const pages = [
  ['overview', 'gravity-notification-manager'],
  ['points', 'gravity-notification-manager-points'],
  ['providers', 'gravity-notification-manager-providers'],
  ['settings', 'gravity-notification-manager-settings'],
  ['advisor', 'gravity-notification-manager-advisor'],
  ['diagnostics', 'gravity-notification-manager-diagnostics'],
  ['log', 'gravity-notification-manager-operational-log'],
];
const headings = locale === 'fa_IR' ? {
  overview:'نمای کلی', points:'نقاط اعلان', providers:'ارائه‌دهندگان SMS', settings:'تنظیمات', advisor:'مشاور', diagnostics:'راهنما و عیب‌یابی', log:'گزارش عملیاتی'
} : {
  overview:'Overview', points:'Notification Points', providers:'SMS Providers', settings:'Settings', advisor:'Advisor', diagnostics:'Help & Diagnostics', log:'Operational Log'
};
const viewports = [
  ['desktop', 1440, 1000],
  ['tablet', 768, 1024],
  ['mobile', 390, 844],
];
const screenshotPlan = new Set(locale === 'fa_IR'
  ? ['settings:desktop','providers:mobile','log:tablet','log:mobile']
  : ['overview:desktop','providers:mobile','log:tablet']);
const forbiddenFa = [
  'Overview','Notification Points','Settings','Advisor','Help & Diagnostics','Operational Log',
  'Bale delivery by phone number','In development — currently unavailable',
  'Success','Failed','Ambiguous','Skipped','Configured','Needs Setup','Disabled','Not Applicable'
];
const secretSentinels = ['EVIDENCE-IPPANEL-SECRET','EVIDENCE-MELI-SECRET','EVIDENCE-SMSIR-SECRET','EVIDENCE-FARAZ-SECRET','EVIDENCE-BALE-SECRET'];
const providerNeedles = [/ippanel/i,/sms\.ir/i,/smsir/i,/melipayamak/i,/payamak/i,/faraz/i,/bale\.ai/i,/tapi\.bale/i];

function assert(cond, msg) { if (!cond) throw new Error(msg); }
function parseRgb(s) {
  const m = String(s).match(/rgba?\((\d+)[ ,]+(\d+)[ ,]+(\d+)/i);
  return m ? [Number(m[1]),Number(m[2]),Number(m[3])] : null;
}
function lum(rgb) {
  const vals = rgb.map(v => { const x=v/255; return x<=0.03928?x/12.92:Math.pow((x+0.055)/1.055,2.4); });
  return 0.2126*vals[0]+0.7152*vals[1]+0.0722*vals[2];
}
function contrast(fg,bg) {
  const a=parseRgb(fg), b=parseRgb(bg); if(!a||!b) return null;
  const l1=lum(a),l2=lum(b); return (Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05);
}
async function login(page) {
  await page.goto(base + '/wp-login.php', { waitUntil:'domcontentloaded', timeout:60000 });
  if (page.url().includes('/wp-admin')) return;
  await page.type('#user_login', user);
  await page.type('#user_pass', pass);
  await Promise.all([
    page.waitForNavigation({ waitUntil:'domcontentloaded', timeout:60000 }),
    page.click('#wp-submit'),
  ]);
  assert(page.url().includes('/wp-admin'), 'Login did not reach wp-admin');
}
async function keyboardEvidence(page, surface) {
  await page.evaluate(() => { document.body.focus(); window.scrollTo(0,0); });
  const seq=[];
  for (let i=0;i<24;i++) {
    await page.keyboard.press('Tab');
    const item = await page.evaluate(() => {
      const e=document.activeElement; if(!e) return null;
      const r=e.getBoundingClientRect(); const cs=getComputedStyle(e);
      return {
        tag:e.tagName, type:e.getAttribute('type')||'', name:e.getAttribute('name')||'', text:(e.innerText||e.getAttribute('aria-label')||e.getAttribute('value')||'').trim().slice(0,80),
        visible:r.width>0&&r.height>0, inViewport:r.bottom>0&&r.right>0&&r.top<innerHeight&&r.left<innerWidth,
        focusVisible:e.matches(':focus-visible'), outline:cs.outlineStyle, outlineWidth:cs.outlineWidth, boxShadow:cs.boxShadow
      };
    });
    if (item) seq.push(item);
  }
  const interactive=seq.filter(x => ['A','BUTTON','INPUT','SELECT','TEXTAREA'].includes(x.tag));
  assert(interactive.length >= 5, `${surface}: keyboard traversal did not reach enough controls`);
  assert(interactive.every(x => x.visible && x.inViewport), `${surface}: focused control clipped/hidden`);
  assert(interactive.some(x => x.focusVisible && ((x.outline && x.outline !== 'none' && x.outlineWidth !== '0px') || (x.boxShadow && x.boxShadow !== 'none'))), `${surface}: no visible focus evidence`);
  return seq;
}
async function analyze(page, surface, viewportName) {
  return await page.evaluate(({surface, expectedDir, locale, forbiddenFa, secretSentinels}) => {
    const root=document.querySelector('.gnm-admin');
    const h1=root?.querySelector('h1')?.textContent?.trim()||'';
    const text=root?.innerText||'';
    const rootOverflow=document.documentElement.scrollWidth-window.innerWidth;
    const controls=[...root.querySelectorAll('input:not([type="hidden"]),select,textarea,button,a.button')].filter(e => {
      const r=e.getBoundingClientRect(); return r.width>0 && r.height>0;
    }).map(e => {
      const r=e.getBoundingClientRect(); return {tag:e.tagName,name:e.getAttribute('name')||'',left:r.left,right:r.right,width:r.width};
    });
    const badControls=controls.filter(r => r.left < -3 || r.right > window.innerWidth + 3);
    const technical=[...root.querySelectorAll('.gnm-ltr,[dir="ltr"]')].slice(0,80).map(e => {
      const cs=getComputedStyle(e); return {text:(e.textContent||'').trim().slice(0,100),direction:cs.direction,unicodeBidi:cs.unicodeBidi};
    });
    const unavailable=root.querySelector('.gnm-mode--unavailable');
    const deferredFocusables=unavailable ? unavailable.querySelectorAll('a[href],button,input,select,textarea,[tabindex]:not([tabindex="-1"])').length : null;
    const secretInputs=[...root.querySelectorAll('input[type="password"]')].map(e => ({value:e.value,placeholder:e.placeholder,autocomplete:e.autocomplete}));
    const statusNodes=[...root.querySelectorAll('.gnm-status')].map(e => {
      const cs=getComputedStyle(e); return {text:e.textContent.trim(),classes:e.className,fg:cs.color,bg:cs.backgroundColor};
    });
    const logWrap=root.querySelector('.gnm-log-table-wrap');
    const logOverflow=logWrap ? {client:logWrap.clientWidth,scroll:logWrap.scrollWidth,overflowX:getComputedStyle(logWrap).overflowX} : null;
    return {
      surface, locale, dir:document.documentElement.dir, h1, rootOverflow, badControls, technical, deferredFocusables,
      secretInputs, secretLeak:secretSentinels.filter(s=>text.includes(s)), statusNodes, logOverflow,
      faFallback: locale==='fa_IR' ? forbiddenFa.filter(s=>text.includes(s)) : [],
      containsComingSoon:/Coming soon|به.?زودی/i.test(text),
      providerNames:['IPPanel','Melipayamak','SMS.ir','FarazSMS'].filter(n=>text.includes(n)),
      hasDebugButton:!!root.querySelector('.gnm-copy-debug'),
      debugButtonCount:root.querySelectorAll('.gnm-copy-debug').length,
      traceCount:root.querySelectorAll('.gnm-log-trace').length,
      statusText:[...root.querySelectorAll('.gnm-status')].map(e=>e.textContent.trim()),
      forms:root.querySelectorAll('form').length
    };
  }, {surface, expectedDir, locale, forbiddenFa, secretSentinels});
}

(async () => {
  const executable = process.env.CHROME_BIN || '/usr/bin/google-chrome';
  const browser = await puppeteer.launch({ executablePath:executable, headless:true, args:['--no-sandbox','--disable-dev-shm-usage'] });
  const page=await browser.newPage();
  const remoteRequests=[];
  const failedRequests=[];
  page.on('request', req => {
    try { const u=new URL(req.url()); if(!['127.0.0.1','localhost'].includes(u.hostname) && !['data:','blob:','chrome-extension:'].includes(u.protocol)) remoteRequests.push({method:req.method(),url:req.url()}); } catch(_) {}
  });
  page.on('requestfailed', req => failedRequests.push({url:req.url(),error:req.failure()?.errorText||''}));
  await page.setViewport({width:1440,height:1000});
  await login(page);
  const results=[];
  const contrasts=[];
  const keyboard={};
  for (const [vpName,width,height] of viewports) {
    await page.setViewport({width,height});
    for (const [surface,slug] of pages) {
      const url=base+`/wp-admin/admin.php?page=${encodeURIComponent(slug)}`;
      const resp=await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});
      assert(resp && resp.status() < 400, `${surface}/${vpName}: HTTP ${resp?.status()}`);
      await new Promise(r=>setTimeout(r,250));
      const data=await analyze(page,surface,vpName);
      assert(data.dir===expectedDir, `${surface}/${vpName}: expected dir=${expectedDir}, got ${data.dir}`);
      assert(data.h1===headings[surface], `${surface}/${vpName}: unexpected heading ${JSON.stringify(data.h1)} expected ${JSON.stringify(headings[surface])}`);
      assert(data.rootOverflow <= 4, `${surface}/${vpName}: page-breaking horizontal overflow ${data.rootOverflow}px`);
      assert(data.badControls.length===0, `${surface}/${vpName}: controls overflow viewport ${JSON.stringify(data.badControls.slice(0,3))}`);
      assert(data.secretLeak.length===0, `${surface}/${vpName}: secret sentinel rendered`);
      if(locale==='fa_IR') assert(data.faFallback.length===0, `${surface}/${vpName}: untranslated expected UI strings: ${data.faFallback.join(', ')}`);
      assert(!data.containsComingSoon, `${surface}/${vpName}: prohibited Coming soon wording found`);
      if(surface==='providers') {
        assert(data.providerNames.length===4, `${surface}/${vpName}: not all four providers rendered`);
        assert(data.secretInputs.length>=4 && data.secretInputs.every(x=>x.value===''), `${surface}/${vpName}: provider secret input leaked stored value`);
      }
      if(surface==='settings') {
        assert(data.deferredFocusables===0, `${surface}/${vpName}: deferred Bale phone mode contains focusable controls`);
        const body=await page.$eval('.gnm-admin',e=>e.innerText);
        if(locale==='fa_IR') {
          assert(body.includes('ارسال بله با شماره موبایل'),'Persian deferred Bale label missing');
          assert(body.includes('در حال توسعه — فعلاً در دسترس نیست'),'Persian deferred Bale status missing');
          assert(body.includes('ارسال بله با شناسه گفتگو یا نام کاربری'),'Persian available Bale mode missing');
        } else {
          assert(body.includes('Bale delivery by phone number'),'English deferred Bale label missing');
          assert(body.includes('In development — currently unavailable'),'English deferred Bale status missing');
          assert(body.includes('Bale delivery by chat ID or username'),'English available Bale mode missing');
        }
      }
      if(surface==='log') {
        assert(data.traceCount>=4, `${surface}/${vpName}: expected seeded SMS traces`);
        assert(data.debugButtonCount>=2, `${surface}/${vpName}: LLM Debug Report actions not discoverable`);
        assert(data.logOverflow && ['auto','scroll'].includes(data.logOverflow.overflowX), `${surface}/${vpName}: log table wrapper not deliberately scrollable`);
      }
      results.push({viewport:{name:vpName,width,height},...data});
      if(screenshotPlan.has(`${surface}:${vpName}`)) await page.screenshot({path:path.join(outDir,`${locale}-${surface}-${vpName}-${width}.png`),fullPage:true});
    }
  }

  await page.setViewport({width:768,height:1024});
  let resp=await page.goto(base+'/wp-admin/admin.php?page=gravity-notification-manager-operational-log&channel=bale',{waitUntil:'domcontentloaded',timeout:60000});
  assert(resp.status()<400,'Bale log failed');
  const bale=await analyze(page,'log-bale','tablet');
  assert(bale.traceCount>=4,'Bale log missing seeded traces');
  assert(bale.debugButtonCount>=2,'Bale log missing debug actions');
  if(locale==='fa_IR') await page.screenshot({path:path.join(outDir,`${locale}-log-bale-tablet-768.png`),fullPage:true});
  resp=await page.goto(base+'/wp-admin/admin.php?page=gravity-notification-manager-operational-log&channel=sms&trace_id=99999999-9999-4999-8999-999999999999',{waitUntil:'domcontentloaded',timeout:60000});
  assert(resp.status()<400,'Empty log state failed');
  const emptyText=await page.$eval('.gnm-admin',e=>e.innerText);
  assert(locale==='fa_IR' ? emptyText.includes('هیچ رکورد عملیاتی مطابقی پیدا نشد.') : emptyText.includes('No matching operational records were found.'),'Operational Log empty state not understandable');

  for (const target of [
    ['providers','gravity-notification-manager-providers'],
    ['log','gravity-notification-manager-operational-log']
  ]) {
    await page.goto(base+`/wp-admin/admin.php?page=${target[1]}`,{waitUntil:'domcontentloaded',timeout:60000});
    const nodes=await page.$$eval('.gnm-status',els=>els.map(e=>{const cs=getComputedStyle(e);return {text:e.textContent.trim(),classes:e.className,fg:cs.color,bg:cs.backgroundColor};}));
    for(const n of nodes){ const ratio=contrast(n.fg,n.bg); contrasts.push({...n,ratio:ratio===null?null:Number(ratio.toFixed(2))}); }
  }
  assert(contrasts.some(x=>/success|configured/.test(x.classes)), 'No success/configured contrast sample');
  assert(contrasts.some(x=>/failed/.test(x.classes)), 'No failed contrast sample');
  assert(contrasts.some(x=>/ambiguous|needs-setup/.test(x.classes)), 'No warning contrast sample');
  assert(contrasts.some(x=>/disabled|skipped/.test(x.classes)), 'No neutral/disabled contrast sample');

  for (const [surface,slug] of [['providers','gravity-notification-manager-providers'],['settings','gravity-notification-manager-settings'],['log','gravity-notification-manager-operational-log']]) {
    await page.setViewport({width:1440,height:1000});
    await page.goto(base+`/wp-admin/admin.php?page=${slug}`,{waitUntil:'domcontentloaded',timeout:60000});
    keyboard[surface]=await keyboardEvidence(page,surface);
  }

  if(locale==='fa_IR') {
    await page.goto(base+'/wp-admin/admin.php?page=gravity-notification-manager-operational-log',{waitUntil:'domcontentloaded',timeout:60000});
    const tech=await page.$$eval('.gnm-ltr,[dir="ltr"]',els=>els.filter(e=>e.getBoundingClientRect().width>0).slice(0,100).map(e=>({text:(e.textContent||e.value||'').trim(),dir:getComputedStyle(e).direction,bidi:getComputedStyle(e).unicodeBidi})));
    assert(tech.some(x=>x.text.includes('11111111-1111-4111-8111-111111111111') && x.dir==='ltr'),'Persian trace ID is not rendered LTR');
    assert(tech.filter(x=>x.text).every(x=>x.dir==='ltr'),'Persian technical island rendered non-LTR');
  }

  const providerRemote=remoteRequests.filter(r=>providerNeedles.some(re=>re.test(r.url)));
  assert(providerRemote.length===0, `Browser render contacted provider endpoint: ${JSON.stringify(providerRemote)}`);
  const report={locale,expectedDir,chrome:await browser.version(),results,bale,contrasts,keyboard,remoteRequests,failedRequests,providerRemote};
  fs.writeFileSync(path.join(outDir,`${locale}-browser-evidence.json`),JSON.stringify(report,null,2));
  console.log(`BROWSER_EVIDENCE_${locale}=PASS surfaces=${pages.length} viewports=${viewports.length} remote=${remoteRequests.length}`);
  await browser.close();
})().catch(async err=>{ console.error(err.stack||err); process.exit(1); });
