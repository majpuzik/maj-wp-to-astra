// Univerzální verifikátor: porovná originál vs převedený web po stránkách.
// Moduly: text-diff, asset-check (404), link-check, console/JS errors, forms, structure.
const { chromium } = require('/home/maj/maj-hrbitov/node_modules/playwright');

const PAGES = ['','nasi-psi/','nase-feny/','nase-uspechy/','nase-vrhy/','o-nas-chovatelska-stanice/','vzpominame/','kontakt/','aktuality/','galerie/','vrhy/'];

function norm(t){return (t||'').replace(/\s+/g,' ').replace(/[ ]/g,' ').trim().toLowerCase();}

async function scan(browser, base){
  const out={};
  for(const pg of PAGES){
    const name=pg.replace(/\//g,'')||'home';
    const page=await browser.newPage({viewport:{width:1440,height:1200}});
    const consoleErrors=[], failedAssets=[];
    page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text().slice(0,120));});
    page.on('requestfailed',r=>failedAssets.push(r.url().split('?')[0]));
    page.on('response',r=>{if(r.status()>=400)failedAssets.push(r.status()+' '+r.url().split('?')[0]);});
    try{
      await page.goto(base+pg,{waitUntil:'networkidle',timeout:45000});
      await page.evaluate(async()=>{await new Promise(r=>{let y=0;const t=setInterval(()=>{scrollBy(0,800);y+=800;if(y>=document.body.scrollHeight){clearInterval(t);r();}},60);});});
      await page.waitForTimeout(1500);
      const data=await page.evaluate(()=>{
        const text=document.body.innerText;
        const links=[...document.querySelectorAll('a[href]')].map(a=>a.href).filter(h=>h.startsWith('http'));
        const imgs=[...document.querySelectorAll('img')].map(i=>({src:i.currentSrc||i.src, w:i.naturalWidth, h:i.naturalHeight}));
        const h=[...document.querySelectorAll('h1,h2,h3')].map(e=>e.textContent.trim()).filter(Boolean);
        const forms=document.querySelectorAll('form').length;
        const wpcf7=document.querySelectorAll('.wpcf7, form.wpcf7-form').length;
        return {textLen:text.length, text, links, imgCount:imgs.length, brokenImgs:imgs.filter(i=>i.w===0).length, headings:h, forms, wpcf7, words:text.split(/\s+/).filter(Boolean).length};
      });
      out[name]={...data, consoleErrors, failedAssets:[...new Set(failedAssets)]};
    }catch(e){out[name]={error:String(e).slice(0,80)};}
    await page.close();
  }
  return out;
}

(async()=>{
  const base1=process.argv[2], base2=process.argv[3];
  const b=await chromium.launch({channel:'chrome',args:['--no-sandbox','--disable-gpu']});
  const A=await scan(b,base1), B=await scan(b,base2);
  await b.close();
  console.log('PAGE | textWords A/B | headings A/B | forms A/B | brokenImg A/B | consoleErr A/B | 404 A/B');
  for(const n of Object.keys(A)){
    const a=A[n],b2=B[n];
    if(a.error||b2.error){console.log(`${n}: ERR a=${a.error||'ok'} b=${b2.error||'ok'}`);continue;}
    const hMatch = JSON.stringify(a.headings.map(norm))===JSON.stringify(b2.headings.map(norm))?'=':'≠';
    console.log(`${n.padEnd(22)} | ${a.words}/${b2.words} | ${a.headings.length}/${b2.headings.length}${hMatch} | ${a.forms}/${b2.forms} | ${a.brokenImgs}/${b2.brokenImgs} | ${a.consoleErrors.length}/${b2.consoleErrors.length} | ${a.failedAssets.length}/${b2.failedAssets.length}`);
  }
  require('fs').writeFileSync('/tmp/verify/result.json',JSON.stringify({A,B},null,1));
})();
