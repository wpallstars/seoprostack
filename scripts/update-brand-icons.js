#!/usr/bin/env node
/**
 * Refresh assets/brand-icons/ from the newest Simple Icons and Font Awesome
 * Free releases on npm. Run through scripts/update-brand-icons.sh.
 *
 * Writes:
 *   assets/brand-icons/{slug}.svg  one minimal SVG per icon (viewBox + paths)
 *   assets/brand-icons/index.json  [slug, title, hex, source] for every icon,
 *                                  aliases (old or other names => slug) and
 *                                  the source versions
 *
 * Simple Icons (CC0) comes first. Font Awesome Free brand icons (CC BY 4.0)
 * are added only where Simple Icons has no icon of that name, mostly brands
 * whose owners asked Simple Icons to remove them (LinkedIn, Microsoft, Slack).
 * Their SVGs keep Font Awesome's attribution comment.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'assets', 'brand-icons');

/*
 * Names that changed since the Simple Icons set bundled with Popular Brand
 * Icons – Simple Icons 2.8.4 (2022), so [simple_icon name="…"] keeps working.
 * Keys are slugs as the plugin makes them (see SEOProStack_Brand_Icons::slug()).
 */
const ALIASES = {
	deldoticiodotus: 'delicious',
	d3dotjs: 'd3',
	dataversioncontrol: 'dvc',
	fite: 'trillertv',
	materialui: 'mui',
	minetest: 'luanti',
	nuxtdotjs: 'nuxt',
	rstudio: 'posit',
	sonarcloud: 'sonarqubecloud',
	sonarlint: 'sonarqubeforide',
	sonarqube: 'sonarqubeserver',
	tutanota: 'tuta',
	// Font Awesome names for brands Simple Icons removed.
	amazonaws: 'aws',
	microsoftedge: 'edge',
	tencentqq: 'qq',
	pocket: 'getpocket',
};

/* Brand colours for Font Awesome icons, from Simple Icons' last copies. */
const FA_HEX = {
	amazon: 'FF9900', amazonpay: 'FF9900', angellist: '000000', aws: '232F3E', codepen: '000000',
	css3: '1572B6', delicious: '0000FF', edge: '0078D7', ello: '000000', getpocket: 'EF3F56',
	ideal: 'CC0066', internetexplorer: '0076D6', invision: 'FF3366', java: '007396', linkedin: '0A66C2',
	linode: '00A95C', magento: 'EE672F', microsoft: '5E5E5E', openai: '412991', periscope: '40A4C4',
	qq: 'EB1923', salesforce: '00A1E0', scribd: '1E7B85', skype: '00AFF0', slack: '4A154B',
	stackpath: '000000', tencentweibo: '20B8E5', twitter: '1DA1F2', vine: '11B48A', visualstudio: '5C2D91',
	w3c: '005A9C', windows: '0078D6', xbox: '107C10', yahoo: '6001D2', yammer: '106EBE', yandex: 'FF0000',
};

function slugify(name) {
	return String(name).toLowerCase().replace(/\+/g, 'plus').replace(/\./g, 'dot').replace(/&/g, 'and')
		.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '');
}

async function fetchPackage(name, dir) {
	const res = await fetch('https://registry.npmjs.org/' + name.replace('/', '%2F') + '/latest');
	if (!res.ok) {
		throw new Error(name + ': registry said ' + res.status);
	}
	const meta = await res.json();
	const tgz = path.join(dir, slugify(name) + '.tgz');
	const body = await fetch(meta.dist.tarball);
	if (!body.ok) {
		throw new Error(name + ': download said ' + body.status);
	}
	fs.writeFileSync(tgz, Buffer.from(await body.arrayBuffer()));
	const into = path.join(dir, slugify(name));
	fs.mkdirSync(into);
	execFileSync('tar', ['-xzf', tgz, '-C', into]);
	return { version: meta.version, dir: path.join(into, 'package') };
}

