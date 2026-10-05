'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const number = new Intl.NumberFormat('pt-BR');
  const time = new Intl.DateTimeFormat('pt-BR', { timeZone:'America/Sao_Paulo',hour:'2-digit',minute:'2-digit',second:'2-digit' });
  const fmtTime = value => value && Number.isFinite(Date.parse(value)) ? time.format(new Date(value)) : 'indisponível';
  const svgNS = 'http://www.w3.org/2000/svg';
  let last = null, fetching = false, view = 0;
  const fixedView = new URLSearchParams(location.search).get('view');
  const titles = ['Presença no Brasil','Jornada das landing pages','Resultados de hoje'];
  function showView(n) {
    view = n; document.querySelectorAll('.view').forEach(el => el.hidden = Number(el.dataset.view) !== n);
    $('title').textContent = titles[n]; $('source').textContent = n === 2 ? 'Google Analytics 4 · Hoje · dados processados' : 'Google Analytics 4 · Últimos 30 min';
  }
  function ranking(id, rows, label, value) {
    const root = $(id); root.replaceChildren();
    if (!rows || !rows.length) { const p = document.createElement('p'); p.textContent = rows ? 'Nenhuma atividade registrada nesta janela' : 'Sem dados disponíveis'; root.append(p); return; }
    [...rows].sort((a,b) => Number(value(b))-Number(value(a))).slice(0,5).forEach(row => {
      const line = document.createElement('div'); line.className = 'row';
      const title = document.createElement('span'); title.textContent = label(row);
      const total = document.createElement('strong'); total.textContent = number.format(value(row));
      line.append(title,total); root.append(line);
    });
  }
  function aggregate(rows, key, value) {
    if (!Array.isArray(rows)) return null;
    const groups = new Map(); rows.forEach(r => groups.set(r[key], (groups.get(r[key]) || 0) + Number(r[value])));
    return [...groups].map(([lp,views]) => ({lp,views}));
  }
  function chart(rows) {
    const root = $('activity'); root.replaceChildren(); if (!Array.isArray(rows)) return;
    const max = Math.max(1,...rows.map(r=>r.events));
    rows.forEach((r,i) => { const bar = document.createElementNS(svgNS,'rect'); const h= Math.max(0,Number(r.events))/max*270;
      Object.entries({x:i*26+8,y:290-h,width:18,height:h,rx:4,fill:'#3b8ecc'}).forEach(([k,v])=>bar.setAttribute(k,String(v)));
      const title=document.createElementNS(svgNS,'title'); title.textContent=`Há ${r.minutes_ago} min: ${r.events} eventos`;bar.append(title);root.append(bar);
    });
  }
  function render(data) {
    [['active30',data.active_30m],['active5',data.active_5m],['whatsapp',data.events_30m?.whatsapp_click],['leads',data.events_30m?.generate_lead]].forEach(([id,v])=>$(id).textContent=typeof v==='number'?number.format(v):'—');
    ranking('cities',data.cities_br,r=>r.city||'Cidade não identificada',r=>r.active_users);
    ranking('pages',aggregate(data.pages_30m,'lp','views'),r=>r.lp,r=>r.views);
    const daily=data.daily; const today=new Intl.DateTimeFormat('en-CA',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const validDaily=daily?.date===today;
    ranking('dailyPages',validDaily?aggregate(daily.pages,'lp','views'):null,r=>r.lp,r=>r.views);
    ranking('campaigns',validDaily?daily.campaigns:null,r=>`${r.source} / ${r.medium} · ${r.campaign==='(not set)'?'Sem campanha identificada':r.campaign}`,r=>r.sessions);
    $('dailyTime').textContent=validDaily?`${daily.status==='stale'?'Resumo diário atrasado':'Resumo diário processado'} · última coleta ${fmtTime(daily.generated_at)}`:'Resumo de hoje ainda indisponível';
    chart(data.activity_30m); $('updated').textContent=`Última coleta: ${fmtTime(data.generated_at)}`;
    $('status').textContent=data.status==='ok'?'Dados atualizados':data.status==='stale'?'Dados atrasados':'Sem dados disponíveis';
  }
  async function refresh() {
    if(fetching)return;fetching=true;
    const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const response=await fetch('./api.php',{cache:'no-store',credentials:'same-origin',signal:controller.signal});
      if(!response.ok)throw new Error('Indisponível');
      const data=await response.json();
      if(!['ok','stale'].includes(data.status)||!data.generated_at)throw new Error('Resposta inválida');
      render(data);last=data;
    }catch{ if(last)render({...last,status:'stale'}); else $('status').textContent='Sem dados disponíveis'; }
    finally{clearTimeout(timeout);fetching=false;}
  }
  async function loadMap(){
    try{
      const res=await fetch('./assets/brasil.geojson');if(!res.ok)throw new Error();const data=await res.json();
      const geometries=(data.features||[]).flatMap(f=>f.geometry.type==='MultiPolygon'?f.geometry.coordinates:f.geometry.type==='Polygon'?[f.geometry.coordinates]:[]);
      const project=([lon,lat])=>[lon*Math.PI/180,-Math.log(Math.tan(Math.PI/4+lat*Math.PI/360))];
      const points=geometries.flat(2).map(project);const xs=points.map(p=>p[0]),ys=points.map(p=>p[1]);
      const minX=Math.min(...xs),maxX=Math.max(...xs),minY=Math.min(...ys),maxY=Math.max(...ys),scale=Math.min(660/(maxX-minX),460/(maxY-minY));
      const ox=(700-(maxX-minX)*scale)/2,oy=(500-(maxY-minY)*scale)/2;
      geometries.forEach(polygon=>{const path=document.createElementNS(svgNS,'path');path.setAttribute('fill-rule','evenodd');path.setAttribute('d',polygon.map(ring=>ring.map((coord,i)=>{const [x,y]=project(coord);return `${i?'L':'M'}${(x-minX)*scale+ox},${(y-minY)*scale+oy}`;}).join(' ')+' Z').join(' '));$('map').append(path);});
    }catch{$('map').setAttribute('aria-label','Mapa indisponível');}
  }
  showView(/^[0-2]$/.test(fixedView||'')?Number(fixedView):0);
  if(!/^[0-2]$/.test(fixedView||''))setInterval(()=>showView((view+1)%3),45000);
  const offsets=[[0,0],[6,3],[-6,3],[3,-6],[-3,-3]];let shift=0;
  setInterval(()=>{const [x,y]=offsets[++shift%offsets.length];$('stage').style.transform=`translate(${x}px,${y}px)`;},60000);
  const tick=()=>{$('clock').textContent=time.format(new Date());};tick();setInterval(tick,1000);
  loadMap();refresh();setInterval(refresh,30000);
})();
