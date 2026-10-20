<?php
declare(strict_types=1);

/**
 * Inline SVG illustrations (no image files, no external requests).
 * Colours use the site palette. $p is a unique id prefix so the same
 * illustration can appear more than once on a page.
 */

/** The boarding house: tin roof, wooden wall, door, windows and plants. */
function art_house(string $p = 'h', string $label = 'Illustration of the boarding house with a tin roof, a wooden door and green plants'): string
{
    return <<<SVG
<svg class="art art-house" viewBox="0 0 520 540" preserveAspectRatio="xMidYMid slice" role="img" aria-label="{$label}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="{$p}-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#bfe0ea"/><stop offset="1" stop-color="#f4f7ec"/></linearGradient>
    <linearGradient id="{$p}-roof" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5a6c78"/><stop offset="1" stop-color="#3b4a55"/></linearGradient>
    <linearGradient id="{$p}-grass" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8cc08f"/><stop offset="1" stop-color="#5b9a6c"/></linearGradient>
  </defs>
  <rect width="520" height="540" fill="url(#{$p}-sky)"/>
  <g class="art-sun" transform="translate(410 105)">
    <g class="art-rays" stroke="#f1b955" stroke-width="5" stroke-linecap="round">
      <path d="M0-72v-16M0 72v16M-72 0h-16M72 0h16M51-51l11-11M-51 51l-11 11M51 51l11 11M-51-51l-11-11"/>
    </g>
    <circle r="46" fill="#f6c870"/><circle r="46" fill="#fff" opacity=".18" cx="-10" cy="-10"/>
  </g>
  <g class="art-cloud art-cloud-1" fill="#fff" opacity=".92"><ellipse cx="110" cy="96" rx="46" ry="18"/><ellipse cx="140" cy="84" rx="30" ry="20"/><ellipse cx="84" cy="88" rx="26" ry="16"/></g>
  <g class="art-cloud art-cloud-2" fill="#fff" opacity=".85"><ellipse cx="300" cy="52" rx="38" ry="14"/><ellipse cx="322" cy="44" rx="24" ry="15"/><ellipse cx="280" cy="46" rx="20" ry="12"/></g>
  <ellipse cx="90" cy="430" rx="230" ry="80" fill="#a9d1a6"/>
  <ellipse cx="460" cy="440" rx="220" ry="70" fill="#9bc99a"/>
  <rect y="420" width="520" height="120" fill="url(#{$p}-grass)"/>
  <path d="M215 540c8-40 14-84 20-112h60c6 28 12 72 20 112z" fill="#e5d3a6"/>

  <g class="art-tree"><rect x="448" y="330" width="14" height="96" rx="6" fill="#7b5a37"/>
    <circle cx="455" cy="320" r="44" fill="#3f8a5f"/><circle cx="430" cy="345" r="30" fill="#4a9a6b"/><circle cx="482" cy="346" r="28" fill="#357a54"/></g>

  <rect x="100" y="250" width="320" height="180" rx="4" fill="#dcc690"/>
  <g stroke="#bfa56c" stroke-width="2" opacity=".75"><path d="M100 282h320M100 314h320M100 346h320M100 378h320M100 410h320"/></g>
  <rect x="100" y="418" width="320" height="12" fill="#8d7443"/>

  <g class="art-roof">
    <path d="M70 262 260 132 450 262z" fill="url(#{$p}-roof)"/>
    <g stroke="#7d909c" stroke-width="3" opacity=".6"><path d="M150 232 154 262M190 205 198 262M230 176 242 262M270 176 278 262M310 205 322 262M350 232 366 262"/></g>
    <path d="M62 266h396" stroke="#2c3841" stroke-width="10" stroke-linecap="round"/>
    <circle cx="260" cy="212" r="17" fill="#e9e2c8" stroke="#2c3841" stroke-width="4"/>
    <path d="M260 200v24M248 212h24" stroke="#8d7443" stroke-width="3"/>
  </g>

  <g class="art-window"><rect x="124" y="296" width="82" height="70" rx="6" fill="#fff"/><rect x="132" y="304" width="66" height="54" rx="3" class="art-glass" fill="#a9d4e4"/>
    <path d="M165 304v54M132 331h66" stroke="#fff" stroke-width="4"/><path d="M138 352 150 312" stroke="#fff" stroke-width="3" opacity=".6"/></g>
  <g class="art-window"><rect x="314" y="296" width="82" height="70" rx="6" fill="#fff"/><rect x="322" y="304" width="66" height="54" rx="3" class="art-glass" fill="#a9d4e4"/>
    <path d="M355 304v54M322 331h66" stroke="#fff" stroke-width="4"/><path d="M328 352 340 312" stroke="#fff" stroke-width="3" opacity=".6"/></g>

  <rect x="224" y="318" width="76" height="112" rx="6" fill="#f3ead2"/>
  <rect x="230" y="324" width="64" height="106" rx="3" fill="#5a3c1f"/>
  <g class="art-door"><rect x="230" y="324" width="64" height="106" rx="3" fill="#c4862a"/>
    <path d="M262 324v106" stroke="#a66f1c" stroke-width="2"/><rect x="238" y="336" width="18" height="30" rx="2" fill="#d79a3c"/><rect x="268" y="336" width="18" height="30" rx="2" fill="#d79a3c"/>
    <circle cx="284" cy="382" r="4.5" fill="#fff3cf"/></g>
  <g transform="translate(262 308)"><rect x="-26" y="-12" width="52" height="18" rx="4" fill="#2b6a4d"/>
    <path d="M-9 1v-7l9-6 9 6v7z" fill="#fff"/></g>

  <g class="art-plant art-plant-l" transform="translate(172 430)"><path d="M-18 0h36l-6 30h-24z" fill="#b5653a"/>
    <path class="art-leaf" d="M0 0C-26-18-30-44-8-60 4-44 6-20 0 0z" fill="#4a9a6b"/><path class="art-leaf art-leaf-2" d="M0 0C20-14 38-34 24-54 6-44-4-22 0 0z" fill="#357a54"/><path class="art-leaf" d="M0 0C-10-28-2-52 10-66 18-44 12-20 0 0z" fill="#5fae7d"/></g>
  <g class="art-plant art-plant-r" transform="translate(352 430)"><path d="M-18 0h36l-6 30h-24z" fill="#b5653a"/>
    <path class="art-leaf" d="M0 0C26-18 30-44 8-60-4-44-6-20 0 0z" fill="#357a54"/><path class="art-leaf art-leaf-2" d="M0 0C-20-14-38-34-24-54-6-44 4-22 0 0z" fill="#4a9a6b"/><path class="art-leaf" d="M0 0C10-28 2-52-10-66-18-44-12-20 0 0z" fill="#5fae7d"/></g>
  <g fill="#e9a23b"><circle cx="150" cy="468" r="4"/><circle cx="388" cy="472" r="4"/><circle cx="70" cy="490" r="4"/></g>
</svg>
SVG;
}

