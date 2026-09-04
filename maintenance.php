<?php
http_response_code(503);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KIMC Eldoret — Maintenance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,340;9..144,480;9..144,600&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-950:#060a12;
    --navy-900:#0b1220;
    --navy-800:#121b2e;
    --navy-700:#1a2740;
    --amber-300:#ffd98a;
    --amber-400:#f5b84c;
    --ember-500:#ff5a36;
    --cream:#f6efe1;
    --slate:#93a3ba;
    --line:rgba(246,239,225,.13);
    --font-display:'Fraunces', Georgia, serif;
    --font-body:'Inter', Arial, Helvetica, sans-serif;
    --font-mono:'IBM Plex Mono', 'Courier New', monospace;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{
    font-family:var(--font-body);
    background:
      radial-gradient(1100px 700px at 82% 8%, rgba(245,184,76,.10), transparent 60%),
      linear-gradient(180deg, var(--navy-950) 0%, var(--navy-900) 55%, var(--navy-800) 100%);
    color:var(--cream);
    min-height:100vh;
    overflow-x:hidden;
  }

  /* ---------- ambient bokeh lights ---------- */
  .bokeh{position:fixed; inset:0; overflow:hidden; z-index:0; pointer-events:none;}
  .bokeh span{
    position:absolute; border-radius:50%;
    background:radial-gradient(circle, rgba(245,184,76,.55), rgba(245,184,76,0) 70%);
    filter:blur(1px);
    animation:rise linear infinite;
    opacity:0;
  }
  @keyframes rise{
    0%{ transform:translateY(0) scale(1); opacity:0; }
    10%{ opacity:.55; }
    90%{ opacity:.35; }
    100%{ transform:translateY(-70vh) scale(1.3); opacity:0; }
  }
  @media (prefers-reduced-motion: reduce){ .bokeh span{ animation:none; opacity:.25; } }

  /* ---------- layout ---------- */
  .wrap{ position:relative; z-index:1; max-width:1440px; margin:0 auto; padding:0 clamp(1.5rem,4vw,4rem); min-height:100vh; display:flex; flex-direction:column; }

  header{
    display:flex; align-items:center; justify-content:space-between;
    padding:1.8rem 0 1.2rem;
    border-bottom:1px solid var(--line);
  }
  .brand{ display:flex; align-items:center; gap:.6rem; font-family:var(--font-mono); font-size:.8rem; letter-spacing:.08em; color:var(--slate); text-transform:uppercase; }
  .brand strong{ color:var(--cream); font-weight:500; }
  .status-pill{
    display:inline-flex; align-items:center; gap:.55rem;
    font-family:var(--font-mono); font-size:.72rem; letter-spacing:.06em; text-transform:uppercase;
    color:var(--amber-300);
    padding:.4rem .8rem; border:1px solid var(--line); border-radius:999px;
    background:rgba(246,239,225,.03);
  }
  .status-dot{ width:7px; height:7px; border-radius:50%; background:var(--ember-500); box-shadow:0 0 0 0 rgba(255,90,54,.6); animation:pulse-dot 1.8s ease-out infinite; }
  @keyframes pulse-dot{
    0%{ box-shadow:0 0 0 0 rgba(255,90,54,.55); }
    70%{ box-shadow:0 0 0 9px rgba(255,90,54,0); }
    100%{ box-shadow:0 0 0 0 rgba(255,90,54,0); }
  }

  main{
    flex:1;
    display:flex;
    align-items:center;
    padding:3rem 0 2rem;
  }
  .copy{ position:relative; z-index:2; }

  .copy .eyebrow{
    font-family:var(--font-mono); font-size:.78rem; letter-spacing:.14em; text-transform:uppercase;
    color:var(--amber-400); margin:0 0 1.1rem;
  }
  .copy h1{
    font-family:var(--font-display);
    font-weight:480;
    font-size:clamp(2.6rem, 5vw, 4.4rem);
    line-height:1.04;
    letter-spacing:-.01em;
    margin:0 0 1.2rem;
    color:var(--cream);
    max-width:12ch;
  }
  .copy h1 em{ font-style:italic; color:var(--amber-300); }
  .copy p.lede{
    font-size:clamp(1.02rem,1.4vw,1.2rem);
    line-height:1.65;
    color:var(--slate);
    max-width:46ch;
    margin:0 0 2.2rem;
  }

  .checklist{ list-style:none; margin:0 0 2.4rem; padding:0; display:flex; flex-direction:column; gap:.65rem; }
  .checklist li{
    display:flex; align-items:center; gap:.75rem;
    font-family:var(--font-mono); font-size:.86rem; color:var(--slate);
    padding-bottom:.65rem; border-bottom:1px dashed var(--line);
    max-width:34rem;
  }
  .checklist li:last-child{ border-bottom:none; }
  .checklist .mark{
    width:18px; height:18px; border-radius:50%; flex:none;
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem;
  }
  .checklist li.done .mark{ background:rgba(245,184,76,.15); color:var(--amber-300); border:1px solid rgba(245,184,76,.4); }
  .checklist li.active .mark{ background:rgba(255,90,54,.12); color:var(--ember-500); border:1px solid rgba(255,90,54,.5); position:relative; }
  .checklist li.active .mark::after{
    content:''; position:absolute; inset:-4px; border-radius:50%; border:1px solid rgba(255,90,54,.4);
    animation:ring 1.6s ease-out infinite;
  }
  @keyframes ring{ 0%{ transform:scale(.7); opacity:.9;} 100%{ transform:scale(1.5); opacity:0;} }
  .checklist li.done{ color:var(--cream); }
  .checklist li span.label{ flex:1; }
  .checklist li span.tag{ color:var(--slate); font-size:.75rem; }

  .progress-track{
    max-width:34rem; height:3px; border-radius:3px; background:rgba(246,239,225,.08); overflow:hidden; margin-bottom:1.6rem; position:relative;
  }
  .progress-fill{
    position:absolute; top:0; bottom:0; width:38%;
    background:linear-gradient(90deg, transparent, var(--amber-400), var(--ember-500), transparent);
    animation:scan 2.6s ease-in-out infinite;
  }
  @keyframes scan{
    0%{ left:-40%; }
    100%{ left:100%; }
  }

  .footnote{ font-size:.85rem; color:var(--slate); }
  .footnote strong{ color:var(--cream); font-weight:500; }

  /* ---------- tower visual (full-bleed background) ---------- */
  .tower-stage{
    position:fixed; inset:0; z-index:0; pointer-events:none;
    display:flex; align-items:flex-end; justify-content:flex-end;
  }
  .glow{
    position:absolute; right:6%; bottom:10%; width:52%; height:66%;
    background:radial-gradient(closest-side, rgba(245,184,76,.20), transparent 72%);
    filter:blur(10px);
    z-index:0;
  }
  .tower{ position:relative; z-index:1; height:100vh; }
  .tower img{
    height:100%; width:auto; display:block; filter:contrast(1.05) saturate(.95);
    opacity:.62;
    -webkit-mask-image:linear-gradient(100deg, transparent 0%, black 32%);
    mask-image:linear-gradient(100deg, transparent 0%, black 32%);
  }
  .tower::after{
    content:'';
    position:absolute; left:0; right:0; bottom:0; height:38%;
    background:linear-gradient(180deg, transparent, var(--navy-900) 82%);
    z-index:2;
  }
  .tower::before{
    content:'';
    position:absolute; top:0; left:0; right:0; height:16%;
    background:linear-gradient(180deg, var(--navy-950), transparent);
    z-index:2;
  }

  .beacon{
    position:absolute; z-index:2;
    width:8px; height:8px; border-radius:50%;
    background:var(--ember-500);
    box-shadow:0 0 8px 2px rgba(255,90,54,.8);
    animation:beacon 2.2s ease-in-out infinite;
  }
  @keyframes beacon{
    0%,100%{ opacity:1; box-shadow:0 0 6px 2px rgba(255,90,54,.7); }
    50%{ opacity:.25; box-shadow:0 0 2px 0 rgba(255,90,54,.2); }
  }

  footer{
    display:flex; align-items:center; justify-content:space-between;
    padding:1.4rem 0 2rem;
    border-top:1px solid var(--line);
    font-family:var(--font-mono); font-size:.72rem; letter-spacing:.05em; color:var(--slate);
    text-transform:uppercase;
  }

  @media (max-width: 1100px){
    .tower-stage::after{
      content:'';
      position:absolute; inset:0;
      background:linear-gradient(180deg, rgba(6,10,18,.5), rgba(6,10,18,.8) 55%, var(--navy-900) 92%);
    }
    .tower img{ opacity:.42; }
    .beacon{ display:none; }
  }

  @media (max-width: 980px){
    main{ padding-top:2rem; }
    .checklist li, .progress-track{ max-width:none; }
    footer{ flex-direction:column; gap:.4rem; align-items:flex-start; }
  }
