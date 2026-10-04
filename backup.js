/**
 * BACKUP AUTOMATICO CATALOGO BOTTEGA -> REPOSITORY
 * 
 * Scarica l'ultima versione di catalog.json pubblicata su solimene.net
 * e la sincronizza nella cartella locale del repository per il backup Git.
 */

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');

const TARGET_FILE = path.join(__dirname, 'catalog.json');
const PUBLIC_FILE = path.join(__dirname, 'public', 'catalog.json');

const REMOTE_URLS = [
  'https://www.solimene.net/public/catalog.json',
  'https://www.solimene.net/catalog.json',
  'http://solimene.net/catalog.json'
];

async function fetchJson(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith('https') ? https : http;
    const req = client.get(url + '?v=' + Date.now(), { timeout: 10000 }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return resolve(fetchJson(res.headers.location));
      }
      if (res.statusCode !== 200) {
        return reject(new Error(`HTTP Status ${res.statusCode}`));
      }
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          const parsed = JSON.parse(data);
          resolve(parsed);
        } catch (e) {
          reject(new Error('Risposta non è un JSON valido: ' + e.message));
        }
      });
    });
    req.on('error', reject);
    req.on('timeout', () => {
      req.destroy();
      reject(new Error('Timeout richiesta HTTP'));
    });
  });
}

async function runBackup() {
  console.log('\n==============================================');
  console.log('📥 BACKUP CATALOGO BOTTEGA SOLIMENE');
  console.log('==============================================\n');

  let catalogData = null;
  let successUrl = '';

  for (const url of REMOTE_URLS) {
    try {
      process.stdout.write(`Tentativo di download da ${url}... `);
      const data = await fetchJson(url);
      if (Array.isArray(data) && data.length > 0) {
        catalogData = data;
        successUrl = url;
        console.log('OK ✅');
        break;
      } else {
        console.log('Array vuoto ⚠️');
      }
    } catch (err) {
      console.log(`Fallito (${err.message}) ⚠️`);
    }
  }

  if (!catalogData) {
    console.error('\n❌ Impossibile scaricare il catalogo da solimene.net.');
    console.log('Verifica la connessione internet o controlla i permessi server.\n');
    process.exit(1);
  }

  const formatted = JSON.stringify(catalogData, null, 2);
  fs.writeFileSync(TARGET_FILE, formatted, 'utf8');
  console.log(`\n💾 Salvato localmente in: ${TARGET_FILE}`);

  // Se esiste la cartella public, aggiorna anche lì
  if (fs.existsSync(path.join(__dirname, 'public'))) {
    fs.writeFileSync(PUBLIC_FILE, formatted, 'utf8');
    console.log(`💾 Aggiornato anche in: ${PUBLIC_FILE}`);
  }

  const disponibili = catalogData.filter(x => x.status !== 'venduto').length;
  const venduti = catalogData.filter(x => x.status === 'venduto').length;

  console.log('\n----------------------------------------------');
  console.log(`📊 Statistiche Catalogo Scaricato:`);
  console.log(`   - Totale Opere: ${catalogData.length}`);
  console.log(`   - Disponibili:  ${disponibili}`);
  console.log(`   - Vendute:      ${venduti}`);
  console.log('----------------------------------------------');
  console.log('\n🎉 Backup completato! Ora puoi eseguire:');
  console.log('   git add catalog.json');
  console.log('   git commit -m "Backup catalogo da bottega online"');
  console.log('   git push origin main\n');
}

runBackup();
