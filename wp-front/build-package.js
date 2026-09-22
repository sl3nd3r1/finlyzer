#!/usr/bin/env node
/**
 * Finlyzer — Multi-Environment WordPress Package Builder (v1.28.0)
 *
 * Compiles and packages the Finlyzer plugin using environment-specific (.env) profiles:
 *
 * 1. Development Build (`npm run build:dev` or `node build-package.js --env=development`):
 *    - Ingests .env.development
 *    - Configures local / staging Worker API endpoints (e.g. http://127.0.0.1:8787)
 *    - Packages the interactive Developer Section & API Inspector for developer testing
 *    - Produces dist/development/finlyzer-dev.zip (and dist/finlyzer-dev.zip)
 *
 * 2. Production Build (`npm run build:prod` or `node build-package.js --env=production`):
 *    - Ingests .env.production
 *    - Enforces 2026 security protocols: HTTPS only, SSRF immunity (no loopback/private IPs)
 *    - Disables and strips developer tools / diagnostic panels
 *    - Bakes locked-down read-only production API endpoints into the package
 *    - Produces dist/production/finlyzer.zip (and dist/finlyzer.zip)
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const PLUGIN_ROOT = __dirname;
const DIST_DIR = path.resolve(PLUGIN_ROOT, 'dist');

// parse environment argument (--env=development | --env=production)
let targetEnv = 'production';
for (const arg of process.argv.slice(2)) {
	if (arg.startsWith('--env=')) {
		const val = arg.split('=')[1].toLowerCase().trim();
		if (val === 'dev' || val === 'development') {
			targetEnv = 'development';
		} else if (val === 'prod' || val === 'production') {
			targetEnv = 'production';
		}
	} else if (arg === '--dev' || arg === '-d') {
		targetEnv = 'development';
	} else if (arg === '--prod' || arg === '-p') {
		targetEnv = 'production';
	}
}

const isProd = targetEnv === 'production';
const envFileName = isProd ? '.env.production' : '.env.development';
const envFilePath = path.resolve(PLUGIN_ROOT, envFileName);

console.log('================================================================');
console.log(`📦 FINLYZER WORDPRESS PLUGIN PACKAGER — [${targetEnv.toUpperCase()} BUILD]`);
console.log('================================================================\n');

// 1. Ingest and parse environment file
console.log(`1. Ingesting environment configuration from ${envFileName}...`);
if (!fs.existsSync(envFilePath)) {
	console.error(`❌ Required environment file missing: ${envFileName}`);
	console.error(`   Please create ${envFileName} (or copy from ${envFileName}.example).`);
	process.exit(1);
}

function parseEnvFile(filePath) {
	const content = fs.readFileSync(filePath, 'utf8');
	const lines = content.split('\n');
	const env = {};
	for (const line of lines) {
		const trimmed = line.trim();
		if (!trimmed || trimmed.startsWith('#')) continue;
		const eqIdx = trimmed.indexOf('=');
		if (eqIdx > 0) {
			const k = trimmed.substring(0, eqIdx).trim();
			let v = trimmed.substring(eqIdx + 1).trim();
			if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
				v = v.substring(1, v.length - 1);
			}
			env[k] = v;
		}
	}
	return env;
}

const envVars = parseEnvFile(envFilePath);
console.log(`   ✓ Ingested ${Object.keys(envVars).length} environment variables.`);

// 2. Validate environment security invariants (2026 Standards)
console.log('\n2. Validating environment security constraints...');
const workerEndpoint = envVars.FINLYZER_WORKER_ENDPOINT || '';
const analyzeEndpoint = envVars.FINLYZER_WORKER_ANALYZE_ENDPOINT || '';
const workerHmacSecret = envVars.FINLYZER_WORKER_HMAC_SECRET || '';

if (isProd) {
	// Production security checks
	if (!workerEndpoint || !workerEndpoint.startsWith('https://')) {
		console.error(`❌ Production security violation: FINLYZER_WORKER_ENDPOINT must use HTTPS. Received: ${workerEndpoint}`);
		process.exit(1);
	}
	if (!analyzeEndpoint || !analyzeEndpoint.startsWith('https://')) {
		console.error(`❌ Production security violation: FINLYZER_WORKER_ANALYZE_ENDPOINT must use HTTPS. Received: ${analyzeEndpoint}`);
		process.exit(1);
	}

	// SSRF check on production endpoints
	const blockedPatterns = ['localhost', '127.0.0.1', '10.', '192.168.', '172.16.', '169.254.', '0.0.0.0', '::1'];
	for (const ep of [workerEndpoint, analyzeEndpoint]) {
		const hostMatch = ep.match(/^https:\/\/([^/:]+)/i);
		if (hostMatch) {
			const host = hostMatch[1].toLowerCase();
			for (const pattern of blockedPatterns) {
				if (host === pattern || host.startsWith(pattern) || host.endsWith('.localhost') || host.endsWith('.local')) {
					console.error(`❌ Production security violation: SSRF hazard detected. Endpoint ${ep} targets prohibited host ${host}.`);
					process.exit(1);
				}
			}
		}
	}

	if (envVars.FINLYZER_ENABLE_DEV_TOOLS === 'true') {
		console.error('❌ Production security violation: FINLYZER_ENABLE_DEV_TOOLS cannot be true in production build.');
		process.exit(1);
	}
	if (envVars.FINLYZER_ALLOW_HTTP === 'true') {
		console.error('❌ Production security violation: FINLYZER_ALLOW_HTTP cannot be true in production build.');
		process.exit(1);
	}

	console.log('   ✓ Production security verified: HTTPS enforced, SSRF guards passed, developer tools disabled.');
} else {
	// Development checks
	console.log('   ✓ Development build verified: Local endpoints and developer diagnostic inspector permitted.');
}

// 3. Verify version integrity across finlyzer.php, readme.txt, and package.json
console.log('\n3. Verifying version consistency...');
const pluginPhp = fs.readFileSync(path.resolve(PLUGIN_ROOT, 'finlyzer.php'), 'utf8');
const readmeTxt = fs.readFileSync(path.resolve(PLUGIN_ROOT, 'readme.txt'), 'utf8');
const packageJson = JSON.parse(fs.readFileSync(path.resolve(PLUGIN_ROOT, 'package.json'), 'utf8'));

const phpVersionMatch = pluginPhp.match(/define\('FINLYZER_VERSION',\s*'([^']+)'\);/);
const readmeVersionMatch = readmeTxt.match(/Stable tag:\s*([^\r\n]+)/);

if (!phpVersionMatch) {
	console.error('❌ Could not determine FINLYZER_VERSION in finlyzer.php');
	process.exit(1);
}
if (!readmeVersionMatch) {
	console.error('❌ Could not determine Stable tag in readme.txt');
	process.exit(1);
}

const phpVersion = phpVersionMatch[1];
const readmeVersion = readmeVersionMatch[1].trim();
const pkgVersion = packageJson.version;

if (phpVersion !== readmeVersion || phpVersion !== pkgVersion) {
	console.error(`❌ Version mismatch: finlyzer.php has ${phpVersion}, readme.txt has ${readmeVersion}, package.json has ${pkgVersion}`);
	process.exit(1);
}

console.log(`   ✓ Version verified: v${phpVersion}`);

// 4. Lint all production PHP files
console.log('\n4. Linting PHP production files...');
const phpFilesToLint = [
	'finlyzer.php',
	'uninstall.php',
	'includes/class-fxli-crypto.php',
	'includes/class-fxli-env.php',
	'includes/class-fxli-security.php',
	'includes/class-fxli-installer.php',
	'includes/class-fxli-order-analyzer.php',
	'includes/class-fxli-gemini-client.php',
	'includes/class-fxli-rest-api.php',
	'includes/class-fxli-admin-page.php',
	'templates/dashboard.php',
	'templates/partials/summary-cards.php',
	'templates/partials/insight-note.php',
	'templates/partials/developer-section.php',
	'templates/partials/settings-modal.php',
];

for (const relPath of phpFilesToLint) {
	const absPath = path.resolve(PLUGIN_ROOT, relPath);
	if (!fs.existsSync(absPath)) {
		console.error(`❌ Required file missing: ${relPath}`);
		process.exit(1);
	}
	try {
		execSync(`php -l "${absPath}"`, { stdio: 'pipe' });
	} catch (err) {
		console.error(`❌ Syntax error in ${relPath}`);
		process.exit(1);
	}
}
console.log(`   ✓ All ${phpFilesToLint.length} PHP files passed linting with 0 errors.`);

// 5. Prepare staging layout
const ENV_DIST_DIR = path.resolve(DIST_DIR, targetEnv);
const STAGING_DIR = path.resolve(ENV_DIST_DIR, 'finlyzer');
const OUTPUT_ZIP = path.resolve(ENV_DIST_DIR, isProd ? 'finlyzer.zip' : 'finlyzer-dev.zip');
const CANONICAL_ZIP = path.resolve(DIST_DIR, isProd ? 'finlyzer.zip' : 'finlyzer-dev.zip');

console.log(`\n5. Preparing ${targetEnv} staging layout in ${STAGING_DIR}...`);
if (fs.existsSync(ENV_DIST_DIR)) {
	fs.rmSync(ENV_DIST_DIR, { recursive: true, force: true });
}
fs.mkdirSync(STAGING_DIR, { recursive: true });

function copyRecursive(src, dest) {
	const stat = fs.statSync(src);
	if (stat.isDirectory()) {
		fs.mkdirSync(dest, { recursive: true });
		for (const child of fs.readdirSync(src)) {
			copyRecursive(path.join(src, child), path.join(dest, child));
		}
	} else {
		fs.copyFileSync(src, dest);
	}
}

// copy core files
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'finlyzer.php'), path.resolve(STAGING_DIR, 'finlyzer.php'));
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'uninstall.php'), path.resolve(STAGING_DIR, 'uninstall.php'));
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'readme.txt'), path.resolve(STAGING_DIR, 'readme.txt'));

// copy subdirectories
copyRecursive(path.resolve(PLUGIN_ROOT, 'includes'), path.resolve(STAGING_DIR, 'includes'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'templates'), path.resolve(STAGING_DIR, 'templates'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'assets'), path.resolve(STAGING_DIR, 'assets'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'languages'), path.resolve(STAGING_DIR, 'languages'));

// 6. Bake environment constants and handle environment assets
console.log('6. Baking environment configuration into distribution...');
const stagedEnvClass = path.resolve(STAGING_DIR, 'includes/class-fxli-env.php');
let envClassContent = fs.readFileSync(stagedEnvClass, 'utf8');

// append baked environment constants
const bakedBlock = `

// -------------------------------------------------------------
// BUILD-TIME BAKED CONFIGURATION (${targetEnv.toUpperCase()} PROFILE)
// -------------------------------------------------------------
if (!defined('FINLYZER_BAKED_ENV')) {
	define('FINLYZER_BAKED_ENV', '${targetEnv}');
}
if (!defined('FINLYZER_BAKED_WORKER_ENDPOINT')) {
	define('FINLYZER_BAKED_WORKER_ENDPOINT', '${workerEndpoint.replace(/'/g, "\\'")}');
}
if (!defined('FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT')) {
	define('FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT', '${analyzeEndpoint.replace(/'/g, "\\'")}');
}
if (!defined('FINLYZER_BAKED_WORKER_HMAC_SECRET') && '${workerHmacSecret}') {
	define('FINLYZER_BAKED_WORKER_HMAC_SECRET', '${workerHmacSecret.replace(/'/g, "\\'")}');
}
if (!defined('FINLYZER_ENABLE_DEV_TOOLS')) {
	define('FINLYZER_ENABLE_DEV_TOOLS', ${isProd ? 'false' : 'true'});
}
if (!defined('FINLYZER_STRICT_SSL')) {
	define('FINLYZER_STRICT_SSL', ${isProd ? 'true' : 'false'});
}
if (!defined('FINLYZER_FORCE_API_CALCULATION')) {
	define('FINLYZER_FORCE_API_CALCULATION', true);
}
`;

fs.writeFileSync(stagedEnvClass, envClassContent + bakedBlock, 'utf8');

// In production, neutralize developer-section partial to eliminate dead attack surface
if (isProd) {
	const stagedDevSection = path.resolve(STAGING_DIR, 'templates/partials/developer-section.php');
	if (fs.existsSync(stagedDevSection)) {
		fs.writeFileSync(
			stagedDevSection,
			'<?php declare(strict_types=1); if (!defined("ABSPATH")) exit; // Developer section is omitted from production builds.\n',
			'utf8'
		);
	}
	console.log('   ✓ Developer section neutralized in production package.');
}

console.log(`   ✓ Configuration successfully baked into staged package.`);

// 7. Generate zip archive with root `finlyzer/` entry
console.log(`\n7. Compiling ${path.basename(OUTPUT_ZIP)} archive...`);
try {
	execSync(`cd "${ENV_DIST_DIR}" && zip -r -9 "${OUTPUT_ZIP}" finlyzer/`, { stdio: 'pipe' });
	// copy to canonical root dist folder for convenient access
	fs.copyFileSync(OUTPUT_ZIP, CANONICAL_ZIP);
} catch (err) {
	console.error('❌ Failed to create zip archive:', err);
	process.exit(1);
}

const zipStat = fs.statSync(OUTPUT_ZIP);
const zipSizeKb = Math.round((zipStat.size / 1024) * 10) / 10;
console.log(`   ✓ Successfully created ${OUTPUT_ZIP} (${zipSizeKb} KB)`);
console.log(`   ✓ Canonical copy placed at ${CANONICAL_ZIP}`);

// 8. Inspect zip archive contents
console.log('\n8. Inspecting archive integrity...');
const zipContents = execSync(`unzip -l "${OUTPUT_ZIP}"`).toString();
const entries = zipContents.split('\n').filter((l) => l.includes('finlyzer/'));

console.log(`   Archive contains ${entries.length} items rooted in 'finlyzer/'.`);
console.log('\n================================================================');
console.log(`🚀 FINLYZER [${targetEnv.toUpperCase()}] IS READY FOR INSTALLATION!`);
console.log(`   Archive: ${OUTPUT_ZIP}`);
console.log(`   Target API: ${workerEndpoint || '(Configured via wp-config)'}`);
console.log('================================================================\n');