</style>
</head>
<body>

<div class="bokeh" aria-hidden="true">
  <span style="left:8%; width:60px; height:60px; animation-duration:14s; animation-delay:0s;"></span>
  <span style="left:22%; width:34px; height:34px; animation-duration:10s; animation-delay:-4s;"></span>
  <span style="left:68%; width:46px; height:46px; animation-duration:16s; animation-delay:-8s;"></span>
  <span style="left:80%; width:26px; height:26px; animation-duration:9s; animation-delay:-2s;"></span>
  <span style="left:45%; width:40px; height:40px; animation-duration:13s; animation-delay:-6s;"></span>
  <span style="left:58%; width:20px; height:20px; animation-duration:8s; animation-delay:-1s;"></span>
</div>

<div class="tower-stage" aria-hidden="true">
  <div class="glow"></div>
  <div class="tower">
    <img src="assets/img/kicc_amber_duotone.png" alt="">
    <div class="beacon" style="top:8.6%; left:49%;"></div>
  </div>
</div>

<div class="wrap">
  <header>
    <div class="brand"><strong>KIMC</strong>&nbsp;Eldoret</div>
    <div class="status-pill"><span class="status-dot"></span> System status: Maintenance</div>
  </header>

  <main>
    <div class="copy">
      <p class="eyebrow">Scheduled maintenance</p>
      <h1>We're tuning things <em>behind the scenes.</em></h1>
      <p class="lede">The site is briefly offline while we finish a round of upgrades. Nothing to worry about — we're almost done, and everything will be back the way you left it.</p>

      <ul class="checklist">
        <li class="done"><span class="mark">✓</span><span class="label">Systems check</span><span class="tag">complete</span></li>
        <li class="done"><span class="mark">✓</span><span class="label">Data backup</span><span class="tag">complete</span></li>
        <li class="active"><span class="mark">●</span><span class="label">Final polish</span><span class="tag">in progress</span></li>
      </ul>

      <div class="progress-track"><div class="progress-fill"></div></div>

      <p class="footnote">Expected back online <strong>shortly</strong>. Thanks for your patience.</p>
    </div>
  </main>

  <footer>
    <span>© KIMC Eldoret</span>
    <span>We'll be right back</span>
  </footer>
</div>

</body>
</html>