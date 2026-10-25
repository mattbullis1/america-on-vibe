// Generates original AOV channel buttons. No third-party artwork is embedded.
const fs = require('node:fs');
const path = require('node:path');

const channels = [
  ['search', 'Search', '#275f9d', '#75c9e8', '⌕'],
  ['aoltoday', 'AOV Today', '#71266b', '#f4a344', '✦'],
  ['news', 'News', '#123b78', '#a5cef1', '▤'],
  ['sports', 'Sports', '#721b36', '#f07344', '●'],
  ['influence', 'Influence', '#273e63', '#80aec9', '✧'],
  ['travel', 'Travel', '#126270', '#e0bc67', '✈'],
  ['international', 'International', '#0b5975', '#73bdd3', '◎'],
  ['personalfinance', 'Personal Finance', '#184c57', '#c1d8a2', '$'],
  ['workplace', 'Workplace', '#433162', '#a4a5d5', '▣'],
  ['computing', 'Computing', '#284e31', '#a9c86b', '▦'],
  ['researchandlearn', 'Research & Learn', '#4b326d', '#dda8b5', '✎'],
  ['entertainment', 'Entertainment', '#602361', '#d886d2', '★'],
  ['games', 'Games', '#814431', '#efc476', '◆'],
  ['interests', 'Interests', '#125c7e', '#91d0e4', '✳'],
  ['lifestyles', 'Lifestyles', '#584b72', '#c5badc', '❖'],
  ['shopping', 'Shopping', '#306481', '#9addd1', '◇'],
  ['health', 'Health', '#2f713c', '#d3db71', '✚'],
  ['families', 'Families', '#7b6840', '#ead495', '♡'],
  ['kidsonly', 'Kids Only', '#1342a3', '#72b6f2', '★'],
  ['local', 'Local', '#286883', '#eac577', '⌂'],
  ['music', 'Music', '#44309c', '#ec8cda', '♫'],
  ['digital-cities', 'Digital Cities', '#134d9a', '#71d7ed', '▥']
];

const escape = value => String(value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[ch]);
const outDir = path.resolve(__dirname, '../assets/channels');
for (const [id, label, dark, light, glyph] of channels) {
  const size = label.length > 17 ? 25 : label.length > 13 ? 29 : label.length > 10 ? 32 : 39;
  const textX = label.length > 13 ? 20 : 23;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="324" height="64" viewBox="0 0 324 64" role="img" aria-label="${escape(label)}">
<defs>
  <linearGradient id="sky" x2="0" y2="1"><stop stop-color="${light}"/><stop offset=".48" stop-color="${dark}"/><stop offset="1" stop-color="#101b40"/></linearGradient>
  <linearGradient id="shine" x2="0" y2="1"><stop stop-color="#fff" stop-opacity=".75"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
  <pattern id="dots" width="8" height="8" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r=".7" fill="#fff" opacity=".28"/></pattern>
  <filter id="shadow"><feDropShadow dx="1" dy="2" stdDeviation="1" flood-color="#071126" flood-opacity=".85"/></filter>
</defs>
<rect x="1" y="1" width="322" height="62" rx="2" fill="#0a1230" stroke="#0a132b" stroke-width="2"/>
<rect x="5" y="5" width="314" height="54" fill="url(#sky)" stroke="#e9f6ff" stroke-width="2"/>
<rect x="7" y="7" width="310" height="25" fill="url(#shine)" opacity=".75"/>
<rect x="7" y="7" width="310" height="50" fill="url(#dots)"/>
<path d="M196 54 C229 18 270 12 314 16" fill="none" stroke="#fff" stroke-opacity=".36" stroke-width="2"/>
<path d="M213 56 C244 32 278 26 315 29" fill="none" stroke="#ffe38b" stroke-opacity=".48" stroke-width="3"/>
<circle cx="273" cy="31" r="25" fill="#fff" opacity=".08"/><circle cx="273" cy="31" r="16" fill="#fff" opacity=".1"/>
<text x="273" y="45" text-anchor="middle" font-family="Trebuchet MS,Arial,sans-serif" font-size="32" font-weight="bold" fill="#fff" opacity=".8">${escape(glyph)}</text>
<text x="${textX}" y="43" font-family="Trebuchet MS,Arial,sans-serif" font-size="${size}" font-weight="bold" font-style="italic" letter-spacing="-.8" fill="#fff" stroke="#122044" stroke-width="2.6" paint-order="stroke" filter="url(#shadow)">${escape(label)}</text>
<path d="M8 55 H316" stroke="#fff" stroke-opacity=".45"/>
</svg>`;
  fs.writeFileSync(path.join(outDir, `${id}.svg`), svg);
}
console.log(`Generated ${channels.length} original AOV channel buttons.`);