/* viewBox and path data only: no title, styles or anything else. */
function minimal(svg, comment) {
	const box = /viewBox="([0-9.\s-]+)"/.exec(svg);
	const paths = [];
	const re = /<path\b[^>]*\sd="([^"]+)"/g;
	let m;
	while ((m = re.exec(svg))) {
		paths.push(m[1]);
	}
	if (!box || !paths.length) {
		return null;
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' + box[1].trim() + '">' + (comment || '')
		+ paths.map((d) => '<path d="' + d + '"/>').join('') + '</svg>\n';
}

async function main() {
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'brand-icons-'));
	try {
		const si = await fetchPackage('simple-icons', tmp);
		const fa = await fetchPackage('@fortawesome/fontawesome-free', tmp);

		fs.mkdirSync(OUT, { recursive: true });
		fs.readdirSync(OUT).filter((f) => f.endsWith('.svg')).forEach((f) => fs.unlinkSync(path.join(OUT, f)));

		const icons = [];
		const aliases = {};
		const data = JSON.parse(fs.readFileSync(path.join(si.dir, 'data', 'simple-icons.json'), 'utf8'));
		(Array.isArray(data) ? data : data.icons).forEach((icon) => {
			const slug = icon.slug || slugify(icon.title);
			const svg = minimal(fs.readFileSync(path.join(si.dir, 'icons', slug + '.svg'), 'utf8'));
			// Brands sharing a name have slugs such as hive_blockchain.
			if (!svg || !/^[a-z0-9_]+$/.test(slug)) {
				return;
			}
			fs.writeFileSync(path.join(OUT, slug + '.svg'), svg);
			icons.push([slug, icon.title, icon.hex || '', 's']);
			const names = [].concat(
				(icon.aliases && icon.aliases.aka) || [],
				Object.values((icon.aliases && icon.aliases.loc) || {}),
				Object.values((icon.aliases && icon.aliases.dup) || {}).map((d) => d.title)
			);
			names.forEach((n) => {
				const a = slugify(n);
				if (a && a !== slug) {
					aliases[a] = aliases[a] || slug;
				}
			});
		});
		const have = new Set(icons.map((i) => i[0]));

		const families = JSON.parse(fs.readFileSync(path.join(fa.dir, 'metadata', 'icon-families.json'), 'utf8'));
		const brands = path.join(fa.dir, 'svgs', 'brands');
		fs.readdirSync(brands).filter((f) => f.endsWith('.svg')).forEach((file) => {
			const name = file.replace(/\.svg$/, '');
			const slug = slugify(name);
			const label = families[name] && families[name].label ? families[name].label : name;
			// Already in Simple Icons under this name, its title or an alias.
			if ([slug, slugify(label)].some((s) => have.has(s) || aliases[s])) {
				return;
			}
			const raw = fs.readFileSync(path.join(brands, file), 'utf8');
			const note = /<!--[\s\S]*?-->/.exec(raw);
			const svg = minimal(raw, note ? note[0] : '');
			if (!svg) {
				return;
			}
			fs.writeFileSync(path.join(OUT, slug + '.svg'), svg);
			icons.push([slug, label, FA_HEX[slug] || '', 'f']);
			have.add(slug);
		});

		Object.keys(ALIASES).forEach((from) => {
			if (have.has(ALIASES[from]) && !have.has(from)) {
				aliases[from] = ALIASES[from];
			}
		});
		Object.keys(aliases).forEach((from) => {
			if (have.has(from)) {
				delete aliases[from];
			}
		});

		icons.sort((a, b) => a[0].localeCompare(b[0]));
		const index = {
			sources: { 'simple-icons': si.version, 'fontawesome-free': fa.version },
			icons: icons,
			aliases: aliases,
		};
		fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify(index) + '\n');
		const faCount = icons.filter((i) => 'f' === i[3]).length;
		console.log('Simple Icons ' + si.version + ': ' + (icons.length - faCount) + ' icons; Font Awesome Free ' + fa.version + ': ' + faCount + ' more; ' + Object.keys(aliases).length + ' aliases.');
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
}

main().catch((e) => {
	console.error('update-brand-icons: ' + e.message);
	process.exit(1);
});
