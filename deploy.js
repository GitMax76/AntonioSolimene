/**
 * Script di Deploy FTP per Aruba Hosting (solimene.net)
 * Utilizza basic-ftp e dotenv per sincronizzare i file sul server remoto.
 */

const ftp = require('basic-ftp');
const path = require('path');
const fs = require('fs');
require('dotenv').config();

// File e cartelle da ignorare durante il deploy
const IGNORE_PATTERNS = [
  /^\.git/,
  /^node_modules/,
  /^\.env/,
  /^deploy\.js$/,
  /^package(-lock)?\.json$/,
  /^\.gitignore$/,
  /\.ogg$/i,
  /\.tmp$/i,
  /\.DS_Store$/i
];

function shouldIgnore(relativePath) {
  const normalized = relativePath.replace(/\\/g, '/');
  return IGNORE_PATTERNS.some(pattern => pattern.test(normalized));
}

function getAllFiles(dirPath, arrayOfFiles = [], rootDir = dirPath) {
  const entries = fs.readdirSync(dirPath, { withFileTypes: true });

  for (const entry of entries) {
    const fullPath = path.join(dirPath, entry.name);
    const relPath = path.relative(rootDir, fullPath);

    if (shouldIgnore(relPath)) {
      continue;
    }

    if (entry.isDirectory()) {
      getAllFiles(fullPath, arrayOfFiles, rootDir);
    } else if (entry.isFile()) {
      arrayOfFiles.push({
        fullPath,
        relPath: relPath.replace(/\\/g, '/'),
        size: fs.statSync(fullPath).size
      });
    }
  }

  return arrayOfFiles;
}

function formatBytes(bytes, decimals = 2) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

async function deploy() {
  console.log('\n==============================================');
  console.log('🚀 DEPLOY BOTTEGA ANTONIO SOLIMENE -> ARUBA');
  console.log('==============================================\n');

  // Verifica presenza file .env
  const envPath = path.join(__dirname, '.env');
  if (!fs.existsSync(envPath)) {
    console.error('❌ File .env non trovato!');
    console.error('👉 Crea un file .env partendo da .env.example con le tue credenziali FTP Aruba.');
    process.exit(1);
  }

  const host = process.env.FTP_HOST;
  const user = process.env.FTP_USER;
  const password = process.env.FTP_PASSWORD;
  const port = parseInt(process.env.FTP_PORT || '21', 10);
  const secure = process.env.FTP_SECURE === 'true' || process.env.FTP_SECURE === 'explicit';
  const remoteDir = process.env.FTP_REMOTE_DIR || '/';

  if (!host || !user || !password) {
    console.error('❌ Credenziali FTP incomplete nel file .env (FTP_HOST, FTP_USER, FTP_PASSWORD richiesti).');
    process.exit(1);
  }

  const client = new ftp.Client();
  client.ftp.verbose = false; // Imposta su true per debug dettagliato comandi FTP

  try {
    console.log(`🔌 Connessione a ${host}:${port} (utente: ${user})...`);
    await client.access({
      host,
      user,
      password,
      port,
      secure
    });
    console.log('✅ Connessione FTP stabilita con successo.\n');

    if (remoteDir && remoteDir !== '/' && remoteDir !== '.') {
      console.log(`📁 Posizionamento nella cartella remota: ${remoteDir}`);
      await client.ensureDir(remoteDir);
    }

    const filesToUpload = getAllFiles(__dirname);
    console.log(`📦 Trovati ${filesToUpload.length} file pronti per il deploy:\n`);

    let uploadedBytes = 0;
    for (let i = 0; i < filesToUpload.length; i++) {
      const file = filesToUpload[i];
      const remoteFilePath = path.posix.join(remoteDir === '/' ? '' : remoteDir, file.relPath);
      const remoteFileDir = path.posix.dirname(remoteFilePath);

      if (remoteFileDir && remoteFileDir !== '.' && remoteFileDir !== '/') {
        await client.ensureDir(remoteFileDir);
      }

      process.stdout.write(`  [${i + 1}/${filesToUpload.length}] Caricamento ${file.relPath} (${formatBytes(file.size)})... `);
      await client.uploadFrom(file.fullPath, remoteFilePath);
      uploadedBytes += file.size;
      process.stdout.write('OK ✅\n');
    }

    console.log('\n==============================================');
    console.log(`🎉 Deploy completato con successo!`);
    console.log(`📊 Totale file sincronizzati: ${filesToUpload.length} (${formatBytes(uploadedBytes)})`);
    console.log(`🌐 Visita il sito: http://solimene.net`);
    console.log('==============================================\n');

  } catch (err) {
    console.error('\n❌ Errore durante il deploy FTP:', err.message || err);
    process.exit(1);
  } finally {
    client.close();
  }
}

deploy();
