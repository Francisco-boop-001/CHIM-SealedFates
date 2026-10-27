// Real Chromium form submission against a standalone PHP fixture, never CHIM.
const assert = require('node:assert/strict');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {chromium} = require('playwright');
(async () => {
  assert(process.env.SF_TEST_PHP, 'SF_TEST_PHP must select an explicit local PHP binary');
  const probe = net.createServer();
  await new Promise((resolve,reject) => {probe.once('error',reject);probe.listen(0,'127.0.0.1',resolve);});
  const port = probe.address().port;
  await new Promise(resolve=>probe.close(resolve));
  const url = `http://127.0.0.1:${port}/fixture?private=synthetic-marker`;
  const child = spawn(process.env.SF_TEST_PHP,['-n','-S',`127.0.0.1:${port}`,path.join(__dirname,'fixtures','origin_browser.php')],{stdio:['ignore','pipe','pipe'],windowsHide:true});
  let errors = '';child.stderr.on('data',chunk=>{errors+=chunk;});
  let browser;
  try {
    for(let i=0;i<50;i++){
      if(child.exitCode!==null) throw new Error('Fixture exited: '+errors);
      const ready=await new Promise(resolve=>{const s=net.connect(port,'127.0.0.1');s.once('connect',()=>{s.destroy();resolve(true);});s.once('error',()=>{s.destroy();resolve(false);});});
      if(ready)break;
      if(i===49)throw new Error('Fixture listener unavailable');
      await new Promise(resolve=>setTimeout(resolve,50));
    }
    browser=await chromium.launch({headless:true,channel:process.env.SF_TEST_BROWSER_CHANNEL});
    const page=await browser.newPage();
    const external=[];let externalReferer='not-observed';
    await page.route('**/*',route=>{const u=new URL(route.request().url());if(u.hostname==='127.0.0.1'&&u.port===String(port))return route.continue();if(u.hostname==='localhost'&&u.port===String(port)&&u.pathname==='/external'){externalReferer=route.request().headers().referer??null;return route.fulfill({status:200,contentType:'text/html',body:'External fixture intercepted'});}external.push(u.origin);return route.abort();});
    await page.goto(url);
    const posted=page.waitForResponse(r=>r.request().method()==='POST');
    await page.getByRole('button',{name:'Submit fixture'}).click();
    const response=await posted;const body=await response.json();
    console.log(JSON.stringify({case:'same-origin-natural-form',status:response.status(),...body}));
    assert.equal(response.status(),200,'natural same-origin form must pass access, origin and CSRF guards');
    assert.equal(body.origin,`http://127.0.0.1:${port}`,'browser must retain its real nonopaque Origin');
    assert.equal(body.fetchSite,'same-origin');
    assert.equal(external.length,0,'fixture must not contact external or live services');
    await page.goto(url);await page.locator('[name=csrf]').fill('wrong');
    const refused=page.waitForResponse(r=>r.request().method()==='POST');await page.getByRole('button',{name:'Submit fixture'}).click();
    const bad=await refused;const badBody=await bad.json();assert.equal(bad.status(),400);assert.equal(badBody.originAllowed,true);assert.equal(badBody.csrfAllowed,false);
    console.log(JSON.stringify({case:'same-origin-bad-csrf',status:bad.status(),...badBody}));
    await page.goto(url);await page.getByRole('link',{name:'External fixture'}).click();
    assert.equal(externalReferer,null,'cross-origin navigation must suppress the private referrer');
    assert.equal(external.length,0);
    console.log(JSON.stringify({case:'cross-origin-navigation-intercepted',referer:externalReferer,externalConnections:0}));
    console.log('PASS isolated Chromium origin and CSRF form regression');
  } finally {
    if(browser)await browser.close();
    child.kill();
    await new Promise(resolve=>{if(child.exitCode!==null)return resolve();child.once('exit',resolve);setTimeout(resolve,1500).unref();});
  }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