/** A shared room with a bunk bed, a window and a lamp. */
function art_room(string $p = 'r', string $label = 'Illustration of a shared room with a wooden bunk bed and a window'): string
{
    return <<<SVG
<svg class="art art-room" viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="{$label}" xmlns="http://www.w3.org/2000/svg">
  <defs><linearGradient id="{$p}-beam" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff6d6" stop-opacity=".75"/><stop offset="1" stop-color="#fff6d6" stop-opacity="0"/></linearGradient></defs>
  <rect width="400" height="300" fill="#eaf0e8"/>
  <rect y="226" width="400" height="74" fill="#c9a66b"/>
  <path d="M0 226h400" stroke="#a98750" stroke-width="4"/>
  <path d="M0 250h400M0 274h400" stroke="#b89559" stroke-width="2"/>
  <g class="art-roomwin"><rect x="248" y="36" width="116" height="104" rx="6" fill="#fff"/><rect x="256" y="44" width="100" height="88" rx="3" fill="#b4dbe8"/>
    <path d="M306 44v88M256 88h100" stroke="#fff" stroke-width="5"/><circle cx="330" cy="66" r="11" fill="#f6c870"/>
    <path d="M248 36c14 24 12 62 0 104" fill="none" stroke="#2b6a4d" stroke-width="12" stroke-linecap="round" opacity=".85"/></g>
  <path class="art-beam" d="M256 132h100l34 96H214z" fill="url(#{$p}-beam)"/>
  <g class="art-bunk">
    <rect x="34" y="72" width="10" height="158" rx="3" fill="#8a5b2b"/><rect x="186" y="72" width="10" height="158" rx="3" fill="#8a5b2b"/>
    <rect x="30" y="196" width="170" height="14" rx="4" fill="#a8703a"/>
    <rect x="40" y="170" width="150" height="28" rx="8" fill="#e0a23e"/><rect x="48" y="160" width="40" height="16" rx="8" fill="#fff"/>
    <rect x="30" y="118" width="170" height="14" rx="4" fill="#a8703a"/>
    <rect x="40" y="92" width="150" height="28" rx="8" fill="#2b6a4d"/><rect x="48" y="82" width="40" height="16" rx="8" fill="#fff"/>
    <path d="M176 118v-44M176 100h20" stroke="#8a5b2b" stroke-width="4" stroke-linecap="round"/>
    <g stroke="#8a5b2b" stroke-width="5" stroke-linecap="round"><path d="M210 90v140M236 90v140"/><path d="M210 112h26M210 140h26M210 168h26M210 196h26"/></g>
  </g>
  <ellipse cx="120" cy="236" rx="96" ry="9" fill="#000" opacity=".1"/>
  <g class="art-lamp" transform="translate(300 184)"><path d="M0 0v-30" stroke="#8d7443" stroke-width="3"/><path d="M-18 0h36l-8-26h-20z" fill="#f2cf80"/></g>
  <g transform="translate(300 226)"><rect x="-26" y="-40" width="52" height="40" rx="4" fill="#a8703a"/><rect x="-26" y="-40" width="52" height="8" rx="3" fill="#8a5b2b"/><circle cx="0" cy="-16" r="3" fill="#fff3cf"/></g>
  <g transform="translate(342 226)"><path d="M-14 0h28l-4-22h-20z" fill="#b5653a"/><path d="M0-22c-14-8-16-24-6-32 8 8 12 18 6 32zM0-22c12-6 20-18 14-30-10 4-18 14-14 30z" fill="#4a9a6b"/></g>
</svg>
SVG;
}

