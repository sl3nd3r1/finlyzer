#!/usr/bin/env node
/**
 * Finlyzer — WordPress Production Package Builder
 *
 * Produces a clean, production-certified finlyzer.zip archive ready for direct installation
 * in WordPress (wp-admin/plugin-install.php > Upload Plugin).
 *
 * Excludes developer test suites, preview servers, and non-production files.
 */

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PLUGIN_ROOT = __dirname;
const DIST_DIR = path.resolve(PLUGIN_ROOT, 'dist');
const STAGING_DIR = path.resolve(DIST_DIR, 'finlyzer');
const OUTPUT_ZIP = path.resolve(DIST_DIR, 'finlyzer.zip');

console.log('================================================================');
console.log('📦 FINLYZER WORDPRESS PLUGIN PRODUCTION PACKAGER');
console.log('================================================================\n');

// 1. Verify version integrity between finlyzer.php and readme.txt
console.log('1. Verifying version consistency...');
const pluginPhp = fs.readFileSync(path.resolve(PLUGIN_ROOT, 'finlyzer.php'), 'utf8');
const readmeTxt = fs.readFileSync(path.resolve(PLUGIN_ROOT, 'readme.txt'), 'utf8');

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

if (phpVersion !== readmeVersion) {
	console.error(`❌ Version mismatch: finlyzer.php has ${phpVersion} but readme.txt has ${readmeVersion}`);
	process.exit(1);
}

console.log(`   ✓ Version verified: v${phpVersion}`);

// 2. Lint all production PHP files
console.log('\n2. Linting PHP production files...');
const phpFilesToLint = [
	'finlyzer.php',
	'uninstall.php',
	'includes/class-fxli-security.php',
	'includes/class-fxli-installer.php',
	'includes/class-fxli-order-analyzer.php',
	'includes/class-fxli-gemini-client.php',
	'includes/class-fxli-rest-api.php',
	'includes/class-fxli-admin-page.php',
	'templates/dashboard.php',
	'templates/partials/summary-cards.php',
	'templates/partials/insight-note.php',
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
console.log(`   ✓ All ${phpFilesToLint.length} PHP production files passed linting with 0 errors.`);

// 3. Prepare clean staging directory
console.log('\n3. Preparing staging directory...');
if (fs.existsSync(DIST_DIR)) {
	fs.rmSync(DIST_DIR, { recursive: true, force: true });
}
fs.mkdirSync(STAGING_DIR, { recursive: true });

// 4. Copy production assets & code into staging
console.log('4. Copying production files into finlyzer/ distribution layout...');

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

// Single root files
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'finlyzer.php'), path.resolve(STAGING_DIR, 'finlyzer.php'));
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'uninstall.php'), path.resolve(STAGING_DIR, 'uninstall.php'));
fs.copyFileSync(path.resolve(PLUGIN_ROOT, 'readme.txt'), path.resolve(STAGING_DIR, 'readme.txt'));

// Subdirectories
copyRecursive(path.resolve(PLUGIN_ROOT, 'includes'), path.resolve(STAGING_DIR, 'includes'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'templates'), path.resolve(STAGING_DIR, 'templates'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'assets'), path.resolve(STAGING_DIR, 'assets'));
copyRecursive(path.resolve(PLUGIN_ROOT, 'languages'), path.resolve(STAGING_DIR, 'languages'));

console.log('   ✓ Files staged in dist/finlyzer/');

// 5. Generate zip archive with root `finlyzer/` entry
console.log('\n5. Creating production finlyzer.zip archive...');
try {
	execSync(`cd "${DIST_DIR}" && zip -r -9 "${OUTPUT_ZIP}" finlyzer/`, { stdio: 'pipe' });
} catch (err) {
	console.error('❌ Failed to create zip archive:', err);
	process.exit(1);
}

const zipStat = fs.statSync(OUTPUT_ZIP);
const zipSizeKb = Math.round((zipStat.size / 1024) * 10) / 10;

console.log(`   ✓ Successfully created ${OUTPUT_ZIP} (${zipSizeKb} KB)`);

// 6. Inspect zip archive contents
console.log('\n6. Inspecting zip package structure...');
const zipContents = execSync(`unzip -l "${OUTPUT_ZIP}"`).toString();
const entries = zipContents.split('\n').filter((l) => l.includes('finlyzer/'));

console.log(`   Package contains ${entries.length} files/directories under root 'finlyzer/'.`);
console.log('\n================================================================');
console.log('🚀 FINLYZER IS READY FOR WORDPRESS INSTALLATION!');
console.log(`   Installable file: ${OUTPUT_ZIP}`);
console.log('   WordPress installation steps:');
console.log('   1. Open WordPress Admin > Plugins > Add New > Upload Plugin');
console.log('   2. Select dist/finlyzer.zip and click "Install Now"');
console.log('   3. Click "Activate Plugin"');
console.log('================================================================\n');
