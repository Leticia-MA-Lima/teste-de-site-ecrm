const {chromium}=require(process.env.PLAYWRIGHT_PACKAGE||'playwright');
const http=require('node:http'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../analytics/public');
let mode='ok';
const data=()=>({status:'ok',generated_at:new Date().toISOString(),active_30m:5,active_5m:2,events_30m:{whatsapp_click:2,generate_lead:1},pages_30m:[{lp:'Geral',title:'Conheça',views:7}],cities_br:[{city:'<img src=x onerror=alert(1)>',active_users:3}],activity_30m:Array.from({length:30},(_,i)=>({minutes_ago:29-i,events:i%5})),daily:{status:'ok',date:new Intl.DateTimeFormat('en-CA',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()),generated_at:new Date().toISOString(),pages:[{lp:'Geral',views:9}],campaigns:[{source:'instagram',medium:'paid_social',campaign:'teste',sessions:4}]}});
const server=http.createServer((req,res)=>{
  if(req.url.startsWith('/api.php')){res.setHeader('Content-Type','application/json');res.statusCode=mode==='ok'?200:503;res.end(JSON.stringify(mode==='ok'?data():{status:'unavailable'}));return;}
  const file=path.resolve(root,'.'+new URL(req.url,'http://local').pathname.replace(/\/$/,'/index.html'));
  if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404).end();return;}
  res.setHeader('Content-Type',({'.html':'text/html','.css':'text/css','.js':'text/javascript','.geojson':'application/json','.png':'image/png'})[path.extname(file)]||'text/plain');fs.createReadStream(file).pipe(res);
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const url=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1920,height:1080}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install();await page.goto(url);await page.locator('#status').getByText('Dados atualizados',{exact:true}).waitFor();
  await page.waitForFunction(()=>document.querySelectorAll('#map path').length>0);
  assert.equal(await page.locator('#active30').innerText(),'5');assert.equal(await page.locator('#cities img').count(),0,'Texto externo não pode executar HTML');
  for(let i=0;i<3;i++){
   if(i)await page.clock.fastForward(45000);
   assert.equal(await page.locator(`[data-view="${i}"]`).isVisible(),true);
   for(const [width,height]of [[1920,1080],[1366,768]]){
    await page.setViewportSize({width,height});
    const overflow=await page.evaluate(()=>{const stage=document.querySelector('#stage');const footer=document.querySelector('footer').getBoundingClientRect();return document.documentElement.scrollHeight>innerHeight||footer.bottom>innerHeight||stage.scrollHeight>stage.clientHeight;});
    assert.equal(overflow,false,`Tela ${i} cabe em ${width}x${height}`);
    await page.screenshot({path:path.resolve(__dirname,`analytics-${i}-${width}.png`)});
   }
  }
  mode='fail';await page.clock.fastForward(30000);await page.locator('#status').getByText('Dados atrasados',{exact:true}).waitFor();assert.equal(await page.locator('#active30').innerText(),'5','Falha preserva última leitura');
  mode='ok';await page.clock.fastForward(30000);await page.locator('#status').getByText('Dados atualizados',{exact:true}).waitFor();
  mode='fail';await page.goto(url+'/?view=1');await page.locator('#status').getByText('Sem dados disponíveis',{exact:true}).waitFor();assert.equal(await page.locator('#active30').innerText(),'—','Falha inicial não inventa zero');
  await page.clock.fastForward(90000);assert.equal(await page.locator('[data-view="1"]').isVisible(),true,'Visão fixa não rotaciona');assert.deepEqual(errors,[]);
  console.log('OK: três telas, 1920x1080/1366x768, rotação, mapa local, XSS, falha inicial, cache e recuperação. Respostas simuladas.');
 }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