/**
 * A friendly host who waves hello. Eyes follow the pointer, and a tap or
 * click changes the greeting (see site.js, [data-welcome]).
 */
function art_welcome(string $p = 'w'): string
{
    return <<<SVG
<svg class="art art-welcome" viewBox="0 0 400 440" role="img" aria-label="A friendly host waving to welcome you" xmlns="http://www.w3.org/2000/svg">
  <ellipse class="aw-halo" cx="200" cy="260" rx="170" ry="170" fill="#e6efe9"/>
  <g transform="translate(296 336) scale(.8)"><g class="aw-house"><path d="M-8 -26 48 -74 104 -26" fill="#4a5a66"/><rect x="2" y="-26" width="92" height="76" fill="#dcc690"/><rect x="38" y="-4" width="20" height="54" rx="2" fill="#c4862a"/><rect x="12" y="-14" width="16" height="16" rx="2" fill="#a9d4e4"/><rect x="68" y="-14" width="16" height="16" rx="2" fill="#a9d4e4"/></g></g>
  <ellipse cx="200" cy="420" rx="92" ry="12" fill="#000" opacity=".12"/>
  <g class="aw-person">
    <rect x="160" y="340" width="34" height="78" rx="12" fill="#22303a"/><rect x="206" y="340" width="34" height="78" rx="12" fill="#22303a"/>
    <rect x="152" y="408" width="50" height="14" rx="7" fill="#14211b"/><rect x="198" y="408" width="50" height="14" rx="7" fill="#14211b"/>
    <g class="aw-arm-l"><rect x="116" y="262" width="34" height="96" rx="17" fill="#2b6a4d" transform="rotate(8 133 270)"/><circle cx="127" cy="362" r="17" fill="#f1c9a5"/></g>
    <rect x="138" y="250" width="124" height="120" rx="34" fill="#2b6a4d"/>
    <path d="M176 250c6 22 42 22 48 0z" fill="#1f5a43"/>
    <rect x="184" y="228" width="32" height="34" rx="12" fill="#e7b88f"/>
    <g class="aw-arm-r"><g transform="rotate(24 262 272)"><rect x="248" y="150" width="34" height="126" rx="17" fill="#2b6a4d"/><g class="aw-hand"><circle cx="265" cy="140" r="20" fill="#f1c9a5"/><path d="M253 124l-6-16M265 120l-2-18M277 124l5-15" stroke="#f1c9a5" stroke-width="8" stroke-linecap="round"/></g></g></g>
    <g class="aw-head">
      <circle cx="200" cy="184" r="62" fill="#f1c9a5"/>
      <path d="M136 182c-4-52 34-76 68-72 36 2 62 28 58 72-10-26-30-38-56-38-34 0-56 14-70 38z" fill="#2b2118"/>
      <circle cx="138" cy="190" r="10" fill="#f1c9a5"/><circle cx="262" cy="190" r="10" fill="#f1c9a5"/>
      <g class="aw-eyes"><circle cx="177" cy="188" r="12" fill="#fff"/><circle cx="223" cy="188" r="12" fill="#fff"/>
        <circle class="aw-pupil" cx="177" cy="189" r="6" fill="#22303a"/><circle class="aw-pupil" cx="223" cy="189" r="6" fill="#22303a"/></g>
      <circle cx="160" cy="212" r="9" fill="#e98f7a" opacity=".45"/><circle cx="240" cy="212" r="9" fill="#e98f7a" opacity=".45"/>
      <path class="aw-smile" d="M178 212q22 24 44 0" fill="none" stroke="#7a3b2a" stroke-width="5" stroke-linecap="round"/>
    </g>
  </g>
  <g class="aw-bubble" transform="translate(12 30)">
    <path d="M10 0h150a14 14 0 0 1 14 14v46a14 14 0 0 1-14 14H80l-22 22V74H10A14 14 0 0 1-4 60V14A14 14 0 0 1 10 0z" fill="#fff" stroke="#cfd9d2" stroke-width="2"/>
    <text class="aw-text" x="85" y="44" text-anchor="middle" font-family="Fraunces, Georgia, serif" font-size="22" font-weight="600" fill="#12372a">Welcome!</text>
  </g>
</svg>
SVG;
}
